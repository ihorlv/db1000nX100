<?php

class OpenVpnProvider  /* Model */
{
    private $name,
            $dir,
            $settingsFile,
            $settings,
            $openVpnConfigs,
            $usedOpenVpnConfigs;

    const   dockerOvpnRoot = 'put-your-ovpn-files-here';

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

    public function getDir()
    {
        return $this->dir;
    }

    public function getSetting($settingName)
    {
        return $this->settings[$settingName] ?? null;
    }

    public function addOpenVpnConfig(OpenVpnConfig $ovpnConfig)
    {
        $this->openVpnConfigs[$ovpnConfig->getId()] = $ovpnConfig;
    }

    public function getAllOpenVpnConfigs()
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

    public static function constructStatic()
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

        static::moveLogToOvpnDirectory();
    }

    private function moveLogToOvpnDirectory()
    {
        foreach (static::$openVpnProviders as $openVpnProvider) {
            $ovpnDir = $openVpnProvider->getDir();
            $pathParts = mbExplode('/', $ovpnDir);
            foreach ($pathParts as $i => $pathPart) {
                if ($pathPart === static::dockerOvpnRoot) {
                    $pathPartToHere = array_slice($pathParts, 0, $i + 1);
                    $pathToHere = implode('/', $pathPartToHere);
                    //echo "$pathToHere\n";
                    if (MainLog::moveLog($pathToHere)) {
                        return;
                    } else {
                        break;
                    }
                }
            }
        }

        foreach (static::$openVpnProviders as $openVpnProvider) {
            $parentDir = mbDirname($openVpnProvider->getDir());
            if (MainLog::moveLog($parentDir)) {
                return;
            }
        }

        foreach (static::$openVpnProviders as $openVpnProvider) {
            if (MainLog::moveLog($openVpnProvider->getDir())) {
                return;
            }
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

            $openVpnConfigs = $openVpnProvider->getAllOpenVpnConfigs();
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

    public static function hasFreeOpenVpnConfig()
    {
        foreach (static::$openVpnProviders as $openVpnProvider) {
            $maxConnections = intval($openVpnProvider->getSetting('max_connections') ?? PHP_INT_MAX);
            if ($openVpnProvider->countUsedOpenVpnConfigs() < $maxConnections) {
                return true;
            }
        }
        return false;
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
        $credentialsFileBasenameMutations = [
            static::credentialsFileBasename,
            mbFilename(static::credentialsFileBasename),
            static::credentialsFileBasename . '.' . mbExt(static::credentialsFileBasename)
        ];

        foreach ($credentialsFileBasenameMutations as $credentialsFileBasenameMutation) {
            $path = $dir . '/' . $credentialsFileBasenameMutation;
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    private static function findProviderSettingsFileInDir($dir)
    {
        $providerSettingsFileBasenameMutations = [
            static::providerSettingsFileBasename,
            mbFilename(static::providerSettingsFileBasename),
            static::providerSettingsFileBasename . '.' . mbExt(static::providerSettingsFileBasename)
        ];

        foreach ($providerSettingsFileBasenameMutations as $providerSettingsFileBasenameMutation) {
            $path = $dir . '/' . $providerSettingsFileBasenameMutation;
            if (file_exists($path)) {
                return $path;
            }
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
}

OpenVpnProvider::constructStatic();