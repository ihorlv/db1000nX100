<?php

class ResourcesConsumption extends LinuxResources
{
    const trackCliPhp = 'track.cli.php';

    private static
        $trackCliPhpProcess,
        $trackCliPhpProcessPGid,
        $trackCliPhpPipes,
        $trackCliData,
        $trackingStartedAt,
        $trackingFinishedAt,
        $tasksTimeTracking;

    private static array $speedtestStatTransmit,
                         $speedtestStatReceive;

    private static object $startTrackingVpnInstancesNetworkTotals,
                          $finishTrackingVpnInstancesNetworkTotals;


    public static int $transmitSpeedLimitBits,
                      $receiveSpeedLimitBits;


    public static function constructStatic()
    {
        static::$trackCliData = [];
        static::$speedtestStatTransmit = [];
        static::$speedtestStatReceive = [];
        Actions::addAction('BeforeInitSession',      [static::class, 'actionBeforeInitSession']);
        Actions::addAction('BeforeTerminateSession', [static::class, 'actionBeforeTerminateSession']);
        Actions::addAction('BeforeMainOutputLoop',   [static::class, 'resetAndStartTracking']);
    }

    public static function actionBeforeInitSession()
    {
        static::startTaskTimeTracking('session');
    }

    public static function actionBeforeTerminateSession()
    {
        global $SESSIONS_COUNT;
        static::stopTaskTimeTracking('session');
        static::finishTracking();
        MainLog::log(static::getTasksTimeTrackingResultsBadge($SESSIONS_COUNT), 1, 0, MainLog::LOG_DEBUG);
    }

    //------------------------------------------------------------------------------------------------------------

    public static function resetAndStartTracking()
    {
        static::$trackCliData = [];
        static::$trackingStartedAt = time();
        static::$trackingFinishedAt = 0;
        static::$startTrackingVpnInstancesNetworkTotals = OpenVpnConnectionStatic::getInstancesNetworkTotals();

        //---

        ResourcesConsumption::killTrackCliPhp();

        $command = __DIR__ . '/' . static::trackCliPhp . '  --main_cli_php_pid ' . posix_getpid() . ' --time_interval 10';
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
    }

    public static function finishTracking()
    {
        if (static::$trackCliPhpProcess) {
            $processStatus = proc_get_status(static::$trackCliPhpProcess);
            if ($processStatus['running']) {
                @posix_kill(0 - static::$trackCliPhpProcessPGid, SIGTERM);
            }
            @proc_terminate(static::$trackCliPhpProcess);

            $stdOut = streamReadLines(static::$trackCliPhpPipes[1], 0.1);
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

        static::$trackingFinishedAt = time();
        static::$finishTrackingVpnInstancesNetworkTotals = OpenVpnConnectionStatic::getInstancesNetworkTotals();
    }

    public static function killTrackCliPhp()
    {
        $linuxProcesses = getLinuxProcesses();
        killZombieProcesses($linuxProcesses, [], static::trackCliPhp);
    }

    //------------------------------------------------------------------------------------------------------------

     public static function getTrackCliPhpColumnPercentageFromAvailable($columnName) : array
    {
        $column  = array_column(static::$trackCliData, $columnName);
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

    //------------------------------------------------------------------------------------

    public static function getPreviousSessionAverageNetworkUsagePercentageFromAllowed()
    {
        global $NETWORK_USAGE_LIMIT;
        if (
               !$NETWORK_USAGE_LIMIT
            || !static::$receiveSpeedLimitBits
            || !static::$transmitSpeedLimitBits
        ) {
            return;
        }

        $trackingPeriodDuration = static::$trackingFinishedAt - static::$trackingStartedAt;

        $trackingPeriodReceived    = static::$finishTrackingVpnInstancesNetworkTotals->session->received    - static::$startTrackingVpnInstancesNetworkTotals->session->received;
        $trackingPeriodTransmitted = static::$finishTrackingVpnInstancesNetworkTotals->session->transmitted - static::$startTrackingVpnInstancesNetworkTotals->session->transmitted;

        $trackingPeriodReceiveSpeed  = intRound($trackingPeriodReceived    * 8 / $trackingPeriodDuration);
        $trackingPeriodTransmitSpeed = intRound($trackingPeriodTransmitted * 8 / $trackingPeriodDuration);

        $ret = [
            'receive'  => intRound($trackingPeriodReceiveSpeed  * 100 / static::$receiveSpeedLimitBits),
            'transmit' => intRound($trackingPeriodTransmitSpeed * 100 / static::$transmitSpeedLimitBits)
        ];

        return $ret;
    }

    public static function calculateNetworkBandwidthLimit($marginTop = 0, $marginBottom = 1)
    {
        global $NETWORK_USAGE_LIMIT,
               $SESSIONS_COUNT;

        if (!$NETWORK_USAGE_LIMIT) {
            return;
        } else if (!Config::isOptionValueInPercents($NETWORK_USAGE_LIMIT)) {
            $NETWORK_USAGE_LIMIT = (int) $NETWORK_USAGE_LIMIT;
            $transmitSpeedLimitMib = intRound($NETWORK_USAGE_LIMIT);
            $receiveSpeedLimitMib  = intRound($NETWORK_USAGE_LIMIT);
            MainLog::log("Network speed limit is set to fixed value {$NETWORK_USAGE_LIMIT}Mib (upload {$transmitSpeedLimitMib}Mib; download {$receiveSpeedLimitMib}Mib)", $marginBottom, $marginTop);
            static::$transmitSpeedLimitBits = $transmitSpeedLimitMib * 1024 * 1024;
            static::$receiveSpeedLimitBits  = $receiveSpeedLimitMib  * 1024 * 1024;
            return;
        }

        // ---------------------------

        $testReturnObj = $uploadBandwidthBits = $downloadBandwidthBits = null;

        $serversListStdout = _shell_exec('/usr/bin/speedtest  --servers  --format=json-pretty');
        $serversListReturnObj = @json_decode($serversListStdout);
        $serversList = $serversListReturnObj->servers  ??  [];
        if (count($serversList)) {

            shuffle($serversList);
            $attempt = 1;
            foreach ($serversList as $server) {

                MainLog::log("Performing Speed Test of your Internet connection ", 1, $attempt === 1  ?  $marginTop : 0);
                ResourcesConsumption::startTaskTimeTracking('InternetConnectionSpeedTest');
                $stdout = _shell_exec("/usr/bin/speedtest  --accept-license  --accept-gdpr  --server-id={$server->id}  --format=json-pretty");
                $stdout = preg_replace('#^.*?\{#s', '{', $stdout);
                $testReturnObj = @json_decode($stdout);
                ResourcesConsumption::stopTaskTimeTracking( 'InternetConnectionSpeedTest');

                $uploadBandwidthBits   = ($testReturnObj->upload->bandwidth   ?? 0) * 8;
                $downloadBandwidthBits = ($testReturnObj->download->bandwidth ?? 0) * 8;

                if (
                    is_object($testReturnObj)
                    &&  $uploadBandwidthBits
                    &&  $downloadBandwidthBits
                ) {
                    break;
                }

                if ($attempt >= 5) {
                    break;
                } else {
                    $attempt++;
                }

                MainLog::log($stdout, 1, 0, MainLog::LOG_GENERAL_ERROR);
                MainLog::log("Network speed test failed. Doing one more attempt", 2, 0, MainLog::LOG_GENERAL_ERROR);
            }
        } else {
            MainLog::log($serversListStdout,             1, 0, MainLog::LOG_GENERAL_ERROR);
            MainLog::log("Failed to fetch servers list", 1, 0, MainLog::LOG_GENERAL_ERROR);
        }

        if (
               !is_object($testReturnObj)
            || !$uploadBandwidthBits
            || !$downloadBandwidthBits
        ) {
            MainLog::log("Network speed test failed $attempt times", 1, 0, MainLog::LOG_GENERAL_ERROR);
            if (static::$transmitSpeedLimitBits  &&  static::$receiveSpeedLimitBits) {
                MainLog::log("The script will use previous session network limits");
            }
            MainLog::log('', $marginBottom);
            return;
        }

        $serverName     = $testReturnObj->server->name     ?? '';
        $serverLocation = $testReturnObj->server->location ?? '';
        $serverCountry  = $testReturnObj->server->country  ?? '';
        MainLog::log("Server:  $serverName; $serverLocation; $serverCountry; https://www.speedtest.net");

        static::$speedtestStatTransmit[$SESSIONS_COUNT] = $transmitSpeed = (int) $uploadBandwidthBits;
        static::$speedtestStatTransmit = array_slice(static::$speedtestStatTransmit, -10, null, true);
        $transmitSpeedAverage = intRound(array_sum(static::$speedtestStatTransmit) / count(static::$speedtestStatTransmit));
        static::$transmitSpeedLimitBits = intRound(intval($NETWORK_USAGE_LIMIT) * $transmitSpeedAverage / 100);

        static::$speedtestStatReceive[$SESSIONS_COUNT] = $receiveSpeed = (int) $downloadBandwidthBits;
        static::$speedtestStatReceive = array_slice(static::$speedtestStatReceive, -10, null, true);
        $receiveSpeedAverage = intRound(array_sum(static::$speedtestStatReceive) / count(static::$speedtestStatReceive));
        static::$receiveSpeedLimitBits = intRound(intval($NETWORK_USAGE_LIMIT) * $receiveSpeedAverage / 100);

        MainLog::log(
              'Results: Upload speed '
            . humanBytes($transmitSpeed, HUMAN_BYTES_BITS)
            . ', average '
            . humanBytes($transmitSpeedAverage, HUMAN_BYTES_BITS)
            . ', set limit to '
            . humanBytes(static::$transmitSpeedLimitBits, HUMAN_BYTES_BITS)
            . " ($NETWORK_USAGE_LIMIT)"
        );

        MainLog::log(
              '       Download speed '
            . humanBytes($receiveSpeed, HUMAN_BYTES_BITS)
            . ', average '
            . humanBytes($receiveSpeedAverage, HUMAN_BYTES_BITS)
            . ', set limit to '
            . humanBytes(static::$receiveSpeedLimitBits, HUMAN_BYTES_BITS)
            . " ($NETWORK_USAGE_LIMIT)",
        $marginBottom);
    }

    //------------------------------------------------------------------------------------

    public static function previousSessionUsageValues()
    {
        global $CPU_CORES_QUANTITY, $MAX_CPU_CORES_USAGE,
               $OS_RAM_CAPACITY, $MAX_RAM_USAGE,
               $DB1000N_CPU_AND_RAM_LIMIT,
               $DISTRESS_CPU_AND_RAM_LIMIT;

        $configCpuLimit = round($MAX_CPU_CORES_USAGE / $CPU_CORES_QUANTITY, 2);

        $configRamLimit = round($MAX_RAM_USAGE / $OS_RAM_CAPACITY, 2);
        if ($configRamLimit > 1) {
            $configRamLimit = 1;
        }

        $usageValues = [];

        // --

        $systemCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemCpu');
        $usageValues['systemAverageCpuUsage'] = [
            'current'     => $systemCpuUsage['average'],
            'goal'        => 90,
            'max'         => 98,
            'configLimit' => $configCpuLimit
        ];

        $systemRamUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemRam');
        $usageValues['systemAverageRamUsage'] = [
            'current'     => $systemRamUsage['average'],
            'goal'        => 85,
            'configLimit' => $configRamLimit
        ];
        $usageValues['systemPeakRamUsage'] = [
            'current'     => $systemRamUsage['peak'],
            'max'         => 98,
            'configLimit' => $configRamLimit
        ];

        // ---

        $systemSwapUsage = static::getTrackCliPhpColumnPercentageFromAvailable('systemSwap');
        $usageValues['systemAverageSwapUsage'] = [
            'current'     => $systemSwapUsage['average'],
            'max'         => 30,
            'configLimit' => $configRamLimit
        ];
        $usageValues['systemPeakSwapUsage'] = [
            'current'     => $systemSwapUsage['peak'],
            'max'         => 50,
            'configLimit' => $configRamLimit
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
            'max'         => 80,
            'configLimit' => $configCpuLimit
        ];

        $x100ProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100ProcessesMem');
        $usageValues['x100ProcessesAverageMemUsage'] = [
            'current'     => $x100ProcessesMemUsage['average'],
            'max'         => 80,
            'configLimit' => $configRamLimit
        ];
        $usageValues['x100ProcessesPeakMemUsage'] = [
            'current'     => $x100ProcessesMemUsage['peak'],
            'max'         => 80,
            'configLimit' => $configRamLimit
        ];

        // -----

        $db1000nProcessesCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('db1000nProcessesCpu');
        $usageValues['db1000nProcessesAverageCpuUsage'] = [
            'current'     => $db1000nProcessesCpuUsage['average'],
            'goal'        => intval($DB1000N_CPU_AND_RAM_LIMIT),
            'configLimit' => $configCpuLimit
        ];

        $db1000nProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('db1000nProcessesMem');
        $usageValues['db1000nProcessesAverageMemUsage'] = [
            'current'     => $db1000nProcessesMemUsage['average'],
            'goal'        => intval($DB1000N_CPU_AND_RAM_LIMIT),
            'configLimit' => $configRamLimit
        ];

        // -----

        $distressProcessesCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('distressProcessesCpu');
        $usageValues['distressProcessesAverageCpuUsage'] = [
            'current'     => $distressProcessesCpuUsage['average'],
            'goal'        => intval($DISTRESS_CPU_AND_RAM_LIMIT),
            'configLimit' => $configCpuLimit
        ];

        $distressProcessesMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('distressProcessesMem');
        $usageValues['distressProcessesAverageMemUsage'] = [
            'current'     => $distressProcessesMemUsage['average'],
            'goal'        => intval($DISTRESS_CPU_AND_RAM_LIMIT),
            'configLimit' => $configRamLimit
        ];

        // -----

        $x100MainCliPhpCpuUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100MainCliPhpCpu');
        $usageValues['x100MainCliPhpCpuUsage'] = [
            'current'     => $x100MainCliPhpCpuUsage['average'],
            'max'         => 10,
            'configLimit' => $configCpuLimit
        ];

        $x100MainCliPhpMemUsage = static::getTrackCliPhpColumnPercentageFromAvailable('x100MainCliPhpMem');
        $usageValues['x100MainCliPhpMemUsage'] = [
            'current'     => $x100MainCliPhpMemUsage['average'],
            'max'         => 10,
            'configLimit' => $configCpuLimit
        ];

        // -----

        $averageNetworkUsage = static::getPreviousSessionAverageNetworkUsagePercentageFromAllowed();
        if ($averageNetworkUsage) {
            $usageValues['averageNetworkUsageReceive'] = [
                'current'     => $averageNetworkUsage['receive'],
                'goal'        => 95
            ];
            $usageValues['averageNetworkUsageTransmit'] = [
                'current'    => $averageNetworkUsage['transmit'],
                'goal'        => 95
            ];
        }

        return $usageValues;
    }

    public static function getResourcesCorrection($usageValues) : ?array
    {
        foreach ($usageValues as $ruleName => $ruleValues) {
            $current     = $ruleValues['current'];
            $configLimit = $ruleValues['configLimit'] ?? 1;
            $goal        = $ruleValues['goal'] ?? -1;
            $max         = $ruleValues['max']  ?? -1;
            $goal       *= $configLimit;
            $max        *= $configLimit;

            if ($current >= 0  &&  $max >= 0  &&  $current > $max) {
                $ruleValues['correction'] = $max - $current;
                $ruleValues['correctionBy'] = 'max';
            } else if ($current >= 0  &&  $goal >= 0  &&  $current != $goal) {
                $ruleValues['correction'] = $goal - $current;
                $ruleValues['correctionBy'] = 'goal';
            }

            $usageValues[$ruleName] = $ruleValues;
        }

        // -----

        $finalCorrection = PHP_INT_MAX;
        $finalCorrectionRule = null;
        foreach ($usageValues as $ruleName => $rule) {
            $correction = $rule['correction']  ??  false;
            if (
                    $correction !== false
                &&  $correction < $finalCorrection
            ) {
                $finalCorrection = $rule['correction'];
                $finalCorrectionRule = $rule;
                $finalCorrectionRule['name'] = $ruleName;
            }
        }

        MainLog::log(print_r($usageValues, true), 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        if ($finalCorrectionRule) {
            MainLog::log(print_r($finalCorrectionRule, true),         2, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        }

        return $finalCorrectionRule;
    }

    public static function reCalculateScale($currentScale, $rule, $minPossibleScale, $maxPossibleScale, $maxPossibleStep) : float
    {
        $ruleCurrent    = $rule['current'];
        $ruleCorrection = $rule['correction'];

        if ($ruleCurrent  &&  $ruleCorrection) {
            $goal = $ruleCurrent + $ruleCorrection;
            $step = ($currentScale * $goal / $ruleCurrent) - $currentScale;
            $step = fitBetweenMinMax(-$maxPossibleStep, $maxPossibleStep, $step);
        } else {
            $step = 0;
        }

        $newScale = $currentScale + $step;
        $newScale = fitBetweenMinMax($minPossibleScale, $maxPossibleScale, $newScale);

        return $newScale;
    }

    //------------------ functions to track time expanses for particular operation ------------------

    public static function resetTaskTimeTracking()
    {
        static::$tasksTimeTracking = [];
    }

    public static function startTaskTimeTracking($taskName)
    {
        global $SESSIONS_COUNT;
        if (!SelfUpdate::$isDevelopmentVersion) {
            return;
        }

        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName]  ??  [];
        $lastItem['startedAt'] = hrtime(true);
        $taskData[] = $lastItem;
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
    }

    public static function stopTaskTimeTracking($taskName) : bool
    {
        global $SESSIONS_COUNT;
        if (!SelfUpdate::$isDevelopmentVersion) {
            return false;
        }

        if (!count(static::$tasksTimeTracking)) {
            return false;
        }
        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName];
        if (!$taskData) {
            return false;
        }
        $lastItemKey = array_key_last($taskData);
        $lastItem = $taskData[$lastItemKey];
        $lastItem['duration']   = hrtime(true) - $lastItem['startedAt'];
        $taskData[$lastItemKey] = $lastItem;
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
        return true;
    }

    public static function getTasksTimeTrackingResultsBadge($sessionId)
    {
        if (!SelfUpdate::$isDevelopmentVersion) {
            return '';
        }

        $tasksData =  static::$tasksTimeTracking[$sessionId];
        $ret = [];
        $sessionDuration = 1;
        foreach ($tasksData as $taskName => $taskData) {
            if ($taskName === 'session') {
                $sessionDuration = $taskData[0]['duration'];
            }

            $durationColumn = array_column($taskData, 'duration');
            $retItem['totalDuration'] = array_sum($durationColumn);
            $retItem['totalDurationSeconds'] = intdiv($retItem['totalDuration'], pow(10, 9));
            $retItem['percent'] = round($retItem['totalDuration'] * 100 / $sessionDuration);

            $retItem['count'] = count($durationColumn);
            $ret[$taskName] = $retItem;
        }
        MainLog::log("TasksTimeTrackingResults:\n" . print_r($ret, true), 2, 0,  MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
    }
}

ResourcesConsumption::constructStatic();
