<?php

class SelfUpdate
{
    private static $selfVersion,
                   $latestVersion;

    public static function constructStatic()
    {
        Actions::addAction('BeforeInitSession',  [static::class, 'actionBeforeInitSession']);
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT;

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
            static::update();
        }
    }

    public static function update()
    {
        static::fetchLatestVersion();
        static::fetchSelfVersion();
    }

    public static function getSelfVersion()
    {
        return static::$selfVersion;
    }

    public static function getLatestVersion()
    {
        return static::$latestVersion;
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

    public static function isDevelopmentVersion() : bool
    {
        return floatval(static::getSelfVersion()) > floatval(static::getLatestVersion());
    }

    public static function isUpToDate() : bool
    {
        if (! static::getLatestVersion()) {
            return false;
        }

        return floatval(static::getSelfVersion()) >= floatval(static::getLatestVersion());
    }
}

SelfUpdate::constructStatic();