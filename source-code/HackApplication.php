<?php

abstract class HackApplication
{
    protected $log = '',
              $instantLog = false,
              $vpnConnection,
              $readChildProcessOutput = false;

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

    abstract public function isAlive();

    abstract public function getExitCode();

    abstract public function terminate($hasError);

    abstract public function kill();

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

}

