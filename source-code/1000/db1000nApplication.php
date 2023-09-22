<?php

class db1000nApplication extends db1000nApplicationStatic
{
    private $stat = false;


    public function processLaunch()
    {
        global $DB1000N_SCALE,
               $DB1000N_USE_PROXY_POOL,
               $DB1000N_PROXY_INSTANCES_PERCENT,
               $DB1000N_PROXY_POOL,
               $DB1000N_DEFAULT_PROXY_PROTOCOL,
               $PARALLEL_VPN_CONNECTIONS_QUANTITY;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $caTargetsConfig = static::$useLocalTargetsFile  ?  '  -c "' . static::$localTargetsFilePath . '"' : '';

        $caProxyPool = '';
        if ($DB1000N_USE_PROXY_POOL  &&  $DB1000N_PROXY_POOL  &&  intval($DB1000N_PROXY_INSTANCES_PERCENT)) {
            $proxyInstancesCount = ceil(intval($DB1000N_PROXY_INSTANCES_PERCENT) / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY);

            if (count(db1000nApplication::getInstances())  <=  $proxyInstancesCount) {
                $caProxyPool = "--proxylist=\"$DB1000N_PROXY_POOL\"  --default-proxy-proto=\"$DB1000N_DEFAULT_PROXY_PROTOCOL\"";
            }
        }

        $command = "export GOMAXPROCS=1 ;   export SCALE_FACTOR={$DB1000N_SCALE} ;"
                 . '   setsid   ip netns exec ' . $this->vpnConnection->getNetnsName()
                 . "   nice -n 10"
                 . "   /sbin/runuser -p -u app-h -g app-h   -- "
                 . '   ' . static::$db1000nCliPath . "  --prometheus_on=false  --scale={$DB1000N_SCALE}  --user-id=0"
                 . "  $caTargetsConfig"
                 . "  $caProxyPool"
                 . "  --periodic-gc=true  --log-format=json   2>&1";

        $this->log('Launching db1000n on VPN' . $this->vpnConnection->getIndex());
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
        $ret = '';

        if (
                isset($lineObj->level)
            &&  $lineObj->level === 'info'
            &&  $lineObj->msg   === 'stats'
        ) {
            if (isset($lineObj->targets)) {
                $this->stat = $lineObj;
            }
        }
        //-----------------------------------------------------
        else if (
               isset($lineObj->level)
            && $lineObj->level === 'info'
            && in_array($lineObj->msg, [
                'running db1000n',
                'Attacking',
                'single http request',
                'loading config',
                'the config has not changed. Keep calm and carry on!',
                'new config received, applying',
                'checking IP address,',
                'job instances (re)started',
                'you might need to enable VPN.',
                'decrypted config',
                'location info'
            ])
        ) {
            // Do nothing
        }
        //-----------------------------------------------------
        else if (
                isset($lineObj->level)
            &&  $lineObj->level === 'warn'
            &&  in_array($lineObj->msg, [
                'error fetching location info',
                'Failed to check the country info'
            ])
        ) {
            // Do nothing
        }
        //-----------------------------------------------------
        else {
            $color = Term::clear;
            if (
                   isset($lineObj->level)
                && $lineObj->level === 'info'
            ) {
                $color = Term::gray;
            }
            $ret .= $this->lineObjectToString($lineObj, $color) . "\n\n";
        }

        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge($returnSamePrevious = false) : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;

        $this->statisticsBadge = null;

        if (!$this->stat  ||  !$this->stat->targets) {
            goto retu;
        }

        if (is_object($this->stat->targets)) {
            $targets = get_object_vars($this->stat->targets);
            ksort($targets);
            $this->stat->targets = $targets;
        }

        if (!count($this->stat->targets)) {
            goto retu;
        }

        $columnsDefinition = [
            [
                'title' => ['Target'],
                'width' => $LOG_WIDTH - $LOG_PADDING_LEFT - 11 * 4,
                'trim'  => 4
            ],
            [
                'title' => ['Requests', 'attempted'],
                'width' => 11,
                'alignRight' => true
            ],
            [
                'title' => ['Requests' , 'sent'],
                'width' => 11,
                'alignRight' => true
            ],
            [
                'title' => ['Responses', 'received'],
                'width' => 11,
                'alignRight' => true
            ],
            [
                'title' => ['MiB', 'sent'],
                'width' => 11,
                'alignRight' => true
            ]
        ];
        $rows[] = [];

        $this->stat->db1000nx100 = new stdClass();
        $this->stat->db1000nx100->totalHttpRequests = 0;
        $this->stat->db1000nx100->totalHttpResponses = 0;
        foreach ($this->stat->targets as $targetName => $targetStat) {
            $mibSent = roundLarge($targetStat->bytes_sent / 1024 / 1024);
            $row = [
                $targetName,
                $targetStat->requests_attempted,
                $targetStat->requests_sent,
                $targetStat->responses_received,
                $mibSent
            ];
            $rows[] = $row;

            //$pattern = '#^https?:\/\/#';
            //if (preg_match($pattern, $targetName, $matches)) {
                $this->stat->db1000nx100->totalHttpRequests  += $targetStat->requests_attempted;
                $this->stat->db1000nx100->totalHttpResponses += $targetStat->responses_received;                
            //}
        }

        //------- Total row
        $rows[] = [];  // new line
        $totalMiBSent = roundLarge($this->stat->total->bytes_sent / 1024 / 1024);
        $row = [
            'Total',
            $this->stat->total->requests_attempted,
            $this->stat->total->requests_sent,
            $this->stat->total->responses_received,
            $totalMiBSent
        ];
        $rows[] = $row;

        $this->statisticsBadge = generateMonospaceTable($columnsDefinition, $rows);

        retu:

        return parent::getStatisticsBadge($returnSamePrevious);
    }

    // Should be called after pumpLog()
    /*public function getEfficiencyLevel()
    {

        if (!isset($this->stat->db1000nx100->totalHttpRequests)) {
            return null;
        }

        $requests = $this->stat->db1000nx100->totalHttpRequests;
        $responses = $this->stat->db1000nx100->totalHttpResponses;

        if (!$requests) {
            return null;
        }

        $averageResponseRate = $responses * 100 / $requests;
        return roundLarge($averageResponseRate);
    }*/

    // Should be called after pumpLog()
    public function getEfficiencyLevel()
    {
        $responseRateByRequests = 0;
        if (
                isset($this->stat->db1000nx100->totalHttpRequests)
            &&  $this->stat->db1000nx100->totalHttpRequests
        ) {
            $responseRateByRequests = roundLarge($this->stat->db1000nx100->totalHttpResponses * 100 / $this->stat->db1000nx100->totalHttpRequests);
        }

        // ---

        $responseRateByTraffic = 0;
        $networkStats = $this->vpnConnection->calculateNetworkStats();
        if ($networkStats->session->transmitted) {
            // Let's assume that, if received traffic is 20 times large then transmitted traffic,
            // then we have 100% response rate

            $responseRateByTraffic = roundLarge($networkStats->session->received / 20 / $networkStats->session->transmitted * 100);
            if ($responseRateByTraffic > 100) {
                $responseRateByTraffic = 100;
            }

        }

        // ---

        return max($responseRateByRequests, $responseRateByTraffic);
    }

    public function terminate($hasError)
    {
        if ($this->processChildrenPGid) {
            $this->log("db1000n terminate PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGTERM);
        }

        $this->terminated = true;
    }

    public function kill()
    {
        if ($this->processChildrenPGid) {
            $this->log("db1000n kill PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGKILL);
        }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);
    }

}

db1000nApplication::constructStatic();