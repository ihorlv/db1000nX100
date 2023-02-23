<?php

abstract class DistressApplicationStatic extends HackApplication
{
    public static $distressCliPath,
                  $localTargetsFilePath,
                  $useLocalTargetsFile,
                  $localTargetsFileHasChanged = false;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $DISTRESS_CPU_AND_RAM_LIMIT, $TEMP_DIR;

        if (!intval($DISTRESS_CPU_AND_RAM_LIMIT)) {
            return;
        }

        static::$distressCliPath  = __DIR__ . '/app';
        static::$localTargetsFilePath = $TEMP_DIR . '/distress-config.bin';
        static::$useLocalTargetsFile = false;

        Actions::addFilter('RegisterHackApplicationClasses',  [static::class, 'filterRegisterHackApplicationClasses'], 11);
        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('BeforeInitSession',               [static::class, 'actionBeforeInitSession']);
        Actions::addAction('AfterInitSession',               [static::class, 'setCapabilities'], 100);
        Actions::addAction('BeforeMainOutputLoop',           [static::class, 'actionBeforeMainOutputLoop']);

        Actions::addAction('BeforeTerminateSession',         [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'terminateInstances']);
        Actions::addAction('TerminateSession',               [static::class, 'killInstances']);
        Actions::addAction('TerminateFinalSession',          [static::class, 'killInstances']);
        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);

        require_once __DIR__ . '/DistressAutoUpdater.php';
    }

    public static function filterRegisterHackApplicationClasses($classNamesArray)
    {
        $classNamesArray[] = 'distressApplication';
        return $classNamesArray;
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT;

        static::$localTargetsFileHasChanged = false;

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 5 === 0) {
            $result = DistressGetTargetsFile::get(static::$localTargetsFilePath);
            static::$useLocalTargetsFile = $result->success;
            static::$localTargetsFileHasChanged = $result->changed;

            if (!static::$useLocalTargetsFile) {
                MainLog::log('Failed to download config file for Distress');
            }
        }
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $SESSIONS_COUNT, $DISTRESS_SCALE, $DISTRESS_SCALE_INITIAL, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX, $DISTRESS_SCALE_MAX_STEP;

        MainLog::log('');

        if ($SESSIONS_COUNT === 1) {
            MainLog::log("Distress initial scale $DISTRESS_SCALE, range $DISTRESS_SCALE_MIN-$DISTRESS_SCALE_MAX");
            goto beforeReturn;
        } else if (static::$localTargetsFileHasChanged) {
            MainLog::log("Distress targets file has changed, reset scale value (concurrency) to initial value $DISTRESS_SCALE_INITIAL");
            $DISTRESS_SCALE = $DISTRESS_SCALE_INITIAL;
            goto beforeReturn;
        }

        // ---

        $usageValuesCopy = $usageValues;
        unset($usageValuesCopy['systemAverageTmpUsage']);
        unset($usageValuesCopy['systemPeakTmpUsage']);        
        
        MainLog::log('Distress    average  CPU   usage during previous session was ' . padPercent($usageValuesCopy['distressProcessesAverageCpuUsage']['current']));
        MainLog::log('Distress    average  RAM   usage during previous session was ' . padPercent($usageValuesCopy['distressProcessesAverageMemUsage']['current']), 2);

        $resourcesCorrectionRule = ResourcesConsumption::reCalculateScaleNG($usageValuesCopy, $DISTRESS_SCALE, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX, $DISTRESS_SCALE_MAX_STEP);
        MainLog::log('Distress scale calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        MainLog::log(print_r($usageValuesCopy, true), 2, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);

        $newScale = intRound($resourcesCorrectionRule['newScale']);
        if ($newScale !== $DISTRESS_SCALE) {
            MainLog::log($newScale > $DISTRESS_SCALE   ?  'Increasing' : 'Decreasing', 0);
            MainLog::log(" Distress scale value from $DISTRESS_SCALE to $newScale because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
        }

        $DISTRESS_SCALE = $newScale;
        MainLog::log("Distress scale value (concurrency) $DISTRESS_SCALE, range $DISTRESS_SCALE_MIN-$DISTRESS_SCALE_MAX", 2);

        beforeReturn:
        return $usageValues;
    }

    public static function actionBeforeMainOutputLoop()
    {
        global $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT;
        // Check effectiveness
        foreach (static::getRunningInstances() as $distressApplication) {
            $efficiencyLevel = $distressApplication->getEfficiencyLevel();
            if (
                    $efficiencyLevel === 0
                &&  $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT > 1
            ) {
                $distressApplication->requireTerminate('Zero efficiency');
            }
        }
    }

    public static function countPossibleInstances() : int
    {
        global $DISTRESS_CPU_AND_RAM_LIMIT;
        return intval($DISTRESS_CPU_AND_RAM_LIMIT)  ?  1000000 : 0;
    }

    public static function getNewInstance($vpnConnection)
    {
        global $DISTRESS_CPU_AND_RAM_LIMIT;

        if (intval($DISTRESS_CPU_AND_RAM_LIMIT)) {
            return new DistressApplication($vpnConnection);
        } else {
            return false;
        }
    }

    public static function filterKillZombieProcesses($data)
    {
        killZombieProcesses($data['linuxProcesses'], [], static::$distressCliPath);
        return $data;
    }

    public static function setCapabilities()
    {
        $output = trim(_shell_exec("/usr/sbin/setcap 'cap_net_raw+ep' " . static::$distressCliPath));
        if ($output) {
            MainLog::log($output, 1, 1, MainLog::LOG_HACK_APPLICATION);
        }
    }
    
}