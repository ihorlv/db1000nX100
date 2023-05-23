<?php

class SelfUpdate
{
    public static bool $isDevelopmentVersion = false;

    private static $selfVersion,
                   $latestVersion;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources',  [static::class, 'refresh'], 5);
        Actions::addAction('BeforeInitSession',        [static::class, 'actionBeforeInitSession'], 5);
        Actions::addFilter('IsFinalSession',           [static::class, 'filterIsFinalSession'], PHP_INT_MAX);
    }

    private static function fetchSelfVersion()
    {
        $version = trim(@file_get_contents(__DIR__ . '/version.txt'));
        if ($version) {
            static::$selfVersion = trim($version);
        } else {
            static::$selfVersion = false;
        }
    }

    private static function fetchLatestVersion()
    {
        $latestVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/version.txt';
        $latestVersion = httpGet($latestVersionUrl, $httpCode);
        if ($latestVersion !== false) {
            static::$latestVersion = trim($latestVersion);
        } else {
            static::$latestVersion = false;
        }
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT;

        if ($SESSIONS_COUNT % 10 === 0) {
            static::refresh();
        }
    }

    public static function refresh()
    {
        static::fetchLatestVersion();
        static::fetchSelfVersion();
        static::$isDevelopmentVersion = floatval(static::getSelfVersion()) > floatval(static::getLatestVersion());
    }

    public static function isOutOfDate() : bool
    {
        if (! static::getLatestVersion()) {
            return false;
        }

        return floatval(static::getSelfVersion()) < floatval(static::getLatestVersion());
    }

    public static function getSelfVersion()
    {
        return static::$selfVersion;
    }

    public static function getLatestVersion()
    {
        return static::$latestVersion;
    }

    public static function filterIsFinalSession($final)
    {
        $dockerAutoUpdateLockFile = Config::$putYourOvpnFilesHerePath . '/docker-auto-update.lock';

        if (file_exists($dockerAutoUpdateLockFile)) {

            if ($final) {
                unlink($dockerAutoUpdateLockFile);
            } else if (static::isOutOfDate()) {
                MainLog::log('New version of X100 is available. Terminating X100 for automatic update', 3, 3);
                file_put_contents_secure($dockerAutoUpdateLockFile, '2');
                $final = true;
            }
        }

        return $final;
    }
}

SelfUpdate::constructStatic();