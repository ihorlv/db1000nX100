<?php

class OpenVpnConfig /* Model */
{
    const credentialsFileBasename     = 'credentials.txt',
          providerSettingsFileBasename  = 'vpn-provider-config.txt';

    public $id,
           $ovpnFile,
           $credentialsFile,
           $provider,
           $providerDir,
           $providerSettingsFile,
           $inUse,
           $successCount,
           $failedCount;

    public function __construct($id, $ovpnFile)
    {
        $this->id = $id;
        $this->ovpnFile = $ovpnFile;

        //---

        $credentialsFile = mbDirname($this->ovpnFile) . '/' . OpenVpnConfig::credentialsFileBasename;
        if (! file_exists($credentialsFile)) {
            // Not found in same dir. Check in parent dir
            $credentialsFile = mbDirname(mbDirname($this->ovpnFile)) . '/' . OpenVpnConfig::credentialsFileBasename;
        }
        if (! file_exists($credentialsFile)) {
            // Not found in parent dir
            $credentialsFile = null;
        }
        $this->credentialsFile = $credentialsFile;

        //---

        $providerDir = mbDirname($this->ovpnFile);
        $providerSettingsFile = $providerDir . '/' . OpenVpnConfig::providerSettingsFileBasename;
        if (! file_exists($providerSettingsFile)) {
            // Provider setting not found in same dir. Check in parent dir
            $providerSettingsFile = mbDirname($providerDir) . '/' . OpenVpnConfig::providerSettingsFileBasename;
            if (file_exists($providerSettingsFile)) {
                // Provider settings found in parent dir
                $providerDir = mbDirname($providerDir);
            } else {
                // Provider config not found
                $providerSettingsFile = null;
                $ovpnFilesCountInDir = count(getDirectoryFilesListRecursive($providerDir, 'ovpn'));
                if ($ovpnFilesCountInDir === 1) {
                    // Only one ovpn file in dir. Likely in provider dir there are separate sub dirs for each ovpn file
                    $providerDir = mbDirname($providerDir);
                }
            }
        }
        $this->providerDir = $providerDir;
        $this->providerSettingsFile = $providerSettingsFile;
        $this->provider = mbBasename($providerDir);

        //---


    }

    private function parseProviderSettingsFile()
    {
        $providerSettingsStr = file_get_contents($this->providerSettingsFile);
        $providerSettingsLines = mbSplitLines($providerSettingsStr);
    }

    public function use()
    {
        if ($this->inUse) {
            return false;
        }
        $usePerProvider = $usePerProviders[$this->provider] ?? 0;

        $usePerProvider++;
        $usePerProviders[$this->provider] = $usePerProvider;
    }

    public function unUse($success)
    {

    }

    //------------------------------------------------------------------

    public static $configs,
                  $usePerProviders,
                  $providerSettings;

    public static function initStatic()
    {
        $ovpnFiles = getDirectoryFilesListRecursive('/media', 'ovpn');
        $ovpnFilesCount = count($ovpnFiles);
        if (! $openVpnovpnFilesCount) {
            _die("NO *.ovpn files found in Shared Folders\n"
                . "Add a share folder with ovpn files and reboot this virtual machine");
        }

        $id = -1;
        foreach ($openVpnovpnFiles as $openVpnovpnFile) {
            $id++;
            $configModel = new OpenVpnConfig($id, $openVpnConfigFile);
            static::$configs[$id] = $configModel;
        }

        print_r(static::$configs);


    }
}