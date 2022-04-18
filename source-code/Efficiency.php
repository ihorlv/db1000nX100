<?php

class Efficiency
{
    private static $counterArray = [];


    public static function reset()
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
            return "Total response rate $totalRate% ⃰ via $viaCount VPN connection(s)  (* in compare to single VPN connection with 100% response rate)";
        }
    }

}

/*class Efficiency {
    private static $counterArray = [],
                   $efficiency = null,
                   $efficentConnectionsCount = 0,
                   $allConnectionsCount = 0;

    public static function clear()
    {
        static::$counterArray = [];
        static::$efficiency = null;
        static::$efficentConnectionsCount = 0;
        static::$allConnectionsCount = 0;
    }

    public static function addValue($connectionIndex, $value)
    {
        //echo static::$counter . "add $value\n";
        if ($value) {
            static::$counterArray[$connectionIndex] = $value;
        }
    }

    public static function newIteration()
    {
        //echo 'new iteration ef' . static::$efficiency . ' c'. static::$counter . "\n";
        global $VPN_CONNECTIONS;
        if (count(static::$counterArray)) {
            static::$efficiency = round(array_sum(static::$counterArray));
            static::$efficentConnectionsCount = count(static::$counterArray);
            static::$allConnectionsCount = count($VPN_CONNECTIONS);
        }

        static::$counterArray = [];
    }

    public static function getMessage()
    {
        if (static::$efficentConnectionsCount === static::$allConnectionsCount) {
            $viaCount = static::$efficentConnectionsCount;
        } else {
            $viaCount = static::$efficentConnectionsCount . ' of ' . static::$allConnectionsCount;
        }

        if (static::$efficiency) {
            return "Total response rate " . static::$efficiency . "% ⃰ via $viaCount VPN connection(s)  (* in compare to single VPN connection with 100% response rate)";
        }
    }

}