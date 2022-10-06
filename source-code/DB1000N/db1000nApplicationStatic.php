<?php

abstract class db1000nApplicationStatic extends HackApplication
{
    // ----------------------  Static part of the class ----------------------

    protected static $db1000nCliPath,
                     $localNeedlesTargetsFilePath,
                     $useLocalConfig;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $DB1000N_CPU_AND_RAM_LIMIT, $TEMP_DIR;

        if (!intval($DB1000N_CPU_AND_RAM_LIMIT)) {
            return;
        }

        static::$localNeedlesTargetsFilePath = $TEMP_DIR . '/db1000n-config.json';
        static::$db1000nCliPath  = __DIR__ . '/db1000n';
        static::$useLocalConfig = false;

        Actions::addFilter('RegisterHackApplicationClasses',  [static::class, 'filterRegisterHackApplicationClasses']);
        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession']);
        Actions::addAction('BeforeMainOutputLoop',           [static::class, 'actionBeforeMainOutputLoop']);

        Actions::addAction('BeforeTerminateSession',         [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'terminateInstances']);
        Actions::addAction('TerminateSession',               [static::class, 'killInstances']);
        Actions::addAction('TerminateFinalSession',          [static::class, 'killInstances']);
        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);

        require_once __DIR__ . '/db1000nAutoUpdater.php';
    }

    public static function filterRegisterHackApplicationClasses($classNamesArray)
    {
        $classNamesArray[] = 'db1000nApplication';
        return $classNamesArray;
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $DB1000N_SCALE, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX, $DB1000N_SCALE_MAX_STEP;

        $usageValuesCopy = $usageValues;
        unset($usageValuesCopy['systemAverageTmpUsage']);
        unset($usageValuesCopy['systemPeakTmpUsage']);

        MainLog::log('db1000n     average  CPU   usage during previous session was ' . padPercent($usageValuesCopy['db1000nProcessesAverageCpuUsage']['current']));
        MainLog::log('db1000n     average  RAM   usage during previous session was ' . padPercent($usageValuesCopy['db1000nProcessesAverageMemUsage']['current']), 2);

        MainLog::log('db1000n scale calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        $resourcesCorrectionRule = ResourcesConsumption::getResourcesCorrection($usageValuesCopy);

        if ($resourcesCorrectionRule) {
            $previousSessionScale = $DB1000N_SCALE;
            $DB1000N_SCALE = ResourcesConsumption::reCalculateScale($DB1000N_SCALE, $resourcesCorrectionRule, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX, $DB1000N_SCALE_MAX_STEP);
            $DB1000N_SCALE = round($DB1000N_SCALE, 3);

            if ($DB1000N_SCALE !== $previousSessionScale) {
                MainLog::log($DB1000N_SCALE > $previousSessionScale  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" db1000n scale value from $previousSessionScale to $DB1000N_SCALE because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
            }
        }

        MainLog::log("db1000n scale value $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX", 2);
        return $usageValues;
    }

    public static function actionAfterInitSession()
    {
        global $SESSIONS_COUNT, $DB1000N_SCALE, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX;

        if ($SESSIONS_COUNT === 1) {
            MainLog::log("db1000n initial scale $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX");
        }

        // ---

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 5 === 0) {
            @unlink(static::$localNeedlesTargetsFilePath);
            static::loadConfig();
            if (file_exists(static::$localNeedlesTargetsFilePath)) {
                static::$useLocalConfig = true;
            } else {
                static::$useLocalConfig = false;
            }
        }

        MainLog::log('', 1, 0, MainLog::LOG_HACK_APPLICATION);
    }

    public static function actionBeforeMainOutputLoop()
    {
        global $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT;
        // Check effectiveness
        foreach (static::getRunningInstances() as $db1000nApplication) {
            $efficiencyLevel = $db1000nApplication->getEfficiencyLevel();
            if (
                $efficiencyLevel === 0
                &&  $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT > 1
            ) {
                $db1000nApplication->requireTerminate('Zero efficiency');
            }
        }
    }

    public static function countPossibleInstances() : int
    {
        global $DB1000N_CPU_AND_RAM_LIMIT;
        return intval($DB1000N_CPU_AND_RAM_LIMIT)  ?  1000000 : 0;
    }

    public static function getNewInstance($vpnConnection)
    {
        global $DB1000N_CPU_AND_RAM_LIMIT;

        if (intval($DB1000N_CPU_AND_RAM_LIMIT)) {
            return new db1000nApplication($vpnConnection);
        } else {
            return false;
        }
    }

    protected static function loadConfig()
    {
        global $USE_X100_COMMUNITY_TARGETS;

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
        }

        // ----

        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $db1000nCfgUpdater     = proc_open(__DIR__ . "/db1000n  --log-format json  -updater-mode  -updater-destination-config " . static::$localNeedlesTargetsFilePath, $descriptorSpec, $pipes);
        $db1000nCfgUpdaterPGid = procChangePGid($db1000nCfgUpdater);
        if ($db1000nCfgUpdaterPGid === false) {
            MainLog::log('Failed to run db1000n in "config updater" mode');
            return;
        }

        stream_set_blocking($pipes[2], false);
        $timeout = 30;
        $delay = 0.1;
        $configDownloadedSuccessfully = false;

        do {
            $stdout = streamReadLines($pipes[2], 0.05);
            $lines = mbSplitLines($stdout);
            foreach ($lines as $line) {
                $obj = @json_decode($line);
                if (is_object($obj)) {
                    if ($obj->msg === 'loading config') {
                        MainLog::log('Config file for db1000n downloaded from ' . $obj->path);
                    }
                    if (
                        $obj->msg === 'Saved file'
                        &&  $obj->size > 0
                    ) {
                        $configDownloadedSuccessfully = true;
                        break 2;
                    }
                }
            }
            sayAndWait($delay);
            $timeout -= $delay;
        } while ($timeout > 0);

        @posix_kill(0 - $db1000nCfgUpdaterPGid, SIGTERM);
        if (! $configDownloadedSuccessfully) {
            MainLog::log('Failed to downloaded config file for db1000n');
        }

        beforeReturn:

        @chown(static::$localNeedlesTargetsFilePath, 'hack-app');
        @chgrp(static::$localNeedlesTargetsFilePath, 'hack-app');
    }

    public static function filterKillZombieProcesses($data)
    {
        killZombieProcesses($data['linuxProcesses'], [], static::$db1000nCliPath);
        return $data;
    }
}