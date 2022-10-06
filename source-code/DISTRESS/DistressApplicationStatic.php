<?php

abstract class DistressApplicationStatic extends HackApplication
{
    protected static $distressCliPath,
                     $localTargetsFilePath,
                     $useLocalTargetsFile;

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

        static::$distressCliPath  = __DIR__ . '/distress';
        static::$localTargetsFilePath = $TEMP_DIR . '/distress-config.bin';
        static::$useLocalTargetsFile = false;

        Actions::addFilter('RegisterHackApplicationClasses',  [static::class, 'filterRegisterHackApplicationClasses'], 11);
        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession']);
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

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $DISTRESS_SCALE, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX, $DISTRESS_SCALE_MAX_STEP;

        $usageValuesCopy = $usageValues;
        unset($usageValuesCopy['systemAverageTmpUsage']);
        unset($usageValuesCopy['systemPeakTmpUsage']);        
        
        MainLog::log('Distress    average  CPU   usage during previous session was ' . padPercent($usageValuesCopy['distressProcessesAverageCpuUsage']['current']));
        MainLog::log('Distress    average  RAM   usage during previous session was ' . padPercent($usageValuesCopy['distressProcessesAverageMemUsage']['current']), 2);

        MainLog::log('Distress scale calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        $resourcesCorrectionRule = ResourcesConsumption::getResourcesCorrection($usageValuesCopy);
        if ($resourcesCorrectionRule) {
            $previousSessionScale = $DISTRESS_SCALE;
            $DISTRESS_SCALE = intRound(ResourcesConsumption::reCalculateScale($DISTRESS_SCALE, $resourcesCorrectionRule, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX, $DISTRESS_SCALE_MAX_STEP));

            if ($DISTRESS_SCALE !== $previousSessionScale) {
                MainLog::log($DISTRESS_SCALE > $previousSessionScale  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" Distress scale value from $previousSessionScale to $DISTRESS_SCALE because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
            }
        }
        MainLog::log("Distress scale value $DISTRESS_SCALE, range $DISTRESS_SCALE_MIN-$DISTRESS_SCALE_MAX", 2);
        return $usageValues;
    }

    public static function actionAfterInitSession()
    {
        global $SESSIONS_COUNT, $DISTRESS_SCALE, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX;

        if ($SESSIONS_COUNT === 1) {
            MainLog::log("Distress initial scale $DISTRESS_SCALE, range $DISTRESS_SCALE_MIN-$DISTRESS_SCALE_MAX");
        }

        // ---

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 5 === 0) {
            @unlink(static::$localTargetsFilePath);
            static::loadConfig();
            if (file_exists(static::$localTargetsFilePath)) {
                static::$useLocalTargetsFile = true;
            } else {
                static::$useLocalTargetsFile = false;
            }
        }

        MainLog::log('', 1, 0, MainLog::LOG_HACK_APPLICATION);
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

    protected static function loadConfig()
    {
        /*global $USE_X100_COMMUNITY_TARGETS;

        if ($USE_X100_COMMUNITY_TARGETS) {
            $developmentTargetsFilePath = __DIR__ . '/needles.bin';
            if (file_exists($developmentTargetsFilePath)) {
                $communityTargets = base64_decode(file_get_contents($developmentTargetsFilePath));
                MainLog::log('Development targets file for db1000n loaded from ' . $developmentTargetsFilePath);
            } else {
                $communityTargetsFileUrl = 'https://raw.githubusercontent.com/teamX100/teamX100/master/needles.bin';
                $communityTargets = base64_decode(httpGet($communityTargetsFileUrl));
                MainLog::log('Community targets file for db1000n downloaded from ' . $communityTargetsFileUrl);
            }

            if ($communityTargets) {
                file_put_contents_secure(static::$localNeedlesTargetsFilePath, $communityTargets);
                goto beforeReturn;
            } else {
                MainLog::log('Invalid community targets files');
            }
        }*/

        // ----



        beforeReturn:

        @chown(static::$localTargetsFilePath, 'hack-app');
        @chgrp(static::$localTargetsFilePath, 'hack-app');
    }

    public static function filterKillZombieProcesses($data)
    {
        killZombieProcesses($data['linuxProcesses'], [], static::$distressCliPath);
        return $data;
    }
    
}