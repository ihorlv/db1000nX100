<?php

// https://github.com/Yneth/distress-releases

class DistressApplication extends distressApplicationStatic
{
    private $stat = false;


    public function processLaunch()
    {
        global $DISTRESS_SCALE,
               $DISTRESS_SCALE_MAX,
               $DISTRESS_DIRECT_CONNECTIONS_PERCENT,
               $DISTRESS_USE_TOR,
               $DISTRESS_USE_PROXY_POOL,
               $DISTRESS_USE_DIRECT_UDP_FLOOD;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        if ($DISTRESS_USE_PROXY_POOL  &&  file_exists(static::$proxyPoolFilePath)) {
            $caProxyPool = '--proxies-path="' . static::$proxyPoolFilePath . '"';
        } else {
            $caProxyPool = '--disable-pool-proxies';
        }

        if (file_exists(static::$configFilePath)) {
            $caConfig = '--config-path="' . static::$configFilePath . '"';
        } else {
            $caConfig = '';
        }

        // ---

        $directConnectionsPercentJoint = $this->vpnConnection->getOpenVpnConfig()->getProvider()->getSetting('distressDirectConnectionsPercent');
        if ($directConnectionsPercentJoint === null) {
            $directConnectionsPercentJoint = $DISTRESS_DIRECT_CONNECTIONS_PERCENT;
        }

        $directConnectionsPercentJoint = intval($directConnectionsPercentJoint);

        if ($directConnectionsPercentJoint < 0  ||  $directConnectionsPercentJoint > 100) {
            $directConnectionsPercentJoint = 0;
        }

        // ---

        $caUseMyIp  = '--use-my-ip=' . $directConnectionsPercentJoint;

        if ($DISTRESS_USE_DIRECT_UDP_FLOOD  &&  $DISTRESS_SCALE > 128) {
            $caUdpFlood = "--direct-udp-failover  --udp-packet-size=" . fitBetweenMinMax(32, 0xffff, intRound($DISTRESS_SCALE * 3));
        } else {
            $caUdpFlood = '';
        }

        // ---

        $caLocalTargetsFile = static::$useLocalTargetsFile  ?  '--targets-path="' . static::$localTargetsFilePath . '"' : '';

        if ($DISTRESS_USE_TOR   &&  $DISTRESS_SCALE > 64) {
            $torConnections = fitBetweenMinMax(1, 10, intRound($DISTRESS_SCALE / 1000));
            $caUseTor = '--use-tor=' . $torConnections;
        } else {
            $caUseTor = '';
        }

        $command =    'setsid   ip netns exec ' . $this->vpnConnection->getNetnsName()
                 . "   nice -n 10   /sbin/runuser -p -u app-h -g app-h   --"
                 . '   ' . static::$distressCliPath . "  --concurrency=$DISTRESS_SCALE"
                 . "  --disable-auto-update  --log-interval-sec=15  --worker-threads=1  --json-logs  --user-id=0"
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

        $ret  = $this->lineObjectToString($lineObj);
        $ret .= "\n\n";

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
        if ($networkStats->session->received) {
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