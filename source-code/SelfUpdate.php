<?php

class SelfUpdate
{
    public static bool $isDevelopmentVersion = false;

    private static $selfVersion,
                   $latestVersion,
                   $dockerAutoUpdateLockFile;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        static::$dockerAutoUpdateLockFile = Config::$putYourOvpnFilesHerePath . '/docker-auto-update.lock';

        Actions::addAction('BeforeInitSession',  [static::class, 'actionBeforeInitSession'], 5);
        Actions::addFilter('IsFinalSession',     [static::class, 'filterIsFinalSession'], PHP_INT_MAX);

        static::refresh();
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

    private static function refresh()
    {
        static::fetchLatestVersion();
        static::fetchSelfVersion();
        static::$isDevelopmentVersion = floatval(static::getSelfVersion()) > floatval(static::getLatestVersion());
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT, $SOURCE_GUARDIAN_EXPIRATION_DATE;

        if ($SESSIONS_COUNT % 10 === 0) {
            static::refresh();
        }

        if (! static::dockerAutoUpdateLockFileExists()) {
            MainLog::log('This version of X100 will expire on ' . date('Y-m-d', $SOURCE_GUARDIAN_EXPIRATION_DATE));
        }
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

    private static function dockerAutoUpdateLockFileExists(): bool
    {
        return file_exists(static::$dockerAutoUpdateLockFile);
    }

    public static function filterIsFinalSession($final)
    {
        if (static::dockerAutoUpdateLockFileExists()) {

            if ($final) {
                unlink(static::$dockerAutoUpdateLockFile);
            } else if (static::isOutOfDate()) {
                MainLog::log('New version of X100 is available. Terminating X100 for automatic update', 3, 3);
                file_put_contents_secure(static::$dockerAutoUpdateLockFile, '2');
                $final = true;
            }
        }

        return $final;
    }
}

SelfUpdate::constructStatic();