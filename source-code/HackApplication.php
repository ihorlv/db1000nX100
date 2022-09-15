<?php

abstract class HackApplication
{
    protected $process,
              $processPGid,
              $pipes,
              $log = '',
              $instantLog = false,
              $vpnConnection,
              $readChildProcessOutput = false,
              $childProcessStdoutBrokenLineCount,
              $childProcessStdoutBuffer = '',
              $exitCode = -1,
              $terminateMessage = false,
              $terminated = false;


    public function __construct($vpnConnection)
    {
        $this->vpnConnection = $vpnConnection;
    }

    abstract public function processLaunch();

    // Should be called after pumpLog()
    abstract public function getStatisticsBadge() : ?string;

    // Should be called after pumpLog()
    abstract public function getEfficiencyLevel();

    // Should be called after pumpLog()
    abstract public function getCurrentCountry();

    abstract public function terminate($hasError);

    abstract public function kill();

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
        $processStatus = proc_get_status($this->process);  // Only first call of this function return real value, next calls return -1.

        if ($processStatus  &&  $processStatus['exitcode'] !== -1) {
            $this->exitCode = $processStatus['exitcode'];
        }
        return $this->exitCode;
    }

    public function requireTerminate($message)
    {
        $this->terminateMessage = $message;
    }

    public function getTerminateMessage() : string
    {
        return $this->terminateMessage;
    }

    public function isTerminateRequired() : bool
    {
        return $this->terminateMessage !== false;
    }

    public function isTerminated() : bool
    {
        return $this->terminated;
    }

    public function terminateAndKill($hasError = false)
    {
        global $WAIT_SECONDS_BEFORE_PROCESS_KILL;
        $this->terminate($hasError);
        sayAndWait($WAIT_SECONDS_BEFORE_PROCESS_KILL);
        $this->kill();
    }

    protected function log($message, $noLineEnd = false)
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

    protected function lineObjectToString($lineObj, $color = false)
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

                if ( substr($item, 0, 5) === '    [') {
                    $item = mbTrim($item);
                }

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

    public function pumpLog($flushBuffers = false) : string
    {
        $ret = $this->log;
        $this->log = '';

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read stdout -------------------

        $this->childProcessStdoutBuffer .= streamReadLines($this->pipes[1], 0);
        if ($flushBuffers) {
            $ret = $this->childProcessStdoutBuffer;
        } else {
            // --- Split lines
            $lines = mbSplitLines($this->childProcessStdoutBuffer);
            // --- Remove terminal markup
            $lines = array_map('\Term::removeMarkup', $lines);
            // --- Remove empty lines
            $lines = mbRemoveEmptyLinesFromArray($lines);

            foreach ($lines as $lineIndex => $line) {
                $lineObj = json_decode($line);

                if (is_object($lineObj)) {
                    unset($lines[$lineIndex]);
                    $this->childProcessStdoutBrokenLineCount = 0;
                    $ret .= $this->processJsonLine($line, $lineObj);
                } else if (
                    !$this->childProcessStdoutBrokenLineCount
                    &&  mb_substr($line, 0, 1) !== '{'
                ) {
                    unset($lines[$lineIndex]);
                    $ret .= $line;
                } else {
                    $this->childProcessStdoutBrokenLineCount++;
                    if ($this->childProcessStdoutBrokenLineCount > 3) {
                        $this->childProcessStdoutBrokenLineCount = 0;
                        $ret .= $line . "\n";
                        unset($lines[$lineIndex]);
                    }
                    break;
                }
            }
            $this->childProcessStdoutBuffer = implode("\n", $lines);
        }

        retu:
        $ret = mbRTrim($ret);

        return $ret;
    }

    // ----------------------  Static part of the class ----------------------

    public static array $registeredClasses = [];

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources'], 1000);
    }

    public static function actionAfterCalculateResources()
    {
        static::$registeredClasses = Actions::doFilter('RegisterHackApplicationClasses', []);
    }

    public static function countPossibleInstances() : int
    {
        $ret = 0;
        foreach (static::$registeredClasses as $hackApplicationClass) {
            $ret += $hackApplicationClass::countPossibleInstances();
        }
        return $ret;
    }

    public static function getNewInstance($vpnConnection)
    {
        foreach (static::$registeredClasses as $hackApplicationClass) {
            $instance = $hackApplicationClass::getNewInstance($vpnConnection);
            if ($instance) {
                return $instance;
            }
        }
    }

    private static function isInstanceOfCallingClass($hackApplication) : bool
    {
        if (
                static::class === self::class
            ||  static::class === get_class($hackApplication)
            ||  static::class === get_parent_class($hackApplication)
        ) {
            return true;
        }

        return false;
    }

    public static function getInstances() : array
    {
        global $VPN_CONNECTIONS;
        $ret = [];
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                    is_object($hackApplication)
                &&  static::isInstanceOfCallingClass($hackApplication)
            ) {
                $ret[$connectionIndex] = $hackApplication;
            }
        }
        return $ret;
    }

    public static function getRunningInstances() : array
    {
        $hackApplications = static::getInstances();

        $ret = [];
        foreach ($hackApplications as $hackApplication) {
            if (
                   !$hackApplication->isTerminated()
                &&  $hackApplication->isAlive()
            ) {
                $ret[] = $hackApplication;
            }
        }
        return $ret;
    }

    public static function terminateInstances()
    {
        $hackApplications = static::getInstances();
        foreach ($hackApplications as $hackApplication) {
            if (
                   is_object($hackApplication)
                && static::isInstanceOfCallingClass($hackApplication)
                && !$hackApplication->isTerminated()
            ) {
                $hackApplication->setReadChildProcessOutput(false);
                $hackApplication->clearLog();
                $hackApplication->terminate(false);
                MainLog::log('VPN' . $hackApplication->vpnConnection->getIndex() . ': ' . $hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
            }
        }
    }

    public static function killInstances()
    {
        $hackApplications = static::getInstances();
        foreach ($hackApplications as $hackApplication) {
            if (
                    is_object($hackApplication)
                &&  static::isInstanceOfCallingClass($hackApplication)
                &&  $hackApplication->isTerminated()
            ) {
                $hackApplication->clearLog();
                $hackApplication->kill();
                MainLog::log('VPN' . $hackApplication->vpnConnection->getIndex() . ': ' . $hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
            }
        }
     }

    public static function sortInstancesArrayByExecutionTime($instancesArray, $asc = true)
    {
        usort(
            $instancesArray,
            function ($l, $r) use ($asc) {
                $lNetworkStats = $l->vpnConnection->calculateNetworkStats();
                $rNetworkStats = $r->vpnConnection->calculateNetworkStats();

                $lDuration = $lNetworkStats->total->duration;
                $rDuration = $rNetworkStats->total->duration;

                return $asc  ? $lDuration - $rDuration : $rDuration - $lDuration;
            }
        );
        return $instancesArray;
    }
}


HackApplication::constructStatic();
