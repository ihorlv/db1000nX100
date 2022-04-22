<?php

class OpenVpnConfig /* Model */
{
    private $id,
            $ovpnFile,
            $ovpnFileName,
            $ovpnFileBasename,
            $credentialsFile,
            $provider,
            $useCount,
            $successConnectionsCount,
            $failedConnectionsCount,
            $hadPublicIPs;

    public function __construct($ovpnFile, $credentialsFile, $provider)
    {
        static::$newId++;
        $this->id = static::$newId;
        $this->ovpnFile = $ovpnFile;
        $this->ovpnFileName = mbFilename($this->ovpnFile);
        $this->ovpnFileBasename = mbBasename($this->ovpnFile);
        $this->credentialsFile = $credentialsFile;
        $this->provider = $provider;
        $this->useCount = 0;
        $this->successConnectionsCount = 0;
        $this->failedConnectionsCount = 0;
        $this->hadPublicIPs = [];
    }

    public function getId()
    {
        return $this->id;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function getOvpnFile()
    {
        return $this->ovpnFile;
    }

    public function getOvpnFileName()
    {
        return $this->ovpnFileName;
    }

    public function getOvpnFileBasename()
    {
        return $this->ovpnFileBasename;
    }

    public function getCredentialsFile()
    {
        return $this->credentialsFile;
    }

    public function logUse()
    {
        $this->useCount++;
    }

    public function logConnectionFinish($connectionSuccess, $publicIp)
    {
        if ($connectionSuccess) {
            $this->successConnectionsCount++;
        } else {
            $this->failedConnectionsCount++;
        }

        if ($publicIp) {
            $this->hadPublicIPs[] = $publicIp;
        }
    }

    //------------------------------------------------------------------

    public static $newId = -1;
}