<?php

class OpenVpnConfig /* Model */
{
    private $id,
            $ovpnFile,
            $ovpnFileName,
            $ovpnFileBasename,
            $ovpnFileSubPath,
            $credentialsFile,
            $provider,
            $useCount,
            $successfulConnectionsCount,
            $failedConnectionsCount,
            $uniqueIPsPool,
            $scores;

    public function __construct($ovpnFile, $credentialsFile, $provider)
    {
        static::$newId++;
        $this->id = static::$newId;
        $this->ovpnFile = $ovpnFile;
        $this->ovpnFileName = mbFilename($this->ovpnFile);
        $this->ovpnFileBasename = mbBasename($this->ovpnFile);
        $this->ovpnFileSubPath = mbPathWithoutRoot($this->ovpnFile, $provider->getDir(), true);
        $this->credentialsFile = $credentialsFile;
        $this->provider = $provider;
        $this->useCount = 0;
        $this->successfulConnectionsCount = 0;
        $this->failedConnectionsCount = 0;
        $this->uniqueIPsPool = [];
        $this->scores = [];
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

    public function getOvpnFileSubPath()
    {
        return $this->ovpnFileSubPath;
    }

    public function getCredentialsFile()
    {
        return $this->credentialsFile;
    }

    public function logUse()
    {
        $this->useCount++;
    }

    public function logConnectionSuccess($publicIp = null)
    {
        $this->successfulConnectionsCount++;
        if ($publicIp  &&  !in_array($publicIp, $this->uniqueIPsPool)) {
            $this->uniqueIPsPool[] = $publicIp;
        }
    }

    public function logConnectionFail()
    {
        $this->failedConnectionsCount++;
    }

    public function getSuccessfulConnectionsCount()
    {
        return $this->successfulConnectionsCount;
    }

    public function getFailedConnectionsCount()
    {
        return $this->failedConnectionsCount;
    }

    public function setCurrentSessionScorePoints($score)
    {
        global $SESSIONS_COUNT;
        if ($score) {
            $this->scores[$SESSIONS_COUNT] = $score;
            $this->scores = array_slice($this->scores, -100, null, true);
        }
    }

    public function getAverageScorePoints()
    {
        if (count($this->scores)) {
            $averageScore = intRound(array_sum($this->scores) / count($this->scores));
        } else {
            $averageScore = 0;
        }

        return $averageScore;
    }

    public function getUniqueIPsPool()
    {
        return $this->uniqueIPsPool;
    }

    //------------------------------------------------------------------

    public static $newId = -1;
}