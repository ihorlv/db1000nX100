<?php

class MainLog
{
    const LOG_GENERAL                  =      0,
          LOG_GENERAL_ERROR            = 1 << 1,
          LOG_GENERAL_OTHER            = 1 << 2,
          LOG_PROXY                    = 1 << 3,
          LOG_PROXY_ERROR              = 1 << 4,
          LOG_HACK_APPLICATION         = 1 << 5,
          LOG_HACK_APPLICATION_ERROR   = 1 << 6,

          LOG_DEBUG                    = 1 << 20,
          LOG_NONE                     = 1 << 21;

    const chanels = [
        
        self::LOG_GENERAL => [
            'level'         => 0
        ],
        self::LOG_GENERAL_ERROR => [
            'toScreenColor' => Term::red,
            'level'         => 0
        ],
        self::LOG_GENERAL_OTHER => [
            'level'         => 1
        ],
        self::LOG_PROXY => [
            'level'         => 2
        ],
        self::LOG_PROXY_ERROR => [
            'toScreenColor' => Term::red,
            'level'         => 1
        ],
        self::LOG_HACK_APPLICATION => [
            'level'         => 2
        ],
        self::LOG_HACK_APPLICATION_ERROR => [
            'toScreenColor' => Term::red,
            'level'         => 1
        ],
    ];

    const logFileBasename       = 'x100-log.txt';
    const shortLogFileBasename  = 'x100-log-short.txt';
    const encryptChunkSize      = 2048 / 8 - 11;   // 2048 is the key length in bits
    public static string $logFilePath,
                         $logFileDir,
                         $shortLogFilePath;

    public static function constructStatic()
    {
        global $TEMP_DIR, $SHOW_CONSOLE_OUTPUT;

        $SHOW_CONSOLE_OUTPUT = true;
        static::$logFileDir = $TEMP_DIR;

        static::$logFilePath = static::$logFileDir . '/' . self::logFileBasename;
        @unlink(static::$logFilePath);

        static::$shortLogFilePath = static::$logFileDir . '/' . self::shortLogFileBasename;
        @unlink(static::$shortLogFilePath);

        Actions::addAction('DelayAfterSession', [static::class, 'trimLog']);

        set_error_handler('MainLog::catchPHPErrors');
    }

    public static function log($message = '', $newLinesInTheEnd = 1, $newLinesInTheBeginning = 0, $chanelId = MainLog::LOG_GENERAL)
    {
        global $LOG_FILE_MAX_SIZE_MIB, $SHOW_CONSOLE_OUTPUT, $ENCRYPT_LOGS, $ENCRYPT_LOGS_PUBLIC_KEY;

        if ($chanelId  &  MainLog::LOG_NONE) {
            return;
        }

        $message = str_repeat("\n", $newLinesInTheBeginning) . $message . str_repeat("\n", $newLinesInTheEnd);
        $messageNoMarkup = $messageToFile = Term::removeMarkup($message);

        if ($ENCRYPT_LOGS) {
            $messageSplitArray = str_split($messageToFile, static::encryptChunkSize);
            $messageToFile = '';
            foreach ($messageSplitArray as $messagePart) {
                if (openssl_public_encrypt($messagePart, $messagePartEncrypted, $ENCRYPT_LOGS_PUBLIC_KEY)) {
                    $messageToFile .= '!!!!!:' . base64_encode($messagePartEncrypted) . "\n";
                }
            }
        }

        if ($chanelId  &  MainLog::LOG_DEBUG) {
            $chanelId    -= MainLog::LOG_DEBUG;
            $showOnScreen = false;               //SelfUpdate::$isDevelopmentVersion;
        } else if ($SHOW_CONSOLE_OUTPUT) {
            $showOnScreen = true;
        } else {
            $showOnScreen = false;
        }

        $chanelSettings = static::chanels[$chanelId];

        if ($showOnScreen) {
            $toScreenColor = $chanelSettings['toScreenColor'] ?? false;

            if ($toScreenColor) {
                echo $toScreenColor;
                echo $messageNoMarkup;
                echo Term::clear;
            } else {
                echo Term::clear;
                echo $message;
            }
        }

        if ($LOG_FILE_MAX_SIZE_MIB) {

            try {

                if (! file_exists(static::$logFilePath)) {
                    file_put_contents_secure(static::$logFilePath, '');
                }

                // ---

                if (! file_exists(static::$shortLogFilePath)) {
                    file_put_contents_secure(static::$shortLogFilePath, '');
                }

                // ---

                $f = fopen(static::$logFilePath, 'a'); //opens file in append mode
                fwrite($f, $messageToFile);
                fclose($f);

                if ($chanelSettings['level'] === MainLog::LOG_GENERAL) {
                    $f = fopen(static::$shortLogFilePath, 'a'); //opens file in append mode
                    fwrite($f, $messageToFile);
                    fclose($f);
                }

            } catch (\Exception $e) {
                echo "Failed to write to the log file\n'";
            }

        } else {
            @unlink(static::$logFilePath);
            @unlink(static::$shortLogFilePath);
        }

    }

    public static function catchPHPErrors($severity, $message, $filename, $lineno) {

        switch ($severity) {
            case E_ERROR:
                $severityStr = 'Error';
                break;

            case E_WARNING:
                $severityStr = 'Warning';
                break;

            case E_NOTICE:
                $severityStr = 'Notice';
                break;

            default:
                $severityStr = 'E_' . $severity;
                break;
        }

        MainLog::log(
              "[PHP $severityStr] "
            . "\"$message\" "
            . "in $filename:$lineno"
        );

        if (error_reporting() == 0) {
            return;
        }
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $filename, $lineno);
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
        static::rotateLog($newShortLogFilePath);

        if (file_exists($newShortLogFilePath)) {
            unlink($newShortLogFilePath);
        }

        @copy(static::$shortLogFilePath, $newShortLogFilePath);

        if (file_exists(static::$shortLogFilePath)) {
            unlink(static::$shortLogFilePath);
        }

        static::$shortLogFilePath = $newShortLogFilePath;

        // ---

        static::log("Log to " . static::$logFilePath, 2);
        return true;
    }

    public static function rotateLog($path)
    {
        if (file_exists($path)) {
            $f = fopen($path, 'r');
            $content = fread($f, 4096);
            fclose($f);

            preg_match('#Starting 1 session at (.*?)$#mu', $content, $matches);
            if (isset($matches[1])) {
                $date = $matches[1];
                $date = mbStrReplace(':', '', $date);
                $date = mbStrReplace(' ', '-', $date);
            } else {
                $date = date('Y-m-d-His');
            }

            $rotatePath = mbPathWithoutExt($path) . '___' . $date . '.' . mbExt($path);
            copy($path, $rotatePath);
        }
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
        self::log('Trimming log', 1, 0, MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
        $trimChunkSize = intRound($logFileSize / 2);
        trimFileFromBeginning(static::$logFilePath, $trimChunkSize, true);
    }

    public static function interactiveHideConsoleOutput()
    {
        global $SHOW_CONSOLE_OUTPUT;

        for ($i = 0; $i < 0xFFFF; $i++) {
            echo "\n";
        }
        passthru('reset');

        $SHOW_CONSOLE_OUTPUT = false;
    }

    public static function interactiveShowConsoleOutput()
    {
        global $SHOW_CONSOLE_OUTPUT;

        for ($i = 0; $i < 0xFFFF; $i++) {
            echo "\n";
        }
        passthru('reset');

        $SHOW_CONSOLE_OUTPUT = true;
    }

}

MainLog::constructStatic();