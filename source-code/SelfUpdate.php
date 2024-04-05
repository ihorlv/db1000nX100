<?php

class SelfUpdate
{
    public static bool $isDevelopmentVersion = false;

    private static $selfVersion, $latestVersion;
    public static string $dockerAutoUpdateLockFile;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources'], 9);
    }

    public static function actionAfterCalculateResources()
    {
        static::$dockerAutoUpdateLockFile = Config::$putYourOvpnFilesHerePath . '/docker-auto-update.lock';

        Actions::addAction('BeforeMainOutputLoop', [static::class, 'actionBeforeMainOutputLoop'], 5);
        Actions::addAction('AfterTerminateFinalSession', [static::class, 'actionAfterTerminateFinalSession']);

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

    public static function actionBeforeMainOutputLoop()
    {
        global $SESSIONS_COUNT, $SOURCE_GUARDIAN_EXPIRATION_DATE;

        if ($SESSIONS_COUNT % 10 === 0) {
            static::refresh();
        }

        if (static::dockerAutoUpdateLockFileExists()) {
            if (static::isOutOfDate()) {
                file_put_contents_secure(static::$dockerAutoUpdateLockFile, '2');
                MainLog::log('New version of X100 is available. Terminate X100 for automatic update');
            }
        } else {
            MainLog::log('This version of X100 will expire on ' . date('Y-m-d', $SOURCE_GUARDIAN_EXPIRATION_DATE));
        }
    }

    public static function isOutOfDate(): bool
    {
        if (!static::getLatestVersion()) {
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

    public static function dockerAutoUpdateLockFileExists(): bool
    {
        return file_exists(static::$dockerAutoUpdateLockFile);
    }

    public static function actionAfterTerminateFinalSession()
    {
        if (static::dockerAutoUpdateLockFileExists()) {
            $contents = trim(file_get_contents(SelfUpdate::$dockerAutoUpdateLockFile));
            if ($contents !== '2') {
                unlink(static::$dockerAutoUpdateLockFile);
            }
        }
    }
}

SelfUpdate::constructStatic();