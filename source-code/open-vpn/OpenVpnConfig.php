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

            $inUse,
            $useCount,
            $lastUsedAt,
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

        $this->inUse = false;
        $this->useCount = 0;
        $this->lastUsedAt = 0;
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

    public function isInUse()
    {
        return $this->inUse;
    }

    public function lock()
    {
        if ($this->inUse) {
            _die("Config {$this->ovpnFile} already locked");
        }

        $this->inUse = true;
        $this->useCount++;
        $this->lastUsedAt = time();
    }

    public function unlock()
    {
        $this->inUse = false;
    }

    public function logSuccess($publicIp = null)
    {
        $this->successfulConnectionsCount++;
        if ($publicIp  &&  !in_array($publicIp, $this->uniqueIPsPool)) {
            $this->uniqueIPsPool[] = $publicIp;
            $this->uniqueIPsPool = array_unique($this->uniqueIPsPool);
        }
    }

    public function logFail()
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

    public function isBadConfig()
    {
        if (!$this->useCount) {
            return false;
        }

        return     $this->useCount >= 5
               &&  $this->successfulConnectionsCount / $this->useCount < 0.2
               &&  time() - $this->lastUsedAt  <  12 * 60 * 60;
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