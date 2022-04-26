<?php

class OpenVpnProvider  /* Model */
{
    private $name,
            $dir,
            $settingsFile,
            $settings,
            $openVpnConfigs,
            $usedOpenVpnConfigs;

    public function __construct($name, $dir, $settingsFile)
    {
        $this->name = $name;
        $this->dir = $dir;
        $this->settingsFile = $settingsFile;
        $this->settings = static::parseProviderSettingsFile($settingsFile);
        $this->openVpnConfigs = [];
        $this->usedOpenVpnConfigs = [];
    }

    public function getName()
    {
        return $this->name;
    }

    public function getSetting($settingName)
    {
        return $this->settings[$settingName] ?? null;
    }

    public function addOpenVpnConfig(OpenVpnConfig $ovpnConfig)
    {
        $this->openVpnConfigs[$ovpnConfig->getId()] = $ovpnConfig;
    }

    public function getOpenVpnConfigs()
    {
        return $this->openVpnConfigs;
    }

    public function useOpenVpnConfig(OpenVpnConfig $ovpnConfig)
    {
        $this->usedOpenVpnConfigs[$ovpnConfig->getId()] = $ovpnConfig;
    }

    public function unUseOpenVpnConfig(OpenVpnConfig $ovpnConfig)
    {
        unset($this->usedOpenVpnConfigs[$ovpnConfig->getId()]);
    }

    public function countUsedOpenVpnConfigs()
    {
        return count($this->usedOpenVpnConfigs);
    }

    public function isOpenVpnConfigInUse(OpenVpnConfig $ovpnConfig)
    {
        return isset($this->usedOpenVpnConfigs[$ovpnConfig->getId()]);
    }

    //-------------------------------------------------------------

    const credentialsFileBasename       = 'credentials.txt',
          providerSettingsFileBasename  = 'vpn-provider-config.txt';

    public static $openVpnProviders;

    public static function initStatic()
    {
        static::$openVpnProviders = [];

        $ovpnFiles = getDirectoryFilesListRecursive('/media', 'ovpn');
        $ovpnFilesCount = count($ovpnFiles);
        if (! $ovpnFilesCount) {
            _die("NO *.ovpn files found in Shared Folders\n"
                . "Add a share folder with ovpn files and reboot this virtual machine");
        }

        foreach ($ovpnFiles as $ovpnFile) {
            $everything = static::getEverythingAboutOvpnFile($ovpnFile);
            $providerName = $everything['providerName'];

            $openVpnProvider = static::$openVpnProviders[$providerName] ?? null;
            if (! $openVpnProvider) {
                $openVpnProvider = new OpenVpnProvider($providerName, $everything['providerDir'], $everything['providerSettingsFile']);
                static::$openVpnProviders[$providerName] = $openVpnProvider;
            }

            $openVpnConfig = new OpenVpnConfig($everything['ovpnFile'], $everything['credentialsFile'], $openVpnProvider);
            $openVpnProvider->addOpenVpnConfig($openVpnConfig);
        }
    }

    public static function holdRandomOpenVpnConfig()
    {
        $openVpnProviders = static::$openVpnProviders;
        shuffle($openVpnProviders);
        foreach ($openVpnProviders as $openVpnProvider) {

            // Check if max_connections reached
            $maxConnections = intval($openVpnProvider->getSetting('max_connections') ?? PHP_INT_MAX);
            if ($openVpnProvider->countUsedOpenVpnConfigs() >= $maxConnections) {
                continue;
            }

            $openVpnConfigs = $openVpnProvider->getOpenVpnConfigs();
            shuffle($openVpnConfigs);
            foreach ($openVpnConfigs as $openVpnConfig) {
                if ($openVpnProvider->isOpenVpnConfigInUse($openVpnConfig)) {
                    continue;
                }

                $openVpnProvider->useOpenVpnConfig($openVpnConfig);
                return $openVpnConfig;
            }
        }

        return -1;
    }

    public static function releaseOpenVpnConfig(OpenVpnConfig $openVpnConfig)
    {
        $openVpnProvider = $openVpnConfig->getProvider();
        $openVpnProvider->unUseOpenVpnConfig($openVpnConfig);
    }

    private static function getEverythingAboutOvpnFile($ovpnFile)
    {
        $ret = [
            'ovpnFile'             => $ovpnFile,
            'credentialsFile'      => false,
            'providerDir'          => false,
            'providerSettingsFile' => false,
            'providerName'         => false
        ];

        //---

        $credentialsFile = static::findCredentialsFileInDir(mbDirname($ovpnFile));
        if (! $credentialsFile) {
            // Not found in same dir. Check in parent dir
            $credentialsFile = static::findCredentialsFileInDir(mbDirname(mbDirname($ovpnFile)));;
        }
        if (! $credentialsFile) {
            // Not found in parent dir
            $credentialsFile = null;
        }

        $ret['credentialsFile'] = $credentialsFile;

        //---

        $providerDir = mbDirname($ovpnFile);
        $providerSettingsFile = static::findProviderSettingsFileInDir($providerDir);
        if (! $providerSettingsFile) {
            // Provider setting file not found in same dir. Check in parent dir
            $providerSettingsFile = static::findProviderSettingsFileInDir(mbDirname($providerDir));
            if ($providerSettingsFile) {
                // Provider settings file found in parent dir
                $providerDir = mbDirname($providerDir);
            } else {
                // Provider settings file not found
                $ovpnFilesCountInDir = count(getDirectoryFilesListRecursive($providerDir, 'ovpn'));
                if ($ovpnFilesCountInDir === 1) {
                    // Only one ovpn file in dir. Likely in provider dir there are separate sub dirs for each ovpn file
                    $providerDir = mbDirname($providerDir);
                }
            }
        }

        $ret['providerDir'] = $providerDir;
        $ret['providerSettingsFile'] = $providerSettingsFile;
        $ret['providerName'] = mbBasename($providerDir);

        return $ret;
    }

    private static function findCredentialsFileInDir($dir)
    {
        $path = $dir . '/' . self::credentialsFileBasename;
        if (file_exists($path)) {
            return $path;
        }
        $path = $dir . '/' . self::credentialsFileBasename . '.' . mbExt(self::credentialsFileBasename);
        if (file_exists($path)) {
            return $path;
        }
        return false;
    }

    private static function findProviderSettingsFileInDir($dir)
    {
        $path = $dir . '/' . self::providerSettingsFileBasename;
        if (file_exists($path)) {
            return $path;
        }
        $path = $dir . '/' . self::providerSettingsFileBasename . '.' . mbExt(self::providerSettingsFileBasename);
        if (file_exists($path)) {
            return $path;
        }
        return false;
    }

    private static function parseProviderSettingsFile($settingsFile)
    {
        $providerSettingsStr = @file_get_contents($settingsFile);
        $settingsRegExp = <<<PhpRegExp
                              #^([^=]+)=(.*)$#um
                              PhpRegExp;
        if (preg_match_all(trim($settingsRegExp), $providerSettingsStr, $matches) < 1) {
            return [];
        }

        $ret = [];
        for ($i = 0; $i < count($matches[0]); $i++) {
            $key   = mbTrim($matches[1][$i]);
            $value = mbTrim($matches[2][$i]);
            $ret[$key] = $value;
        }
        return $ret;
    }

    public static function getStatistics()
    {
        $ret = '';
        foreach (static::$openVpnProviders as $openVpnProvider) {
            $openVpnConfigs = $openVpnProvider->getOpenVpnConfigs();
            foreach ($openVpnConfigs as $openVpnConfig) {

            }
        }
    }
}