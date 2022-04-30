<?php

class Efficiency
{
    private static $counterArray = [];


    public static function newIteration()
    {
        static::$counterArray = [];
    }

    public static function addValue($connectionIndex, $value)
    {
        if ($value) {
            static::$counterArray[$connectionIndex] = $value;
        } else if (isset(static::$counterArray[$connectionIndex])) {
            unset(static::$counterArray[$connectionIndex]);
        }
    }

    public static function getMessage()
    {
        global $VPN_CONNECTIONS;
        foreach (array_keys(static::$counterArray) as $connectionIndex) {
            if (! isset($VPN_CONNECTIONS[$connectionIndex])) {
                unset(static::$counterArray[$connectionIndex]);
            }
        }

        $totalRate = roundLarge(array_sum(static::$counterArray));
        $viaCount  = count(static::$counterArray);

        if ($totalRate) {
            return "Summary response rate $totalRate% ⃰ via $viaCount VPN connection(s)  (* in compare to single VPN connection with 100% response rate)";
        }
    }

}