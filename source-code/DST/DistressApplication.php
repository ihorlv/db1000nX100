<?php

// https://github.com/Yneth/distress-releases

class DistressApplication extends distressApplicationStatic
{
    private $stat = false;


    public function processLaunch()
    {
        global $DISTRESS_SCALE,
               $DISTRESS_SCALE_MAX,
               $DISTRESS_PROXY_CONNECTIONS_PERCENT,
               $DISTRESS_USE_TOR,
               $DISTRESS_USE_UDP_FLOOD;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        // ---

        if (file_exists(static::$configFilePath)) {
            $caConfig = '--config-path="' . static::$configFilePath . '"';
        } else {
            $caConfig = '';
        }

        // ---

        $proxyConnectionsPercentJoint = $this->vpnConnection->getOpenVpnConfig()->getProvider()->getSetting('distressProxyConnectionsPercent');
        if ($proxyConnectionsPercentJoint === null) {
            $proxyConnectionsPercentJoint = $DISTRESS_PROXY_CONNECTIONS_PERCENT;
        }

        $proxyConnectionsPercentJoint = intval($proxyConnectionsPercentJoint);

        if ($proxyConnectionsPercentJoint < 0  ||  $proxyConnectionsPercentJoint > 100) {
            $proxyConnectionsPercentJoint = 0;
        }

        $useMyIp = 100 - $proxyConnectionsPercentJoint;
        if ($useMyIp) {
            $caUseMyIp = "--use-my-ip=$useMyIp";
        } else {
            $caUseMyIp = '';
        }

        // ---

        if ($proxyConnectionsPercentJoint  &&  file_exists(static::$proxyPoolFilePath)) {
            $caProxyPool = '--proxies-path="' . static::$proxyPoolFilePath . '"';
        } else {
            $caProxyPool = '--disable-pool-proxies';
        }

        // ---

        $caUdpFlood = '';

        if ($DISTRESS_USE_UDP_FLOOD) {

            /*$udpPacketSize = $DISTRESS_SCALE;

            if ($DISTRESS_SCALE < 1000) {
                $udpPacketSizeMultiplier = round($DISTRESS_SCALE / 1000, 1);
                $udpPacketSize *= $udpPacketSizeMultiplier;
            }

            $udpPacketSize = fitBetweenMinMax(16, 0xffff, intRound($udpPacketSize));

            // ---

            $directConnectionsMultiplier = intRound( (100 - intval($DISTRESS_PROXY_CONNECTIONS_PERCENT)) / 10 );
            $directConnectionsMultiplier *= 2;

            $packetsPerConnection = round($DISTRESS_SCALE / 1000, 1);
            $packetsPerConnection *= $directConnectionsMultiplier;

            $packetsPerConnection = fitBetweenMinMax(1, 1000, intRound($packetsPerConnection)); */

            $udpFloodSize = $DISTRESS_SCALE;

            $scaleMultiplier = round($DISTRESS_SCALE / 1000, 1);
            $udpFloodSize *= $scaleMultiplier;

            $directConnectionsMultiplier = intRound( (100 - intval($DISTRESS_PROXY_CONNECTIONS_PERCENT)) / 10 );
            $udpFloodSize *= $directConnectionsMultiplier;

            $maxUdpPacketSize = 10240;
			
            $packetsPerConnection = intRound($udpFloodSize / $maxUdpPacketSize);
			if ($packetsPerConnection < 1) {
				$packetsPerConnection = 1;
			}
			
            $udpPacketSize = intRound($udpFloodSize / $packetsPerConnection);

            // ---

            if ($udpPacketSize > 16) {
                $caUdpFlood = "--direct-udp-mixed-flood  --udp-packet-size=$udpPacketSize --direct-udp-mixed-flood-packets-per-conn=$packetsPerConnection  --udp-flood-interval-ms=10";
            }
        }

        // ---

        $caLocalTargetsFile = static::$useLocalTargetsFile  ?  '--targets-path="' . static::$localTargetsFilePath . '"' : '';

        // ---

        if ($DISTRESS_USE_TOR   &&  $DISTRESS_SCALE > 128) {
            $torConnections = fitBetweenMinMax(1, 10, intRound($DISTRESS_SCALE / 1000));
            $caUseTor = '--use-tor=' . $torConnections;
        } else {
            $caUseTor = '';
        }

        // ---

        $command =    'setsid   ip netns exec ' . $this->vpnConnection->getNetnsName()
                 . "   nice -n 10   /sbin/runuser -p -u app-h -g app-h   --"
                 . '   ' . static::$distressCliPath . "  --concurrency=$DISTRESS_SCALE"
                 . "  --disable-auto-update  --log-interval-sec=15  --worker-threads=1  --json-logs  --source=x100"  // --user-id=0
                 . "  $caUseMyIp"
                 . "  $caUdpFlood"
                 . "  $caUseTor"
                 . "  $caProxyPool"
                 . "  $caConfig"
                 . "  $caLocalTargetsFile"
                 . "  2>&1";

        $this->log('Launching Distress on VPN' . $this->vpnConnection->getIndex());
        $this->log($command);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );

        $this->process = proc_open($command, $descriptorSpec, $this->pipes);
        usleep(50 * 1000);

        // ---

        $this->processShellPid = $this->isAlive();
        if (!$this->processShellPid) {
            $this->log('Command failed');
            $this->terminateAndKill(true);
            $this->launchFailed = true;
            return -1;
        }

        // ---

        //passthru("pstree -g -p $this->processShellPid");
        $childrenPids = [];
        getProcessPidWithChildrenPids($this->processShellPid, false, $childrenPids);
        $processFirstChildPid = $childrenPids[1] ?? false;

        if (   !$processFirstChildPid
            ||  posix_getpgid($processFirstChildPid) !== $processFirstChildPid
        ) {
            $this->log('Setsid failed');
            $this->terminateAndKill(true);
            $this->launchFailed = true;
            return -1;
        }

        $this->processChildrenPGid = $processFirstChildPid;

        // ---

        stream_set_blocking($this->pipes[1], false);
        $this->wasLaunched = true;
        return true;
    }

    protected function processJsonLine($line, $lineObj)
    {

        $level = $lineObj->level  ??  '';
        $msg   = $lineObj->msg    ??  '';

        if (
                $level === 'INFO'
            &&  strpos($msg, 'active connections=') !== false
        ) {

            $regExp = '#^active connections=([^,]+), pps=([^,]+), bps=([^,]+), requests=([^,]+), bytes=([^,]+), pending connections=(.*)$#';

            if (preg_match($regExp, $msg, $matches) > 0) {
                $this->stat = new \stdClass();
                $this->stat->activeConnections  = $matches[1];
                $this->stat->pps                = $matches[2];
                $this->stat->bps                = $matches[3];
                $this->stat->requests           = $matches[4];
                $this->stat->bytes              = $matches[5];
                $this->stat->pendingConnections = $matches[6];
            }

        }

        if (val($lineObj, 'level') === 'INFO') {
            $ret = val($lineObj, 'msg') . "\n";
        } else {
            $ret  = $this->lineObjectToString($lineObj);
            $ret .= "\n\n";
        }

        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge($returnSamePrevious = false) : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;

        $this->statisticsBadge = null;

        if (!$this->stat) {
            goto retu;
        }

        $columnsDefinition = [
            [
                'title' => ['Active ', 'connections'],
                'width' => $LOG_WIDTH - $LOG_PADDING_LEFT - 14 * 5,
                'alignRight' => true
            ],
            [
                'title' => ['PPS'],
                'width' => 14,
                'alignRight' => true
            ],
            [
                'title' => ['BPS'],
                'width' => 14,
                'alignRight' => true
            ],
            [
                'title' => ['Requests'],
                'width' => 14,
                'alignRight' => true
            ],
            [
                'title' => ['Bytes'],
                'width' => 14,
                'alignRight' => true
            ],
            [
                'title' => ['Pending', 'connections'],
                'width' => 14,
                'alignRight' => true
            ]
        ];

        $rows[] = [];
        $rows[] = [
            $this->stat->activeConnections,
            $this->stat->pps,
            $this->stat->bps,
            $this->stat->requests,
            $this->stat->bytes,
            $this->stat->pendingConnections
        ];

        $this->statisticsBadge = generateMonospaceTable($columnsDefinition, $rows);

        retu:

        return parent::getStatisticsBadge($returnSamePrevious);
    }

    // Should be called after pumpLog()
    public function getEfficiencyLevel()
    {
        $networkStats = $this->vpnConnection->calculateNetworkStats();
        if ($networkStats->session->transmitted) {
            // Let's assume that, if received traffic is 20 times larger than transmitted traffic,
            // then we have 100% response rate

            $responseRate = roundLarge($networkStats->session->received / 20 / $networkStats->session->transmitted * 100);
            if ($responseRate > 100) {
                $responseRate = 100;
            }
            return $responseRate;
        } else {
            return null;
        }
    }

    public function terminate($hasError)
    {
        if ($this->processChildrenPGid) {
            $this->log("Distress terminate PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGTERM);
        }

        $this->terminated = true;
    }

    public function kill()
    {
        if ($this->processChildrenPGid) {
            $this->log("Distress kill PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGKILL);
        }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);
    }

}

distressApplication::constructStatic();