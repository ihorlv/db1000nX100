<?php

class MainLog
{
    const LOG_GENERAL                  = 1,
          LOG_GENERAL_ERROR            = 1 << 1,
          LOG_GENERAL_STATISTIC        = 1 << 2,
          LOG_PROXY                    = 1 << 3,
          LOG_PROXY_ERROR              = 1 << 4,
          LOG_HACK_APPLICATION         = 1 << 5,
          LOG_HACK_APPLICATION_ERROR   = 1 << 6;

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
        self::LOG_GENERAL_STATISTIC => [
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
        ]
    ];

    const logFileBasename = 'db1000nX100-log.txt';
    public static string $logFilePath;
    public static int    $maxLogSize;

    public static function constructStatic()
    {
        global $TEMP_DIR;
        static::$logFilePath = $TEMP_DIR . '/' . self::logFileBasename;
        static::$maxLogSize = (SelfUpdate::isDevelopmentVersion()  ?  500 : 50) * 1024 * 1024;
    }

    public static function log($message = '', $chanelId = self::LOG_GENERAL, $newLinesInTheEnd = 1, $newLinesInTheBeginning = 0)
    {
        $message = str_repeat("\n", $newLinesInTheBeginning) . $message . str_repeat("\n", $newLinesInTheEnd);

        if (! $message) {
            return;
        }
        $chanel = self::chanels[$chanelId];

        if ($chanel['toScreen']) {
            echo Term::clear;
            if ($chanel['toScreenColor']) {
                echo $chanel['toScreenColor'];
            }

            echo $message;
            echo Term::clear;
        }

        if ($chanel['toFile']) {
            try {
                if (! file_exists(static::$logFilePath)) {
                    file_put_contents_secure(static::$logFilePath, '');
                }
                $f = fopen( static::$logFilePath, 'a');//opens file in append mode
                fwrite($f, Term::removeMarkup($message));
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

        passthru('reset');  // Clear console
        echo "Logging to " . static::$logFilePath . "\n";
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