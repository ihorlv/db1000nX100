<?php

class Config
{
    const putYourOvpnFilesHere = 'put-your-ovpn-files-here';

    public static string $putYourOvpnFilesHerePath,
                         $mainConfigPath,
                         $overrideConfigPath;

    public static array  $data = [
                            'cpuUsageLimit'          => 100,
                            'ramUsageLimit'          => 100,
                            'networkUsageLimit'      => 100,
                            'oneSessionDuration'     => 900,
                            'vpnMaxConnectionsLimit' => 0,
                            'logsEnabled'            => 1
                         ];

    public static function constructStatic()
    {
        passthru('reset');
        static::$putYourOvpnFilesHerePath = '';
        static::processPutYourOvpnFilesHere();
        static::processConfigs();
    }

    private static function processPutYourOvpnFilesHere()
    {
        MainLog::log('Searching for "' . static::putYourOvpnFilesHere . '" directory');
        $dirs = getDirectoryFilesListRecursive('/media', [static::putYourOvpnFilesHere], [], [], true, false, true);

        if (count($dirs) === 0) {
            MainLog::log('"' . static::putYourOvpnFilesHere . '" directory not found. We recommended to create it', 2, 0, Mainlog::LOG_GENERAL_ERROR);
        } else {
            if (count($dirs) > 1) {
                sort($dirs);
                MainLog::log('Multiple "' . static::putYourOvpnFilesHere . '" directories found', 1, 0, Mainlog::LOG_GENERAL_ERROR);
                MainLog::log(implode("\n", $dirs), 2, 0, Mainlog::LOG_GENERAL_ERROR);
            }
            static::$putYourOvpnFilesHerePath = $dirs[0];
            MainLog::log('"' . static::putYourOvpnFilesHere . '" directory found at ' . static::$putYourOvpnFilesHerePath);
        }
    }

    private static function processConfigs()
    {
        if (static::$putYourOvpnFilesHerePath) {
            static::$mainConfigPath = static::$putYourOvpnFilesHerePath . '/db1000nX100-config.txt';
        } else {
            static::$mainConfigPath = __DIR__ . '/db1000nX100-config.txt';
        }

        if (! file_exists(static::$mainConfigPath)) {
            static::createDefaultConfig(static::$mainConfigPath);
        } else {
            static::loadConfig(static::$mainConfigPath);
        }
        MainLog::log('Main config file in ' .  static::$mainConfigPath, 2);

        if (static::$putYourOvpnFilesHerePath) {
            static::$overrideConfigPath = static::$putYourOvpnFilesHerePath . '/db1000nX100-config-override.txt';
        } else {
            static::$overrideConfigPath = __DIR__ . '/db1000nX100-config-override.txt';
        }
        static::loadConfig(static::$overrideConfigPath);
    }

    private static function loadConfig($path)
    {
        $config = @file_get_contents($path);
        if (! $config) {
            return;
        }

        $regExp = <<<PhpRegExp
                    #[^\s;]+=[^\s;]+#
                    PhpRegExp;

        if (preg_match_all(trim($regExp), $config, $matches) > 0) {
            for ($i = 0, $max = count($matches[0]); $i < $max; $i++) {
                $line = $matches[0][$i];
                $parts = mbExplode('=', $line);
                if (count($parts) === 2) {
                    $key   = $parts[0];
                    $value = $parts[1];
                    static::$data[$key] = $value;
                }
            }
        }
    }

    private static function createDefaultConfig($path)
    {
        $defaultConfigContent = '';
        foreach (static::$data as $key => $value) {
            $defaultConfigContent .= "$key=$value\n";
        }

        file_put_contents_secure($path, $defaultConfigContent);
    }

}
Config::constructStatic();