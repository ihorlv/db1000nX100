<?php

class ResourcesConsumption {

    private static $cpuStatOnStart,
                   $cpuStatOnFinish,
                   $ramFreeTrackArray;

    public static function resetAndStartTracking()
    {
        static::$cpuStatOnStart  = static::cpuStatRead();
        static::$cpuStatOnFinish = null;

        $memoryStat = static::memoryStatRead();
        static::$ramFreeTrackArray = [ $memoryStat['MemFree'] ];
    }

    public static function finishTracking()
    {
        if (static::hasFinished()) {
            return;
        }

        static::trackRamUsage();
        static::$cpuStatOnFinish = static::cpuStatRead();
    }

    public static function trackRamUsage()
    {
        if (static::hasFinished()) {
            _die(__METHOD__ . " can't track after finish");
        }

        $memoryStat = static::memoryStatRead();
        static::$ramFreeTrackArray[] = $memoryStat['MemFree'];
        //print_r(static::$ramFreeTrackArray);
    }

    public static function getAverageCPUUsageSinceStart() : int
    {
        if (! static::hasFinished()) {
            _die(__METHOD__ . " can't get before finish");
        }

        $differanceBetweenCpuStats = [];
        foreach (static::$cpuStatOnStart as $key => $startValue) {
            $differanceBetweenCpuStats[$key] = static::$cpuStatOnFinish[$key] - $startValue;
        }

        /*print_r(static::$cpuStatOnStart);
        echo static::cpuStatCalculateAverageCPUUsage(static::$cpuStatOnStart) . "\n\n";
        print_r(static::$cpuStatOnFinish);
        echo static::cpuStatCalculateAverageCPUUsage(static::$cpuStatOnFinish) . "\n\n";
        print_r($differanceBetweenCpuStats);
        echo static::cpuStatCalculateAverageCPUUsage($differanceBetweenCpuStats) . "\n\n";*/

        return static::cpuStatCalculateAverageCPUUsage($differanceBetweenCpuStats);
    }

    public static function getAverageRAMUsageSinceStart() : int
    {
        if (! static::hasFinished()) {
            _die(__METHOD__ . " can't get before finish");
        }

        $averageRamFreeInBytes = array_sum(static::$ramFreeTrackArray) / count(static::$ramFreeTrackArray);
        $averageRamFreeInPercents = $averageRamFreeInBytes * 100 / static::getRAMCapacity();
        return round(100 - $averageRamFreeInPercents);
    }

    public static function getPeakRAMUsageSinceStart() : int
    {
        if (! static::hasFinished()) {
            _die(__METHOD__ . " can't get before finish");
        }

        $minRamFreeInBytes    = min(static::$ramFreeTrackArray);
        $minRamFreeInPercents = $minRamFreeInBytes * 100 / static::getRAMCapacity();
        return round(100 - $minRamFreeInPercents);
    }

    //------------------------------------------------------------

    private static function hasFinished()
    {
        return (boolean) static::$cpuStatOnFinish;
    }

    private static function cpuStatRead() : array
    {
        $stat = file_get_contents('/proc/stat');
        $cpuUsageRegExp = '#cpu' . str_repeat('\s+(\d+)', 10) . '#';
        if (preg_match($cpuUsageRegExp, $stat, $matches) !== 1) {
            _die(__METHOD__ . ' failed');
        }

        //https://man7.org/linux/man-pages/man5/proc.5.html
        $i = 0;
        return [
            'user'       => (int) $matches[++$i],
            'nice'       => (int) $matches[++$i],
            'system'     => (int) $matches[++$i],
            'idle'       => (int) $matches[++$i],
            'iowait'     => (int) $matches[++$i],
            'irq'        => (int) $matches[++$i],
            'softirq'    => (int) $matches[++$i],
            'steal'      => (int) $matches[++$i],
            'guest'      => (int) $matches[++$i],
            'guest_nice' => (int) $matches[++$i]
        ];
    }

    private static function cpuStatCalculateAverageCPUUsage($cpuStat) : int
    {
        // https://rosettacode.org/wiki/Linux_CPU_utilization
        $idle = $cpuStat['idle'] / array_sum($cpuStat);
        return round((1 - $idle) * 100);
    }

    private static function memoryStatRead()
    {
        $stat = file_get_contents('/proc/meminfo');
        $memoryUsageRegExp = <<<PhpRegExp
                             #^(\w+):\s+(\d+)\s+kB$#m  
                             PhpRegExp;
        if (preg_match_all(trim($memoryUsageRegExp), $stat, $matches) < 1) {
            _die(__METHOD__ . ' failed');
        }

        $memoryUsageKb = array_combine($matches[1], $matches[2]);
        $memoryUsage = array_map(function ($value) {
                return ( (int) $value * 1024 );
            },
            $memoryUsageKb
        );

        return $memoryUsage;
    }

    //------------------------------------------------------

    function getCPUQuantity()
    {
        $regExp = <<<PhpRegExp
              #CPU\(s\):\s+(\d+)#  
              PhpRegExp;

        $r = shell_exec('lscpu');
        if (preg_match(trim($regExp), $r, $matches) === 1) {
            return (int) $matches[1];
        }
        return $r;
    }

    function getRAMCapacity()
    {
        $memoryStat = static::memoryStatRead();
        return $memoryStat['MemTotal'];
    }

}