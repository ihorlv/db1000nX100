<?php

use JetBrains\PhpStorm\Pure;

class SelfUpdate
{
    private static $selfVersion,
                   $latestVersion;

    public static function constructStatic()
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
            static::$selfVersion = (float) trim($version);
        } else {
            static::$selfVersion = false;
        }
    }

    private static function fetchLatestVersion()
    {
        $latestVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/version.txt';
        $latestVersion = httpDownload($latestVersionUrl);
        if ($latestVersion !== false) {
            static::$latestVersion = (float) trim($latestVersion);
        } else {
            static::$latestVersion = false;
        }
    }

    public static function isDevelopmentVersion() : bool
    {
        return static::getSelfVersion() > static::getLatestVersion();
    }

}