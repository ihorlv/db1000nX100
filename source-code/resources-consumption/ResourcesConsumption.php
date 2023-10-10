<?php

class ResourcesConsumption extends LinuxResources
{
    const trackCliPhp = 'track.cli.php';
    const trackTimeInterval = 10;

    public static array $pastSessionUsageValues = [];

    private static
        $trackCliPhpProcess,
        $trackCliPhpProcessPGid,
        $trackCliPhpPipes,
        $trackCliData;


    private static int $cpuOverUsageSessionsCount = 0;

    public static function constructStatic()
    {
        static::$trackCliData = [];
        Actions::addAction('BeforeInitSession',      [static::class, 'actionBeforeInitSession']);
        Actions::addAction('BeforeTerminateSession', [static::class, 'actionBeforeTerminateSession']);
        Actions::addAction('BeforeMainOutputLoop',   [static::class, 'resetAndStartTracking']);
    }

    public static function actionBeforeInitSession()
    {
        TimeTracking::startTaskTimeTracking('session');
    }

    public static function actionBeforeTerminateSession()
    {
        global $SESSIONS_COUNT;
        TimeTracking::stopTaskTimeTracking('session');
        static::finishTracking();
        static::$pastSessionUsageValues = static::calculateSessionUsageValues();

        MainLog::log(TimeTracking::getTasksTimeTrackingResultsBadge($SESSIONS_COUNT), 1, 0, MainLog::LOG_DEBUG);
    }

    //------------------------------------------------------------------------------------------------------------

    public static function resetAndStartTracking()
    {
        static::$trackCliData = [];

        //---

        ResourcesConsumption::killTrackCliPhp();

        $command = __DIR__ . '/' . static::trackCliPhp . '  --main_cli_php_pid ' . posix_getpid() . ' --time_interval ' . static::trackTimeInterval;
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $li = 0;
        do {
            $li++;
            if ($li > 10) {
                MainLog::log('Failed to run ' . static::trackCliPhp, 1, 0, MainLog::LOG_GENERAL_ERROR);
            }
            static::$trackCliPhpProcess = proc_open($command, $descriptorSpec, static::$trackCliPhpPipes);
            static::$trackCliPhpProcessPGid = procChangePGid(static::$trackCliPhpProcess, $log);
        } while (!static::$trackCliPhpProcess || !static::$trackCliPhpProcessPGid);

        MainLog::log(time() . ': ' . static::trackCliPhp . ' started with PGID ' . static::$trackCliPhpProcessPGid, 2, 0, MainLog::LOG_GENERAL_OTHER);

        NetworkConsumption::trackingPeriodNetworkUsageStartTracking();
    }

    public static function finishTracking()
    {
        if (static::$trackCliPhpProcess) {
            $processStatus = proc_get_status(static::$trackCliPhpProcess);
            if ($processStatus['running']) {
                @posix_kill(0 - static::$trackCliPhpProcessPGid, SIGTERM);
            }
            @proc_terminate(static::$trackCliPhpProcess);

            $stdOut = streamReadLines(static::$trackCliPhpPipes[1]);
            MainLog::log(time() . ': Output of ' . static::trackCliPhp,  1, 0, MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
            MainLog::log($stdOut, 2, 0,  MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);

            $stdOutLines = mbSplitLines($stdOut);
            foreach ($stdOutLines as $line) {
                $lineArr = @json_decode($line, JSON_OBJECT_AS_ARRAY);
                if (is_array($lineArr)) {
                    static::$trackCliData[] = $lineArr;
                }
            }
        }

        NetworkConsumption::trackingPeriodNetworkUsageFinishTracking();
    }

    public static function killTrackCliPhp()
    {
        $linuxProcesses = getLinuxProcesses();
        killZombieProcesses($linuxProcesses, [], static::trackCliPhp);
    }

    //------------------------------------------------------------------------------------------------------------

    public static function getTrackCliPhpColumnPercentageFromAvailable($columnName) : array
    {
        $column = array_column(static::$trackCliData, $columnName);

        if (count($column)) {
            $average = array_sum($column) / count($column);
            $peak    = max($column);
        } else {
            $average = -1;
            $peak    = -1;
        }

        return [
            'average' => intRound($average),
            'peak'    => intRound($peak)
        ];
    }

    /**
     * Peak CPU usage is calculated as maximal from average CPU usage during one minute
     */
    public static function getTrackCliPhpSystemPeakCpuUsageFromAvailable(): int
    {
        $ret = -1;
        $duration = 90;
        $durationInIntervals = floor($duration / static::trackTimeInterval);

        if ($durationInIntervals < 1) {
            return $ret;
        }

        $cpuUsageColumn = array_column(static::$trackCliData, 'systemCpu');

        if (count($cpuUsageColumn) < $durationInIntervals) {
            return $ret;
        }

        for ($start = 0; $start < count($cpuUsageColumn); $start++) {

            $durationCpuUsageColumn = array_slice($cpuUsageColumn, $start, $durationInIntervals);
            if (count($durationCpuUsageColumn) < $durationInIntervals) {
                continue;
            }

            //print_r($durationCpuUsageColumn);

            $durationAverage = ceil(array_sum($durationCpuUsageColumn) / count($durationCpuUsageColumn));
            $ret = max($ret, $durationAverage);
        }

        return $ret;
    }

    //------------------------------------------------------------------------------------

    private static function calculateSessionUsageValues() : array
    {
        global $CPU_USAGE_GOAL, $RAM_USAGE_GOAL, $NETWORK_USAGE_GOAL,
               $DB1000N_CPU_AND_RAM_LIMIT,
               $DISTRESS_CPU_AND_RAM_LIMIT;

        $configCpuLimit = intval($CPU_USAGE_GOAL);
        $configRamLimit = intval($RAM_USAGE_GOAL);

        $usageValues = [];

        //MainLog::log("\$trackCliData:\n\n" . print_r(static::$trackCliData, true),  1, 1, MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);

        // --

        $systemCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemCpu');
        $usageValues['systemAverageCpuUsage'] = [
            'current' => $systemCpuUsage['average'],
            'goal'    => min(90, $configCpuLimit)
        ];

        // --

        if ($systemCpuUsage['average'] === 100) {
            static::$cpuOverUsageSessionsCount++;
        } else {
            static::$cpuOverUsageSessionsCount = 0;
        }

        if (static::$cpuOverUsageSessionsCount) {
            $usageValues['systemAverageCpuUsage']['max'] = 100 - static::$cpuOverUsageSessionsCount * 10;
            $usageValues['systemAverageCpuUsage']['max'] = fitBetweenMinMax(30, false, $usageValues['systemAverageCpuUsage']['max']);
        }

        // ---

        $usageValues['systemPeakCpuUsage'] = [
            'current' => static::getTrackCliPhpSystemPeakCpuUsageFromAvailable(),
            'max'     => 99
        ];

        // ---

        $systemRamUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemRam');
        $usageValues['systemAverageRamUsage'] = [
            'current' => $systemRamUsage['average'],
            'goal'    => min(85, $configRamLimit)
        ];

        $usageValues['systemPeakRamUsage'] = [
            'current' => $systemRamUsage['peak'],
            'max'     => 95
        ];

        // ---

        $systemSwapUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemSwap');
        $usageValues['systemAverageSwapUsage'] = [
            'current'     => $systemSwapUsage['average'],
            'max'         => 30
        ];
        $usageValues['systemPeakSwapUsage'] = [
            'current'     => $systemSwapUsage['peak'],
            'max'         => 50
        ];

        // ---

        $systemTmpUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemTmp');
        $usageValues['systemAverageTmpUsage'] = [
            'current' => $systemTmpUsage['average'],
            'max'     => 60
        ];
        $usageValues['systemPeakTmpUsage'] = [
            'current' => $systemTmpUsage['peak'],
            'max'     => 80
        ];

        // ---

        $x100ProcessesCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100ProcessesCpu');
        $usageValues['x100ProcessesAverageCpuUsage'] = [
            'current'     => $x100ProcessesCpuUsage['average'],
            'max'         => 85
        ];

        $x100ProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100ProcessesMem');
        $usageValues['x100ProcessesAverageMemUsage'] = [
            'current'     => $x100ProcessesMemUsage['average'],
            'max'         => 85
        ];
        $usageValues['x100ProcessesPeakMemUsage'] = [
            'current'     => $x100ProcessesMemUsage['peak'],
            'max'         => 85
        ];

        // -----

        $db1000nProcessesCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('db1000nProcessesCpu');
        $usageValues['db1000nProcessesAverageCpuUsage'] = [
            'current'     => $db1000nProcessesCpuUsage['average'],
            'goal'        => min($configCpuLimit, intval($DB1000N_CPU_AND_RAM_LIMIT))
        ];

        $db1000nProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('db1000nProcessesMem');
        $usageValues['db1000nProcessesAverageMemUsage'] = [
            'current' => $db1000nProcessesMemUsage['average'],
            'goal'    => min($configRamLimit, intval($DB1000N_CPU_AND_RAM_LIMIT))
        ];

        // -----

        $distressProcessesCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('distressProcessesCpu');
        $usageValues['distressProcessesAverageCpuUsage'] = [
            'current'     => $distressProcessesCpuUsage['average'],
            'goal'        => min($configCpuLimit, intval($DISTRESS_CPU_AND_RAM_LIMIT))
        ];

        $distressProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('distressProcessesMem');
        $usageValues['distressProcessesAverageMemUsage'] = [
            'current'     => $distressProcessesMemUsage['average'],
            'goal'        => min($configRamLimit, intval($DISTRESS_CPU_AND_RAM_LIMIT))
        ];

        // -----

        $x100MainCliPhpCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100MainCliPhpCpu');
        $usageValues['x100MainCliPhpCpuUsage'] = [
            'current'     => $x100MainCliPhpCpuUsage['average'],
            'max'         => 10
        ];

        $x100MainCliPhpMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100MainCliPhpMem');
        $usageValues['x100MainCliPhpMemUsage'] = [
            'current'     => $x100MainCliPhpMemUsage['average'],
            'max'         => 10
        ];

        // -----

        if (
                $NETWORK_USAGE_GOAL
            &&  NetworkConsumption::$transmitSpeedLimitBits
            &&  NetworkConsumption::$receiveSpeedLimitBits
        ) {
            $receivePercent  = intRound(NetworkConsumption::$trackingPeriodReceiveSpeed  * 100 / NetworkConsumption::$receiveSpeedLimitBits);
            $transmitPercent = intRound(NetworkConsumption::$trackingPeriodTransmitSpeed * 100 / NetworkConsumption::$transmitSpeedLimitBits);

            $usageValues['systemAverageNetworkUsageReceive'] = [
                'current'     => $receivePercent,
                'goal'        => 95
            ];
            $usageValues['systemAverageNetworkUsageTransmit'] = [
                'current'     => $transmitPercent,
                'goal'        => 95
            ];
        }

        return $usageValues;
    }

    public static function reCalculateScale(&$usageValues, $currentScale, $initialScale, $minPossibleScale, $maxPossibleScale) : ?array
    {
        foreach ($usageValues as $ruleName => $ruleValues) {

            $currentPercent = $ruleValues['current'];
            $goalPercent    = $ruleValues['goal'] ?? -1;
            $maxPercent     = $ruleValues['max']  ?? -1;

            if ($currentPercent >= 0  &&  $maxPercent > 0  &&  $currentPercent > $maxPercent) {
                $newPercent = $maxPercent;
                $correctionBy = 'max';
            } else if ($currentPercent >= 0  &&  $goalPercent > 0) {
                $newPercent = $goalPercent;
                $correctionBy = 'goal';
            } else {
                $newPercent = 0;
                $correctionBy = '';
            }

            // ---

            if ($newPercent) {

                if ($currentPercent < 1) {
                    $currentPercent = 1;
                }

                $newScale = $currentScale * $newPercent / $currentPercent;
                $newScale = static::limitNewScaleStep($newScale, $currentScale, $initialScale, $ruleName);
                $newScale = round($newScale, 3);
                $newScale = fitBetweenMinMax($minPossibleScale, $maxPossibleScale, $newScale);
            } else {
                $newScale = 0;
            }


            $ruleValues['correctionBy'] = $correctionBy;
            $ruleValues['newScale']     = $newScale;
            $ruleValues['name']         = $ruleName;

            $usageValues[$ruleName] = $ruleValues;
        }

        $usageValues = sortArrayBySubValue($usageValues, true, 'newScale');
        foreach ($usageValues as $ruleValues) {
            if ($ruleValues['correctionBy']) {
                return $ruleValues;
            }
        }

        return null;
    }

    private static function limitNewScaleStep($newScale, $currentScale, $initialScale, $ruleName = '')
    {
        $calculatedStep = $newScale - $currentScale;
        $stepTimes = round($newScale / $currentScale, 1);

        if ($calculatedStep <= 0) {
            return $newScale;
        }

        if ($currentScale < $initialScale) {
            $upStep = min($calculatedStep, $initialScale);

        } else if ($stepTimes >= 8) {
            $upStep = min($calculatedStep, $currentScale * 2);   // Allow 3x boost

        } else if ($stepTimes >= 2) {
            $upStep = min($calculatedStep, $currentScale * floor($stepTimes) / 4);

        } else {
            $upStep = min($calculatedStep, $currentScale * 0.3);
        }

        //MainLog::log("ruleName=$ruleName; newScale=$newScale; currentScale=$currentScale; initialScale=$initialScale; calculatedStep=$calculatedStep; stepTimes=$stepTimes; upStep=$upStep", 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        return $currentScale + $upStep;
    }

}

ResourcesConsumption::constructStatic();
