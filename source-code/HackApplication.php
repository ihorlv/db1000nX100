<?php

abstract class HackApplication
{
    protected $log = '',
              $instantLog = false,
              $netnsName,
              $readChildProcessOutput = false;

    public function __construct($netnsName)
    {
        $this->netnsName = $netnsName;
    }

    abstract public function processLaunch();

    abstract public function pumpLog() : string;

    // Should be called after pumpLog()
    abstract public function getStatisticsBadge() : ?string;

    // Should be called after pumpLog()
    abstract public function getEfficiencyLevel();

    // Should be called after pumpLog()
    abstract public function getCurrentCountry();

    abstract public function isAlive();

    abstract public function getExitCode();

    abstract public function terminate($hasError = false);

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

}

