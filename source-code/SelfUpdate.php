<?php

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
            static::$selfVersion = $version;
        } else {
            static::$selfVersion = false;
        }
    }

    private static function fetchLatestVersion()
    {
        $latestVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/version.txt';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $latestVersionUrl);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true );
        $content = trim(curl_exec($curl));
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode === 200  &&  $content) {
            static::$latestVersion = $content;
        } else {
            static::$latestVersion = false;
        }
    }

    public static function isDevelopmentVersion()
    {
        return static::getSelfVersion() > static::getLatestVersion();
    }

}