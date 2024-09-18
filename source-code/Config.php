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
            'itArmyUserId'                          => 0,
            'dockerInteractiveConfiguration'        => 1,
            'cpuUsageGoal'                          => '80%',
            'ramUsageGoal'                          => '80%',
            'networkUsageGoal'                      => '80%',
            'eachVpnBandwidthMaxBurst'              => 0,
            'ignoreBundledFreeVpn'                  => 0,
            'logFileMaxSize'                        => 300,
            'fixedVpnConnectionsQuantity'           => 0,
            'vpnConnectionsQuantityPerCpu'          => 3,
            'vpnConnectionsQuantityPer1GibRam'      => 4,
            'oneSessionMinDuration'                 => 600,
            'oneSessionMaxDuration'                 => 900,
            'delayAfterSessionMinDuration'          => 15,
            'delayAfterSessionMaxDuration'          => 45,
            'vpnDisconnectTimeout'                  => 10,

            'db1000nEnabled'                        => 0,
            'initialDB1000nScale'                   => 0.05,
            'maxDb1000nScale'                       => 4,
            'db1000nUseProxyPool'                   => 0,
            'db1000nProxyInstancesPercent'          => '50%',
            'db1000nProxyPool'                      => 'https://raw.githubusercontent.com/mmpx12/proxy-list/master/socks5.txt',
            'db1000nDefaultProxyProtocol'           => 'socks5',

            'distressEnabled'                       => 1,
            'initialDistressScale'                  => 50,
            'maxDistressScale'                      => 10240,
            'distressUseProxyPool'                  => 1,
            'distressProxyConnectionsPercent'       => '30%',
            'distressUseUdpFlood'                   => 1,
            'distressUseTor'                        => 1,
            'distressSingleCpuCoreMode'             => 1,

            'showConsoleOutput'                     => 1,
            'encryptLogs'                           => 0,
            'encryptLogsPublicKey'                  => '',
            'telegramNotificationsEnabled'          => 0,
            'telegramNotificationsToUserId'         => 0,
            'telegramNotificationsAtHours'          => '0,8,12,16,20',
            'telegramNotificationsPlainMessages'    => 1,
            'telegramNotificationsAttachmentMessages' => 1,
            'X100InstanceTitle'                       => 'No name',

            'cleanSwapAfterSession'                 => 0
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
            SEARCH_IN_FILES_LIST_MATCH_BASENAME + SEARCH_IN_FILES_LIST_RETURN_DIRS,
            preg_quote(static::putYourOvpnFilesHere) . '$'
        );

        if (count($dirs) === 0) {
            MainLog::log('"' . static::putYourOvpnFilesHere . '" directory not found. We recommended to create it', 2, 0, Mainlog::LOG_GENERAL_ERROR);
        } else {
            if (count($dirs) > 1) {
                sort($dirs);
                MainLog::log('Multiple "' . static::putYourOvpnFilesHere . '" directories found', 1, 0, Mainlog::LOG_GENERAL_ERROR);
                MainLog::log(implode("\n", $dirs), 2, 0, Mainlog::LOG_GENERAL_ERROR);
            }
            static::$putYourOvpnFilesHerePath = mbTrimDir($dirs[0]);
            MainLog::log('"' . static::putYourOvpnFilesHere . '" directory found at ' . static::$putYourOvpnFilesHerePath);
        }
    }

    private static function processConfigs()
    {
        if (static::$putYourOvpnFilesHerePath) {
            static::$mainConfigPath = static::$putYourOvpnFilesHerePath . '/x100-config.txt';
        } else {
            static::$mainConfigPath = __DIR__ . '/x100-config.txt';
        }
        MainLog::log('Main config file in ' .  static::$mainConfigPath, 2);

        static::upgradeConfig(static::$mainConfigPath);
        static::$data = static::readConfig(static::$mainConfigPath);

        static::$overrideConfigPath = mbDirname(static::$mainConfigPath) . '/x100-config-override.txt';
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
                    #[^\s=;]+=[^\n\r;]+#
                    PhpRegExp;

        $ret = [];

        if (preg_match_all(trim($regExp), $config, $matches) > 0) {
            for ($i = 0, $max = count($matches[0]); $i < $max; $i++) {
                $line = $matches[0][$i];
                $parts = mbExplode('=', $line);
                if (count($parts) === 2) {
                    $key   = $parts[0];
                    $value = $parts[1];
                    $ret[$key] = $value;
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

        try {
            file_put_contents_secure($path, $configContent);
        } catch (Exception $e) {
            _die('Failed to write config file. Possibly read-only filesystem');
        }
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

    public static function filterOptionValueIntPercents($optionValue, $minInt = false, $maxInt = false, $minPercent = false, $maxPercent = false)
    {
        if (static::isOptionValueInPercents($optionValue)) {
            return static::filterOptionValuePercents($optionValue, $minPercent, $maxPercent);
        } else {
            return static::filterOptionValueInt($optionValue, $minInt, $maxInt);
        }
    }

    public static function filterOptionValuePercents($optionValue, $minPercent = false, $maxPercent = false)
    {
        if (static::isOptionValueInPercents($optionValue)) {
            $optionValueInt = (int) $optionValue;
            if (
                   ($minPercent !== false  &&  $optionValueInt < $minPercent)
                || ($maxPercent !== false  &&  $optionValueInt > $maxPercent)
            ) {
                return null;
            } else {
                return $optionValueInt . '%';
            }
        } else {

            if (
                    $minPercent === 0
                &&  intval($optionValue) === 0
            ) {
                return '0%';
            } else {
                return null;
            }

        }
    }

    public static function filterOptionValueInt($optionValue, $minInt = false, $maxInt = false)
    {
        if (static::isOptionValueInPercents($optionValue)) {
            return null;
        } else {
            $optionValueInt = (int) $optionValue;
            if (
                   ($minInt !== false  &&  $optionValueInt < $minInt)
                || ($maxInt !== false  &&  $optionValueInt > $maxInt)
            ) {
                return null;
            } else {
                return $optionValueInt;
            }
        }
    }

    public static function filterOptionValueFloat($optionValue, $minFloat = false, $maxFloat = false)
    {
        if (static::isOptionValueInPercents($optionValue)) {
            return null;
        } else {
            $optionValueFloat = (float) $optionValue;
            if (
                   ($minFloat !== false  &&  $optionValueFloat < $minFloat)
                || ($maxFloat !== false  &&  $optionValueFloat > $maxFloat)
            ) {
                return null;
            } else {
                return $optionValueFloat;
            }
        }
    }

    public static function filterOptionValueBoolean($optionValue)
    {
        if (static::isOptionValueInPercents($optionValue)) {
            return null;
        } else {
            return filter_var($optionValue, FILTER_VALIDATE_BOOLEAN);
        }
    }

    public static function isOptionValueInPercents($optionValue) : bool
    {
        return mb_substr(mbTrim($optionValue), -1) === '%';
    }
}

Config::constructStatic();
