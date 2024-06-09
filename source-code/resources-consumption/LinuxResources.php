<?php

class LinuxResources
{

    public static function readSystemMemoryStats()
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
        $memoryUsage['pageSize'] = (int) _shell_exec('getconf PAGESIZE');
        return $memoryUsage;
    }

    public static function getSystemRamCapacity()
    {
        static $ret;
        if ($ret) {
            return $ret;
        }

        $memoryStat = static::readSystemMemoryStats();
        $ret = $memoryStat['MemTotal'];
        return $ret;
    }

    public static function getSystemSwapCapacity()
    {
        static $ret;
        if ($ret) {
            return $ret;
        }

        $memoryStat = static::readSystemMemoryStats();
        $ret = $memoryStat['SwapTotal'];
        return $ret;
    }

    public static function getSystemTmpCapacity()
    {
        static $ret;
        if ($ret) {
            return $ret;
        }

        $stdout = _shell_exec('df --block-size=1 --output=size /tmp');
        $stdoutArr = mbSplitLines($stdout);
        $ret = $stdoutArr[1] ?? -1;
        $ret = (int) $ret;
        return $ret;
    }

    public static function calculateSystemRamUsagePercentage($memoryStat)
    {
        $ramUsed =  $memoryStat['MemTotal'] - $memoryStat['MemAvailable'];
        return intRound( $ramUsed / $memoryStat['MemTotal'] * 100 );
    }

    public static function calculateSystemSwapUsagePercentage($memoryStat)
    {
        $swapUsed = $memoryStat['SwapTotal'] - $memoryStat['SwapFree'];
        if ($memoryStat['SwapTotal']) {
            return intRound( $swapUsed / $memoryStat['SwapTotal'] * 100 );
        } else {
            return -1;
        }
    }

    public static function calculateSystemTmpUsagePercentage()
    {
        $stdout = _shell_exec('df --output=pcent /tmp');
        $stdoutArr = mbSplitLines($stdout);
        $ret = $stdoutArr[1] ?? -1;
        return (int) $ret;
    }

    //------------------------------------------------------------------------------------------------------------

    public static function getSystemCpuQuantity()
    {
        static $ret;
        if ($ret) {
            return $ret;
        }

        $regExp = <<<PhpRegExp
              #CPU\(s\):\s+(\d+)#  
              PhpRegExp;

        $r = shell_exec('lscpu');
        if (preg_match(trim($regExp), $r, $matches) === 1) {
            $ret = (int) $matches[1];
            return $ret;
        }

        return false;
    }

    public static function readSystemCpuStats() : array
    {
        $stat = file_get_contents('/proc/stat');
        $statLines = mbSplitLines($stat);

        // ---

        $cpuLine = '';
        foreach ($statLines as $statLine) {
            if (substr($statLine, 0, 4) === 'cpu ') {
                $cpuLine = $statLine;
                break;
            }
        }

        if (!$cpuLine) {
            echo __METHOD__ . ' failed. "cpu " line not found' . "\n";
        }

        // ---

        $cpuLineParts = explode(' ', $cpuLine);
        $cpuLineParts = mbRemoveEmptyLinesFromArray($cpuLineParts);
        //print_r($cpuLineParts);

        // https://man7.org/linux/man-pages/man5/proc.5.html
        $i = 0;
        $retAsStr = [
            'user'       => $cpuLineParts[++$i],
            'nice'       => $cpuLineParts[++$i],
            'system'     => $cpuLineParts[++$i],
            'idle'       => $cpuLineParts[++$i],
            'iowait'     => $cpuLineParts[++$i],
            'irq'        => $cpuLineParts[++$i],
            'softirq'    => $cpuLineParts[++$i],
            'steal'      => $cpuLineParts[++$i]  ??  0,
            'guest'      => $cpuLineParts[++$i]  ??  0,
            'guest_nice' => $cpuLineParts[++$i]  ??  0
        ];

        $ret = [];
        foreach ($retAsStr as $key => $value) {
            $ret[$key] = intval($value);
        }

        return $ret;
    }

    public static function calculateSystemCpuUsagePercentage($cpuStatsBegin, $cpuStatsEnd)
    {
        $cpuStatsDiff = [];
        foreach (array_keys($cpuStatsBegin) as $key) {
            $cpuStatsDiff[$key] = $cpuStatsEnd[$key] - $cpuStatsBegin[$key];
        }

        // https://rosettacode.org/wiki/Linux_CPU_utilization
        $idle = $cpuStatsDiff['idle'] / array_sum($cpuStatsDiff);
        $busyPercents = (1 - $idle) * 100;
        if ($busyPercents >= 99) {
            return round($busyPercents, 1);
        } else {
            return intRound($busyPercents);
        }
    }

    //------------------------------------------------------------------------------------------------------------

    public static function readProcStat($pid)
    {
        if (posix_getpgid($pid) === false) {
            return false;
        }

        $statsFile = "/proc/$pid/stat";
        $stats = '';

        try {
            if (file_exists($statsFile)) {
                $stats = file_get_contents($statsFile);
            }
        } catch (\Exception $e) {}

        if (!$stats) {
            echo __METHOD__ . " failed to read file \"$statsFile\"\n";
            return false;
        }

        //https://man7.org/linux/man-pages/man5/proc.5.html
        $statKeys = [
            'pid',
            'comm',
            'state',
            'ppid',
            'pgrp',
            'session',
            'tty_nr',
            'tpgid',
            'flags',
            'minflt',
            'cminflt',
            'majflt',
            'cmajflt',
            'utime',
            'stime',
            'cutime',
            'cstime',
            'priority',
            'nice',
            'num_threads',
            'itrealvalue',
            'starttime',
            'vsize',
            'rss',
            'rsslim',
            'startcode',
            'endcode',
            'startstack',
            'kstkesp',
            'kstkeip',
            'signal',
            'blocked',
            'sigignore',
            'sigcatch',
            'wchan',
            'nswap',
            'cnswap',
            'exit_signal',
            'processor',
            'rt_priority',
            'policy',
            'delayacct_blkio_ticks',
            'guest_time',
            'cguest_time',
            'start_data',
            'end_data',
            'start_brk',
            'arg_start',
            'arg_end',
            'env_start',
            'env_end',
            'exit_code'
        ];

        $digitsField = '(-?\d+)';
        $commField  = '(.+?)';
        $stateField = '(\w+)';
        $space      = '\s';

        $statsRegExp = '#^'
                . $digitsField . $space
                . $commField   . $space
                . $stateField  . $space
                . str_repeat($digitsField . $space, count($statKeys) - 4)
                . $digitsField
                . '#';

        if (preg_match_all($statsRegExp, $stats, $statsValues) < 1) {
            echo __METHOD__ . " failed to parse file \"$statsFile\"\n";
            return false;
        }

        unset($statsValues[0]);
        $statsValues = array_column($statsValues, 0);

        $ret = array_combine($statKeys, $statsValues);
        //print_r([$pid, $ret]);
        return $ret;
    }

    public static function readProcSmapsRollup($pid)
    {
        if (posix_getpgid($pid) === false) {
            return false;
        }

        $stats = '';
        $smapsRollupPath = "/proc/$pid/smaps_rollup";

        try {
            if (file_exists($smapsRollupPath)) {
                $stats = file_get_contents($smapsRollupPath);
            }
        } catch (\Exception $e) {}

        if (!$stats) {
            echo __METHOD__ . " failed to read file \"/proc/$pid/smaps_rollup\"\n";
            return false;
        }

        // ---

        $smapsRollupRegExp = <<<PhpRegExp
                             #^(\w+):\s+(\d+)\s+kB$#m  
                             PhpRegExp;
        if (preg_match_all(trim($smapsRollupRegExp), $stats, $matches) < 1) {
            echo __METHOD__ . " failed to parse file \"/proc/$pid/smaps_rollup\"\n";
            return false;
        }

        $memoryUsageKb = array_combine($matches[1], $matches[2]);
        $memoryUsage = array_map(
            function ($value) {
                return ( (int) $value * 1024 );
            },
            $memoryUsageKb
        );
        return $memoryUsage;
    }

    public static function getAllProcessesStats($pidsList)
    {
        $ret = [];
        $ret['processes'] = [];

        foreach ($pidsList as $pid) {

            $procStat = static::readProcStat($pid);
            $smapsRollup = static::readProcSmapsRollup($pid);

            // ---

            $command = '';
            $cmdlineFile = "/proc/$pid/cmdline";
            try {
                if (file_exists($cmdlineFile)) {
                    $command = file_get_contents($cmdlineFile);
                }
            } catch (\Exception $e) { }

            if (!$command) {
                echo __METHOD__ . " failed to read file \"$cmdlineFile\"\n";
            }

            // ---

            if ($procStat  &&  $smapsRollup  &&  $command) {
                $ret['processes'][$pid]['stat'] = $procStat;
                $ret['processes'][$pid]['smaps_rollup'] = $smapsRollup;
                $ret['processes'][$pid]['command'] = $command;
            }
        }

        $ret['ticksSinceReboot'] = posix_times()['ticks'];      // getconf CLK_TCK
        return $ret;
    }

    //------------------------------------------------------------------------------------------------------------

    public static function calculateProcessesMemoryUsagePercentage($processesStats, $systemMemoryStat)
    {
        /*if (!isset($processesStats['processes'])) {
            return -1;
        }*/

        /*
         * PSS (proportional share size). Private pages are summed up as is,
         * and each shared mapping's size is divided by the number of processes that share it.
         * So if a process had 100k private pages, 500k pages shared with one other process,
         * and 500k shared with four other processes, the PSS would be:
         * 100k + (500k / 2) + (500k / 5) = 450k
         */

        $processesSumPss = -1;

        foreach ($processesStats['processes'] as $pid => $data) {
            $processesSumPss += intval(val($data, 'smaps_rollup', 'Pss'));
        }

        if ($processesSumPss === -1) {
            return -1;  // No smaps_rollup found
        } else {
            $processesSumPss++;
        }

        $processesMem = intRound($processesSumPss * 100 / $systemMemoryStat['MemTotal']);
        return $processesMem;
    }    

    public static function calculateProcessesCpuUsagePercentage($statsOnStart, $statsOnEnd)
    {
        /*if (
                !isset($statsOnStart['processes'])
            ||  !isset($statsOnEnd['processes'])
        ) {
            return -1;
        }*/

        //https://www.baeldung.com/linux/total-process-cpu-usage
        $durationTicks = $statsOnEnd['ticksSinceReboot'] - $statsOnStart['ticksSinceReboot'];
        $cpuTimeSum = 0;

        foreach (array_keys($statsOnEnd['processes']) as $endPid)
        {
            $cpuTimeOnEnd = $statsOnEnd['processes'][$endPid]['stat']['utime']
                          + $statsOnEnd['processes'][$endPid]['stat']['stime'];

            if (!isset($statsOnStart['processes'][$endPid])) {
                // Process was created after $statsOnStart were collected
                $cpuTimeSum += $cpuTimeOnEnd;
            } else {
                $cpuTimeOnStart = $statsOnStart['processes'][$endPid]['stat']['utime']
                                + $statsOnStart['processes'][$endPid]['stat']['stime'];

                $cpuTimeSum += $cpuTimeOnEnd - $cpuTimeOnStart;
            }
        }

        $coresUsed = $cpuTimeSum / $durationTicks;
        return intRound($coresUsed * 100 / static::getSystemCpuQuantity());
    }
    
}
