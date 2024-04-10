<?php

abstract class DistressApplicationStatic extends HackApplication
{
    public static $distressCliPath,
        $targetsFilePath,
        $proxyPoolFilePath,
        $configFilePath,
        $localTargetsFileHasChanged = false,
        $localTargetsFileLastChangeAt = 0,
        $currentAffinityCoreId;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $DISTRESS_ENABLED, $TEMP_DIR;

        if (!$DISTRESS_ENABLED) {
            return;
        }

        require_once __DIR__ . '/DistressAutoUpdater.php';

        static::$distressCliPath = DistressAutoUpdater::$distressCliPath;
        static::$targetsFilePath = $TEMP_DIR . '/distress-targets.bin';
        static::$proxyPoolFilePath = $TEMP_DIR . '/distress-proxies.bin';
        static::$configFilePath = $TEMP_DIR . '/distress-config.bin';

        Actions::addFilter('RegisterHackApplicationClasses', [static::class, 'filterRegisterHackApplicationClasses'], 11);
        Actions::addFilter('InitSessionResourcesCorrection', [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('BeforeInitSession', [static::class, 'actionBeforeInitSession']);
        Actions::addAction('AfterInitSession', [DistressAutoUpdater::class, 'setCapabilities'], 100);

        Actions::addAction('BeforeTerminateSession', [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateFinalSession', [static::class, 'terminateInstances']);
        Actions::addAction('TerminateSession', [static::class, 'killInstances']);
        Actions::addAction('TerminateFinalSession', [static::class, 'killInstances']);
        Actions::addFilter('KillZombieProcesses', [static::class, 'filterKillZombieProcesses']);


    }

    public static function filterRegisterHackApplicationClasses($classNamesArray)
    {
        $classNamesArray[] = 'distressApplication';
        return $classNamesArray;
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT, $DISTRESS_USE_PROXY_POOL, $NEW_DIR_ACCESS_MODE;

        MainLog::log('');
        static::$currentAffinityCoreId = -1;

        // ---

        if (file_exists(static::$targetsFilePath)) {
            $targetsFileChangeMessage = '';

            if (static::$localTargetsFileLastChangeAt) {
                $targetsFileChangeMessage = 'Last Distress targets file change was at ' . date('Y-m-d H:i:s', static::$localTargetsFileLastChangeAt);
            } else if ($SESSIONS_COUNT !== 1) {
                $targetsFileChangeMessage = "The Distress targets file hasn't changed after X100 script was launched";
            }

            $targetsFileChangeMessage = Actions::doFilter('TargetsFileChangeMessage', $targetsFileChangeMessage);
            MainLog::log($targetsFileChangeMessage);
        } else {
            MainLog::log('Failed to load targets file for Distress', 1, 1);
        }

        // ---

        if (!file_exists(static::$configFilePath)) {
            MainLog::log('Failed to load Distress Config file', 1, 1);
        }

        // ---

        if (
            $DISTRESS_USE_PROXY_POOL && !file_exists(static::$proxyPoolFilePath)
        ) {
            MainLog::log('Failed to load Distress Proxy Pool file', 1, 1);
        }

        // ---

        $distressTmpDir = '/tmp/distress';
        if (is_dir($distressTmpDir)) {
            rmdirRecursive($distressTmpDir);
        }

        mkdir($distressTmpDir, $NEW_DIR_ACCESS_MODE);
        chown($distressTmpDir, 'app-h');
        chgrp($distressTmpDir, 'app-h');

        // ---

        $distressTmpTorDir = $distressTmpDir . '/tor';
        mkdir($distressTmpTorDir, $NEW_DIR_ACCESS_MODE);
        chown($distressTmpTorDir, 'app-h');
        chgrp($distressTmpTorDir, 'app-h');
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $SESSIONS_COUNT, $DISTRESS_SCALE, $DISTRESS_SCALE_INITIAL, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX;

        MainLog::log('');

        if ($SESSIONS_COUNT === 1) {
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

        MainLog::log('Distress scale calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        MainLog::log(print_r($usageValuesCopy, true), 2, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);

        $resourcesCorrectionRule = ResourcesConsumption::reCalculateScale($usageValuesCopy, $DISTRESS_SCALE, $DISTRESS_SCALE_INITIAL, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX);
        $newScale = intRound(val($resourcesCorrectionRule, 'newScale'));

        if ($newScale && $newScale !== $DISTRESS_SCALE) {
            MainLog::log($newScale > $DISTRESS_SCALE ? 'Increasing' : 'Decreasing', 0);
            MainLog::log(" Distress scale value from $DISTRESS_SCALE to $newScale because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
        }

        $DISTRESS_SCALE = $newScale;

        // ---

        beforeReturn:

        $scaleValueMessage = ($SESSIONS_COUNT === 1 ? 'Initial ' : '');
        $scaleValueMessage .= "Distress scale value (concurrency) $DISTRESS_SCALE";
        $scaleValueMessage = Actions::doFilter('ScaleValueMessage', $scaleValueMessage);
        MainLog::log($scaleValueMessage . ", range $DISTRESS_SCALE_MIN-$DISTRESS_SCALE_MAX");

        return $usageValues;
    }
    public static function countPossibleInstances(): int
    {
        global $DISTRESS_ENABLED;
        return $DISTRESS_ENABLED ? 1000000 : 0;
    }

    public static function getNewInstance($vpnConnection)
    {
        global $DISTRESS_ENABLED;

        if ($DISTRESS_ENABLED) {
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
    public static function prepareCustomFileForDistress($filename)
    {
        global $TEMP_DIR;

        $externalPath = Config::$putYourOvpnFilesHerePath . '/' . $filename;
        $internalPath = $TEMP_DIR . '/' . $filename;

        if (file_exists($externalPath)) {
            copy($externalPath, $internalPath);
            chown($internalPath, 'app-h');
            chgrp($internalPath, 'app-h');
            return $internalPath;
        }
    }

}