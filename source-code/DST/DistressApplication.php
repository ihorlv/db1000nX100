<?php

// https://github.com/Yneth/distress-releases

class DistressApplication extends distressApplicationStatic
{
    private $stat = false;


    public function processLaunch()
    {
        global $DISTRESS_SCALE,
               $DISTRESS_DIRECT_CONNECTIONS_PERCENT,
               $DISTRESS_TOR_CONNECTIONS_PER_TARGET,
               $DISTRESS_USE_PROXY_POOL,
               $IT_ARMY_USER_ID;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $caUseMyIp             = '--use-my-ip='            . intval($DISTRESS_DIRECT_CONNECTIONS_PERCENT);
        $caUseTor              = '--use-tor='              . $DISTRESS_TOR_CONNECTIONS_PER_TARGET;

        $caDisablePoolProxies  = $DISTRESS_USE_PROXY_POOL  ?  '' : '--disable-pool-proxies';

        $command =    'ip netns exec ' . $this->vpnConnection->getNetnsName()
                 . "   nice -n 10   /sbin/runuser -p -u app-h -g app-h   --"
                 . '   ' . static::$distressCliPath . "  --concurrency=$DISTRESS_SCALE"
                 . "  --disable-auto-update  --log-interval-sec=15"
                 . "  $caUseMyIp"
                 . "  $caUseTor"
                 . "  $caDisablePoolProxies"
                 . "  --json-logs  2>&1";

        $this->log('Launching Distress on VPN' . $this->vpnConnection->getIndex());
        $this->log($command);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $this->process = proc_open($command, $descriptorSpec, $this->pipes);
        $this->processPGid = procChangePGid($this->process, $log);
        $this->log($log);
        if ($this->processPGid === false) {
            $this->terminateAndKill(true);
            $this->log('Command failed: ' . $command);
            $this->launchFailed = true;
            return -1;
        }

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
        if ($this->processPGid) {
            $this->log("DistressApplication terminate PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGTERM);
        }

        $this->terminated = true;
    }

    public function kill()
    {
        if ($this->processPGid) {
            $this->log("DistressApplication kill PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGKILL);
        }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);
    }

}

distressApplication::constructStatic();