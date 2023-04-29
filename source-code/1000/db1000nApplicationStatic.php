<?php

abstract class db1000nApplicationStatic extends HackApplication
{
    // ----------------------  Static part of the class ----------------------

    protected static $db1000nCliPath,
                     $useLocalTargetsFile,
                     $localTargetsFilePath,
                     $localTargetsFileHasChanged,
                     $localTargetsFileLastChangeAt = 0;

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

        static::$db1000nCliPath  = __DIR__ . '/app';
        static::$useLocalTargetsFile = false;
        static::$localTargetsFilePath = $TEMP_DIR . '/1000.json';
        static::$localTargetsFileHasChanged = false;

        Actions::addFilter('RegisterHackApplicationClasses',  [static::class, 'filterRegisterHackApplicationClasses']);
        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('BeforeInitSession',               [static::class, 'actionBeforeInitSession']);
        Actions::addAction('AfterInitSession',                [static::class, 'setCapabilities'], 100);
        Actions::addAction('BeforeMainOutputLoop',            [static::class, 'actionBeforeMainOutputLoop']);

        Actions::addAction('BeforeTerminateSession',          [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateFinalSession',     [static::class, 'terminateInstances']);
        Actions::addAction('TerminateSession',                [static::class, 'killInstances']);
        Actions::addAction('TerminateFinalSession',           [static::class, 'killInstances']);
        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);

        require_once __DIR__ . '/db1000nAutoUpdater.php';
    }

    public static function filterRegisterHackApplicationClasses($classNamesArray)
    {
        $classNamesArray[] = 'db1000nApplication';
        return $classNamesArray;
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

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT;

        static::$localTargetsFileHasChanged = false;

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 5 === 0) {
            static::$useLocalTargetsFile = false;

            $previousTargetsFileHash = @md5_file(static::$localTargetsFilePath);
            @unlink(static::$localTargetsFilePath);
            static::loadConfig();

            if (file_exists(static::$localTargetsFilePath)) {
                static::$useLocalTargetsFile = true;
                $currentTargetsFileHash = md5_file(static::$localTargetsFilePath);
                static::$localTargetsFileHasChanged = $previousTargetsFileHash
                                                      && $previousTargetsFileHash !== $currentTargetsFileHash;

                if (static::$localTargetsFileHasChanged) {
                    static::$localTargetsFileLastChangeAt = time();
                }
            }
        }

        // ---

        if (static::$useLocalTargetsFile) {
            $targetsFileChangeMessage = '';
            if (static::$localTargetsFileLastChangeAt) {
                $targetsFileChangeMessage = 'Last db1000n targets file change was at ' . date('Y-m-d H:i:s', static::$localTargetsFileLastChangeAt);
            } else if ($SESSIONS_COUNT !== 1) {
                $targetsFileChangeMessage = "The db1000n targets file hasn't changed after X100 script was launched";
            }
            $targetsFileChangeMessage = Actions::doFilter('TargetsFileChangeMessage', $targetsFileChangeMessage);
            MainLog::log($targetsFileChangeMessage);
        }
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $SESSIONS_COUNT, $DB1000N_SCALE, $DB1000N_SCALE_INITIAL, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX;

        MainLog::log('');

        if ($SESSIONS_COUNT === 1) {
            MainLog::log('Initial ', 0);
            goto beforeReturn;
        } else if (static::$localTargetsFileHasChanged) {
            MainLog::log("db1000n targets file has changed, reset scale value to initial value $DB1000N_SCALE_INITIAL");
            $DB1000N_SCALE = $DB1000N_SCALE_INITIAL;
            goto beforeReturn;
        }

        // ---

        $usageValuesCopy = $usageValues;
        unset($usageValuesCopy['systemAverageTmpUsage']);
        unset($usageValuesCopy['systemPeakTmpUsage']);

        MainLog::log('db1000n     average  CPU   usage during previous session was ' . padPercent($usageValuesCopy['db1000nProcessesAverageCpuUsage']['current']));
        MainLog::log('db1000n     average  RAM   usage during previous session was ' . padPercent($usageValuesCopy['db1000nProcessesAverageMemUsage']['current']), 2);

        $resourcesCorrectionRule = ResourcesConsumption::reCalculateScale($usageValuesCopy, $DB1000N_SCALE, $DB1000N_SCALE_INITIAL, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX);
        MainLog::log('db1000n scale calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        MainLog::log(print_r($usageValuesCopy, true), 2, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);

        $newScale = $resourcesCorrectionRule['newScale'];
        if ($newScale !== $DB1000N_SCALE) {
            MainLog::log($newScale > $DB1000N_SCALE   ?  'Increasing' : 'Decreasing', 0,);
            MainLog::log(" db1000n scale value from $DB1000N_SCALE to $newScale because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
        }

        $DB1000N_SCALE = $newScale;

        // ---

        beforeReturn:
        MainLog::log("db1000n scale value $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX");
        return $usageValues;
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
                file_put_contents_secure(static::$localTargetsFilePath, $communityTargets);
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

        $db1000nCfgUpdater     = proc_open(static::$db1000nCliPath . "  --log-format=json  --updater-mode  --updater-destination-config=" . static::$localTargetsFilePath, $descriptorSpec, $pipes);
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
            MainLog::log('Failed to download config file for db1000n');
        }

        beforeReturn:

        @chown(static::$localTargetsFilePath, 'app-h');
        @chgrp(static::$localTargetsFilePath, 'app-h');
    }

    public static function filterKillZombieProcesses($data)
    {
        killZombieProcesses($data['linuxProcesses'], [], static::$db1000nCliPath);
        return $data;
    }

    public static function setCapabilities()
    {
        $output = trim(_shell_exec("/usr/sbin/setcap 'cap_net_raw+ep' " . static::$db1000nCliPath));
        if ($output) {
            MainLog::log($output, 1, 1, MainLog::LOG_HACK_APPLICATION);
        }
    }
}