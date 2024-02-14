<?php

class DistressGetTargetsFile extends SFunctions {

    private static array $urls = [
        "https://raw.githubusercontent.com/crayfish-kissable-marrow/crayfish/master/31.json",
        "https://raw.githubusercontent.com/snoring-huddling-charred/snoring/master/31.json",
    ];

    private static object $getState,
                          $getStateClear;

    public static function constructStatic()
    {
        static::$getStateClear = (object) [
            'success' => false,
            'md5Current' => '',
            'md5Previous' => '',
            'path' => '',
            'changed' => false,
            'changedAt' => 0,
        ];

        static::$getState = static::$getStateClear;
    }

    public static function getDistressTargetsFile(string $localTargetsFilePath): object
    {
        $previousGetState = static::$getState;
        static::$getState = static::$getStateClear;
        static::$getState->md5Previous = $previousGetState->md5Current;
        static::$getState->path = $localTargetsFilePath;

        if (file_exists($localTargetsFilePath)) {
            unlink($localTargetsFilePath);
        }

        shuffle(static::$urls);

        foreach(static::$urls as $url) {
            $targets = static::httpGet($url);
            if (!$targets) {
                continue;
            }

            // ---

            static::$getState->success = true;

            file_put_contents_secure($localTargetsFilePath, $targets);
            chown($localTargetsFilePath, 'app-h');
            chgrp($localTargetsFilePath, 'app-h');

            static::$getState->md5Current = md5_file($localTargetsFilePath);
            static::$getState->changed =     static::$getState->md5Previous
                                             &&  static::$getState->md5Previous !== static::$getState->md5Current;

            if (static::$getState->changed) {
                static::$getState->changedAt = time();
            }

            break;
        }

        return static::$getState;
    }

    public static function lastGetStateOfDistressTargetsFile(): object
    {
        return static::$getState;
    }

}

DistressGetTargetsFile::constructStatic();