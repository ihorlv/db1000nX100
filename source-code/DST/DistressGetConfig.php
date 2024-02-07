<?php

class DistressGetConfig extends SFunctions
{
    private static array $urlsByType = [
        'config' => [
            'https://raw.githubusercontent.com/Yneth/distress-releases/main/config4.txt'
        ],
        'proxies' => [
            'https://github.com/Yneth/funny/raw/main/01.txt'
        ]
    ];

    public static function fetchDistressConfig($path, $type): bool
    {
        $urls = static::$urlsByType[$type] ?? false;
        if (!$urls) {
            return false;
        }

        shuffle($urls);
        $url = $urls[0];

        $configContent = static::httpGet($url);
        if ($configContent === false) {
            return false;
        }

        file_put_contents_secure($path, $configContent);
        chown($path, 'app-h');
        chgrp($path, 'app-h');
        return true;
    }
}