<?php

abstract class HackApplication
{
    public $requireTerminate = false,
           $terminateMessage = '',
           $terminated = false;

    protected $process,
              $processPGid,
              $pipes,
              $log = '',
              $instantLog = false,
              $vpnConnection,
              $readChildProcessOutput = false,
              $exitCode = -1;


    public function __construct($vpnConnection)
    {
        $this->vpnConnection = $vpnConnection;
    }

    abstract public function processLaunch();

    abstract public function pumpLog($flushBuffers = false) : string;

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
                //$item = mbTrim($item);
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

    // ----------------------  Static part of the class ----------------------

    public static function getApplication($vpnConnection)
    {
        $application = PuppeteerApplication::getNewObject($vpnConnection);
        if ($application) {
            return $application;
        } else {
            return new db1000nApplication($vpnConnection);
        }
    }

    public static function getRunningInstances() : array
    {
        global $VPN_CONNECTIONS;
        $ret = [];
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                    is_object($hackApplication)
                &&  get_class($hackApplication) === static::class
                &&  $hackApplication->isAlive()
            ) {
                $ret[$connectionIndex] = $hackApplication;
            }
        }
        return $ret;
    }

    public static function actionTerminateInstances()
    {
        global $VPN_CONNECTIONS;

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                is_object($hackApplication)
                && get_class($hackApplication) === static::class
            ) {
                $hackApplication->setReadChildProcessOutput(false);
                $hackApplication->clearLog();
                $hackApplication->terminate(false);
                MainLog::log('VPN' . $connectionIndex . ': ' . $hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
            }
        }
    }

    public static function actionKillInstances()
    {
        global $VPN_CONNECTIONS;

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                is_object($hackApplication)
                && get_class($hackApplication) === static::class
            ) {
                $hackApplication->clearLog();
                $hackApplication->kill();
                MainLog::log('VPN' . $connectionIndex . ': ' . $hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
            }
        }
    }

}

