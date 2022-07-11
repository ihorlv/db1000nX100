<?php

class MainLog
{
    const LOG_GENERAL                  = 1 << 0,
          LOG_GENERAL_ERROR            = 1 << 1,
          LOG_GENERAL_OTHER            = 1 << 2,
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
        self::LOG_GENERAL_OTHER => [
            'toScreen'      => true,
            'toScreenColor' => false,
            'toFile'        => true,
            'level'         => 0
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

    const logFileBasename       = 'db1000nX100-log.txt';
    const shortLogFileBasename  = 'db1000nX100-log-short.txt';
    public static string $logFilePath,
                         $logFileDir,
                         $shortLogFilePath;

    public static function constructStatic()
    {
        global $TEMP_DIR;
        static::$logFileDir = $TEMP_DIR;

        static::$logFilePath = static::$logFileDir . '/' . self::logFileBasename;
        @unlink(static::$logFilePath);

        static::$shortLogFilePath = static::$logFileDir . '/' . self::shortLogFileBasename;
        @unlink(static::$shortLogFilePath);

        Actions::addAction('AfterTerminateSession', [static::class, 'trimLog']);
    }

    public static function log($message = '', $newLinesInTheEnd = 1, $newLinesInTheBeginning = 0, $chanelId = self::LOG_GENERAL)
    {
        global $LOG_FILE_MAX_SIZE_MIB;

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

        if ($chanel['toFile']  &&  $LOG_FILE_MAX_SIZE_MIB) {

            try {
                if (! file_exists(static::$logFilePath)) {
                    file_put_contents_secure(static::$logFilePath, '');
                }

                if (! file_exists(static::$shortLogFilePath)) {
                    file_put_contents_secure(static::$shortLogFilePath, '');
                }

                $f = fopen(static::$logFilePath, 'a'); //opens file in append mode
                fwrite($f, $messageNoMarkup);
                fclose($f);

                if (
                        $chanelId === self::LOG_GENERAL
                    ||  $chanelId === self::LOG_GENERAL_ERROR
                ) {
                    $f = fopen(static::$shortLogFilePath, 'a'); //opens file in append mode
                    fwrite($f, $messageNoMarkup);
                    fclose($f);
                }

            } catch (\Exception $e) {
                echo "Failed to write to log file\n'";
            }

        } else if (!$LOG_FILE_MAX_SIZE_MIB) {
            @unlink(static::$logFilePath);
            @unlink(static::$shortLogFilePath);
        }

    }

    public static function moveLog($newLogFileDir)
    {

        $newLogFilePath = $newLogFileDir . '/' . self::logFileBasename;
        $result = @file_put_contents_secure($newLogFilePath, '');
        if ($result === false) {
            return false;
        }

        // ---

        @unlink($newLogFilePath);
        @copy(static::$logFilePath, $newLogFilePath);
        @unlink(static::$logFilePath);
        static::$logFilePath = $newLogFilePath;
        static::$logFileDir  = $newLogFileDir;

        // ---

        $newShortLogFilePath = static::$logFileDir . '/' . self::shortLogFileBasename;
        @unlink($newShortLogFilePath);
        @copy(static::$shortLogFilePath, $newShortLogFilePath);
        @unlink(static::$shortLogFilePath);
        static::$shortLogFilePath = $newShortLogFilePath;

        // ---

        static::log("Log to " . static::$logFilePath, 2);
        return true;
    }

    public static function trimLog()
    {
        global $LOG_FILE_MAX_SIZE_MIB;

        if (
               !$LOG_FILE_MAX_SIZE_MIB
            || !file_exists(static::$logFilePath)
        ) {
            return;
        }

        $logFileMaxSize = $LOG_FILE_MAX_SIZE_MIB * 1024 * 1024;
        $logFileSize = filesize(static::$logFilePath);
        if ($logFileSize < $logFileMaxSize) {
            return;
        }
        self::log('Trimming log', 1, 0, MainLog::LOG_GENERAL_OTHER);
        $trimChunkSize = intRound($logFileSize / 2);
        trimFileFromBeginning(static::$logFilePath, $trimChunkSize, true);
    }

    public static function newIteration()
    {
        file_put_contents_secure(static::$shortLogFilePath, '');
    }

}

MainLog::constructStatic();