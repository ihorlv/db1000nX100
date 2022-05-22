<?php

class MainLog
{
    const LOG_GENERAL                  = 1,
          LOG_GENERAL_ERROR            = 1 << 1,
          LOG_GENERAL_STATISTICS       = 1 << 2,
          LOG_PROXY                    = 1 << 3,
          LOG_PROXY_ERROR              = 1 << 4,
          LOG_HACK_APPLICATION         = 1 << 5,
          LOG_HACK_APPLICATION_ERROR   = 1 << 6,
          LOG_DEBUG                    = 1 << 7,
          LOG_NONE                     = 1 << 8;

    const chanels = [
        
        self::LOG_GENERAL => [
            'toScreen'      => true,
            'toScreenColor' => false,
            'toFile'        => true,
            'level'         => 0
        ],
        self::LOG_GENERAL_ERROR => [
            'toScreen'      => true,
            'toScreenColor' => Term::red,
            'toFile'        => true,
            'level'         => 0
        ],
        self::LOG_GENERAL_STATISTICS => [
            'toScreen'      => true,
            'toScreenColor' => false,
            'toFile'        => true,
            'level'         => 2
        ],
        self::LOG_PROXY => [
            'toScreen'      => true,
            'toScreenColor' => false,
            'toFile'        => true,
            'level'         => 4
        ],
        self::LOG_PROXY_ERROR => [
            'toScreen'      => true,
            'toScreenColor' => Term::red,
            'toFile'        => true,
            'level'         => 1
        ],
        self::LOG_HACK_APPLICATION => [
            'toScreen'      => true,
            'toScreenColor' => false,
            'toFile'        => true,
            'level'         => 3
        ],
        self::LOG_HACK_APPLICATION_ERROR => [
            'toScreen'      => true,
            'toScreenColor' => Term::red,
            'toFile'        => true,
            'level'         => 1
        ],
        self::LOG_DEBUG=> [
            'toScreen'      => true,
            'toScreenColor' => Term::gray,
            'toFile'        => true,
            'level'         => 0
        ],
        self::LOG_NONE => [
            'toScreen'      => false,
            'toScreenColor' => false,
            'toFile'        => false,
            'level'         => 0
        ]
    ];

    const logFileBasename = 'db1000nX100-log.txt';
    public static string $logFilePath,
                         $logFileDir;
    public static int    $maxLogSize;

    public static function constructStatic()
    {
        global $TEMP_DIR;
        static::$logFileDir = $TEMP_DIR;
        static::$logFilePath = static::$logFileDir . '/' . self::logFileBasename;
        static::$maxLogSize = (SelfUpdate::isDevelopmentVersion()  ?  500 : 50) * 1024 * 1024;
    }

    public static function log($message = '', $newLinesInTheEnd = 1, $newLinesInTheBeginning = 0, $chanelId = self::LOG_GENERAL)
    {
        global $LOGS_ENABLED;

        $message = str_repeat("\n", $newLinesInTheBeginning) . $message . str_repeat("\n", $newLinesInTheEnd);
        $messageNoMarkup = Term::removeMarkup($message);

        if (! $message) {
            return;
        }

        if ($chanelId === MainLog::LOG_DEBUG  &&  !SelfUpdate::isDevelopmentVersion()) {
            return;
        }

        $chanel = self::chanels[$chanelId];

        if ($chanel['toScreen']) {
            if ($chanel['toScreenColor']) {
                echo $chanel['toScreenColor'];
                echo $messageNoMarkup;
                echo Term::clear;
            } else {
                echo Term::clear;
                echo $message;
            }
        }

        if ($chanel['toFile']  &&  $LOGS_ENABLED) {
            try {
                if (! file_exists(static::$logFilePath)) {
                    file_put_contents_secure(static::$logFilePath, '');
                }
                $f = fopen( static::$logFilePath, 'a');//opens file in append mode
                fwrite($f, $messageNoMarkup);
                fclose($f);
            } catch (\Exception $e) {
                echo "Failed to write to log file\n'";
            }

        }
    }

    public static function moveLog($newLogFileDir)
    {
        $newLogFilePath = $newLogFileDir . '/' . self::logFileBasename;
        $result = @file_put_contents_secure($newLogFilePath, '');
        if ($result === false) {
            return false;
        }
        @copy(static::$logFilePath, $newLogFilePath);
        @unlink(static::$logFilePath);
        static::$logFilePath = $newLogFilePath;
        static::$logFileDir  = $newLogFileDir;

        static::log("Log to " . static::$logFilePath, 2);
        return true;
    }

    public static function trimLog()
    {
        if (filesize(static::$logFilePath) < self::$maxLogSize) {
            return;
        }
        self::log('Trimming log');
        $trimChunkSize = round(self::$maxLogSize * 0.4);
        trimFileFromBeginning(static::$logFilePath, $trimChunkSize, true);
    }
}

MainLog::constructStatic();