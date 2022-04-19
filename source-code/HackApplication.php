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
            $efficiencyLevels = [];

    public function __construct($netnsName)
    {
        $this->netnsName = $netnsName;
    }

    public function processLaunch()
    {
        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        //$command = "ip netns exec {$this->netnsName} traceroute 94.142.139.9 ; sleep 5";
        $command = "sleep 1 ;   ip netns exec {$this->netnsName}   nice -n 10   " . __DIR__ . "/DB1000N/db1000n  -prometheus_on=false " . static::getCmdArgsForConfig() . "  2>&1";
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

    public function pumpOutLog() : string
    {
        $ret = $this->log;

        if (! $this->readChildProcessOutput) {
            goto pumpOut;
        }

        //------------------- read db1000n stdout -------------------

        $output = streamReadLines($this->pipes[1], 0.1);


        //------------------- reduce db1000n output -------------------
        global $REDUCE_DB1000N_OUTPUT;

        if ($REDUCE_DB1000N_OUTPUT) {
            // --- Split lines
            $linesArray = mbSplitLines($output);

            // --- Remove empty lines
            $linesArray = mbRemoveEmptyLinesFromArray($linesArray);

            // --- Remove timestamps RegExp
            $thisYear = date('Y');
            $timeStampRegExp = <<<PhpRegExp
                  #^$thisYear\/\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{1,2}:\d+\.\d{1,6}\s+#u  
                  PhpRegExp;

            $linesArray = array_map(
                function ($line) use ($timeStampRegExp) {
                    global $LOG_WIDTH, $LOG_PADDING_LEFT;
                    $line = preg_replace(trim($timeStampRegExp), '', $line, -1, $count);
                    if ($count === 0) {
                        $line = mbStrReplace('|', '', $line);
                        $line = mbRTrim($line);
                        $line = str_repeat(' ', $LOG_WIDTH - $LOG_PADDING_LEFT - mb_strlen($line)) . $line;
                        $line = mb_substr($line, 0 - $LOG_WIDTH + $LOG_PADDING_LEFT);
                    }
                    return $line;
                },
                $linesArray
            );

            //---------------------------

            $attackingMessages = [];
            $i = 0;
            while ($line = $linesArray[$i] ?? false) {

                if (strpos($line, ': Attacking ') !== false) {
                    // Collect "Attacking" messages
                    $count = $attackingMessages[$line] ?? 0;
                    $count++;
                    $attackingMessages[$line] = $count;
                } else {
                    // Join duplicate lines to one line
                    $sameLinesCount = 1;
                    $ni = $i + 1;
                    while ($nextLine = $linesArray[$ni] ?? false) {
                        if ($line === $nextLine) {
                            $sameLinesCount++;
                            $ni++;
                        } else {
                            break;
                        }
                    }
                    $i = $ni - 1;

                    $this->addCountToLine($line, $sameLinesCount);
                    $ret .= $line . "\n";
                }

                $i++;
            }

            // Show collected "Attacking" targets
            ksort($attackingMessages);
            foreach ($attackingMessages as $line => $count) {
                $this->addCountToLine($line, $count);
                $ret .= $line . "\n";
            }

        } else {
            $ret .= $output;
        }


        //--------------------------------
        pumpOut:

        if (! mbTrim($ret)) {
            return '';
        }

        // Preserve incomplete last line
        $lastNlPos = mb_strrpos($ret, "\n");
        if ($lastNlPos !== false) {
            $this->log = mb_substr($ret, $lastNlPos + 1);
            $ret = mb_substr($ret, 0, $lastNlPos /* +1 */);  //skip last newline

            if ($this->log) {
                echo '"' . $this->log . '"';
            }
        } else {
            $this->log = '';
        }

        $this->readUsefulData($ret);

        return $ret;
    }

    private function addCountToLine(&$line, $count)
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        if (mbTrim($line)  &&  $count > 1) {
            $messagesCountLabel = "    [$count]";
            $line = str_pad($line, $LOG_WIDTH - strlen($messagesCountLabel) - $LOG_PADDING_LEFT);
            $line .= $messagesCountLabel;
        }
    }

    private function readUsefulData($output)
    {
        //------------------- fetch country from db1000n output -------------------

        $countryRegexp = <<<REGEXP
             #Current country:([^\(]+)#u
        REGEXP;

        if (preg_match(trim($countryRegexp), $output, $matches) === 1) {
            $currentCountry = mbTrim($matches[1]);
            if ($currentCountry) {
                $this->currentCountry = $currentCountry;
            }
        }

        //------------------- fetch statistics from db1000n output -------------------

        $responseRegExp = '#Total' . str_repeat('\s+(\d+)', 3) . '#u';
        if (preg_match_all($responseRegExp, $output, $matches) > 0) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $requestsAttempted = (int) $matches[1][$i];
                $requestsSent      = (int) $matches[2][$i];
                $responsesReceived = (int) $matches[3][$i];

                //echo($requestsAttempted . ' ' . $requestsSent . ' ' . $responsesReceived);
                if ($responsesReceived === 0) {
                    $this->efficiencyLevels[] = 0;
                } else {
                    $this->efficiencyLevels[] = round($responsesReceived * 100 / $requestsAttempted, 4);
                }
            }

            //print_r($matches);
            //print_r($this->efficiencyLevels);
        }
    }

    // Should be called after getLog()
    public function getCurrentCountry()
    {
        return $this->currentCountry;
    }

    // Should be called after getLog()
    public function getEfficiencyLevel()
    {
        if (count($this->efficiencyLevels) === 0) {
            return null;
        }
        $average = array_sum($this->efficiencyLevels) / count($this->efficiencyLevels);
        return roundLarge($average);
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
        $this->log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "db1000n SIGTERM PGID -{$this->processPGid}");

        if ($this->processPGid) {
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


    public static function initStatic()
    {
        global $TEMP_DIR;
        static::$configUrl = 'https://raw.githubusercontent.com/db1000n-coordinators/LoadTestConfig/main/config.v0.7.json';
        static::$localConfigPath = $TEMP_DIR . '/db1000n-config.json';
        static::$useLocalConfig = false;
    }

    private static function loadConfig()
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, static::$configUrl);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true );
        $content = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode === 200  &&  $content) {
            echo "Config file for db1000n downloaded from " . static::$configUrl . "\n";
            file_put_contents_secure(static::$localConfigPath, $content);
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

HackApplication::initStatic();