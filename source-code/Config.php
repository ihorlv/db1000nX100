<?php

require_once __DIR__ . '/common.php';

class Config
{
    const putYourOvpnFilesHere = 'put-your-ovpn-files-here';

    public static string $putYourOvpnFilesHerePath,
                         $mainConfigPath,
                         $overrideConfigPath;

    public static array $filesInMediaDir,
                        $data,
                        $dataDefault;

    public static function constructStatic()
    {
        static::$filesInMediaDir = [];
        static::$putYourOvpnFilesHerePath = '';
        static::$data = [];
        static::$dataDefault = [
            'dockerInteractiveConfiguration'        => 1,
            'cpuUsageLimit'                         => '100%',
            'ramUsageLimit'                         => '100%',
            'networkUsageLimit'                     => '100%',
            'logFileMaxSize'                        => 100,
            'fixedVpnConnectionsQuantity'           => 0,
            'oneSessionMinDuration'                 => 300,
            'oneSessionMaxDuration'                 => 600,
            'delayAfterSessionMinDuration'          => 10,
            'delayAfterSessionMaxDuration'          => 30,
            'initialDB1000nScale'                   => 0.05,
            'vboxAttackProtectedWebsitesPerSession' => 2
        ];

        static::processPutYourOvpnFilesHere();
        static::processConfigs();
    }

    private static function processPutYourOvpnFilesHere()
    {
        MainLog::log('Searching for "' . static::putYourOvpnFilesHere . '" directory');
        static::$filesInMediaDir = getFilesListOfDirectory('/media', true);

        $dirs = searchInFilesList(
            static::$filesInMediaDir,
            SEARCH_IN_FILES_LIST_MATCH_DIR_BASENAME + SEARCH_IN_FILES_LIST_RETURN_DIRS,
            preg_quote(static::putYourOvpnFilesHere)
        );

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
        MainLog::log('Main config file in ' .  static::$mainConfigPath, 2);

        static::upgradeConfig(static::$mainConfigPath);
        static::$data = static::readConfig(static::$mainConfigPath);

        static::$overrideConfigPath = mbDirname(static::$mainConfigPath) . '/db1000nX100-config-override.txt';
        if (file_exists(static::$overrideConfigPath)) {
            $overrideData = static::readConfig(static::$overrideConfigPath);
            static::$data = array_merge(static::$data, $overrideData);
            @unlink(static::$overrideConfigPath);
        }

    }

    private static function readConfig($path)
    {
        $config = @file_get_contents($path);
        if (! $config) {
            return;
        }

        $regExp = <<<PhpRegExp
                    #[^\s;]+=[^\s;]+#
                    PhpRegExp;

        $ret = [];

        if (preg_match_all(trim($regExp), $config, $matches) > 0) {
            for ($i = 0, $max = count($matches[0]); $i < $max; $i++) {
                $line = $matches[0][$i];
                $parts = mbExplode('=', $line);
                if (count($parts) === 2) {
                    $key   = $parts[0];
                    $value = $parts[1];
                    $ret [$key] = $value;
                }
            }
        }

        return $ret;
    }

    private static function writeConfig($path, $data)
    {
        $configContent = '';
        foreach ($data as $key => $value) {
            $configContent .= "$key=$value\n";
        }
        file_put_contents_secure($path, $configContent);
    }

    private static function upgradeConfig($path)
    {
        if (!file_exists($path)) {
            // Create new config from default values
            static::writeConfig($path, static::$dataDefault);
            return;
        }

        $data = static::readConfig($path);
        foreach ($data as $key => $value) {
            if (!isset(static::$dataDefault[$key])) {
                // remove obsolete value
                unset($data[$key]);
            }
        }

        $data = array_merge(static::$dataDefault, $data);
        static::writeConfig($path, $data);
    }

}
Config::constructStatic();
