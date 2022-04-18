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
        $command = "sleep 1 ; ip netns exec {$this->netnsName}  " . __DIR__ . "/DB1000N/db1000n  -prometheus_on=false " . static::getCmdArgsForConfig() . "  2>&1";
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

        //------------------- fetch country from db1000n output -------------------

        $countryRegexp = <<<REGEXP
             #Current country:([^\(]+)#
        REGEXP;

        if (preg_match(trim($countryRegexp), $output, $matches) === 1) {
            $currentCountry = mbTrim($matches[1]);
            if ($currentCountry) {
                $this->currentCountry = $currentCountry;
            }
        }

        //------------------- fetch statistics from db1000n output -------------------

        $responseRegExp = '#Total' . str_repeat('\s+\|\s+(\d+)', 3) . '#';
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

        //------------------- reduce db1000n output -------------------
        global $REDUCE_DB1000N_OUTPUT;

        if ($REDUCE_DB1000N_OUTPUT) {

            // Remove timestamps
            $timeStampRegexp = <<<PhpRegExp
                  #^\d{4}\/\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{1,2}:\d+\.\d{1,6}\s+#um  
                  PhpRegExp;
            $output = preg_replace(trim($timeStampRegexp), '', $output);
            $output = mbStrReplace('|', '', $output);

            // Split lines
            $linesArray = mbSplitLines($output);
            // Remove empty lines
            $linesArray = mbRemoveEmptyLinesFromArray($linesArray);

            $attackingMessages = [];
            $i = 0;
            while ($line = $linesArray[$i] ?? false) {

                if (strpos($line, ': Attacking ') !== false) {
                    // Collect "Attacking" messages
                    $count = $attackingMessages[$line] ?? 0;
                    $count++;
                    $attackingMessages[$line] = $count;
                } else {
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
        $lastNlPos = mb_strrpos($this->log, "\n");
        if ($lastNlPos !== false) {
            $ret = mb_substr($this->log, 0, $lastNlPos);
            $this->log = mb_substr($this->log, $lastNlPos + 1);
            if ($this->log) {
                echo '"' . $this->log . '"';
            }
            return $ret;
        } else {
            $this->log = '';
            return $ret;
        }
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