<?php

class BrainServerLauncher
{
    public static string $brainServerCliPath;

    private static $brainServerCliPhpProcess = null,
                   $brainServerCliProcessPGid,
                   $brainServerCliPhpPipes;

    public static function doAfterCalculateResources()
    {
        static::$brainServerCliPath = __DIR__ . "/secret/brain-server.cli.js";
        if (!file_exists(static::$brainServerCliPath)) {
            static::$brainServerCliPath = __DIR__ . "/brain-server-dist.cli.js";
        }

        Actions::addAction('AfterInitSession',              [static::class, 'rerunBrainServerCli'], 11);
        Actions::addAction('BeforeTerminateSession',        [static::class, 'showBrainServerCliStdout']);

        Actions::addAction('TerminateSession',              [static::class, 'terminateBrainServerCli'], 20);
        Actions::addAction('TerminateFinalSession',         [static::class, 'terminateBrainServerCli'], 20);

        Actions::addAction('AfterTerminateSession',         [static::class, 'killBrainServerCli'], 20);
        Actions::addAction('AfterTerminateFinalSession',    [static::class, 'killBrainServerCli'], 20);
    }

    public static function rerunBrainServerCli()
    {
        if (is_resource(static::$brainServerCliPhpProcess)) {
            $processStatus = proc_get_status(static::$brainServerCliPhpProcess);
            if ($processStatus['running']) {
                return;
            }
        }

        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );

        $command = "nice -n 19  "
            . static::$brainServerCliPath
            . '  --images-export-dir="' . Config::$putYourOvpnFilesHerePath . '/captchas-log"'
            . '   2>&1';

        static::$brainServerCliPhpProcess = proc_open($command, $descriptorSpec, static::$brainServerCliPhpPipes);
        static::$brainServerCliProcessPGid = procChangePGid(static::$brainServerCliPhpProcess, $changePGidLog);

        if (static::$brainServerCliPhpProcess === false) {
            MainLog::log('Failed to start PuppeteerDDoS ' . mbBasename(static::$brainServerCliPath), 1, 1, MainLog::LOG_HACK_APPLICATION_ERROR);
            MainLog::log($changePGidLog, 1, 0, MainLog::LOG_HACK_APPLICATION_ERROR);
        } else {
            MainLog::log('PuppeteerDDoS ' . mbBasename(static::$brainServerCliPath) . ' started with PGID ' . static::$brainServerCliProcessPGid, 2, 0, MainLog::LOG_HACK_APPLICATION);
        }
    }

    public static function showBrainServerCliStdout()
    {
        if (
            SelfUpdate::$isDevelopmentVersion
            &&  static::$brainServerCliPhpPipes
        ) {
            $brainServerCliOutput = streamReadLines(static::$brainServerCliPhpPipes[1], 0);
            if ($brainServerCliOutput) {
                $brainServerCliOutput  = 'Output from ' . mbBasename(static::$brainServerCliPath) . "\n" . $brainServerCliOutput;
                MainLog::log($brainServerCliOutput, 1, 1, MainLog::LOG_DEBUG);
            }
        }
    }

    public static function terminateBrainServerCli()
    {
        if (count(PuppeteerApplication::getRunningInstances())) {
            return;
        }

        if (static::$brainServerCliPhpProcess) {
            MainLog::log(mbBasename(static::$brainServerCliPath) . ' terminate PGID -' . static::$brainServerCliProcessPGid, 1, 0, MainLog::LOG_HACK_APPLICATION);
            @posix_kill(0 - static::$brainServerCliProcessPGid, SIGTERM);
        }
    }

    public static function killBrainServerCli()
    {
        if (count(PuppeteerApplication::getRunningInstances())) {
            return;
        }

        if (static::$brainServerCliPhpProcess) {
            MainLog::log(mbBasename(static::$brainServerCliPath) . ' kill PGID -' . static::$brainServerCliProcessPGid, 1, 0, MainLog::LOG_HACK_APPLICATION);
            @posix_kill(0 - static::$brainServerCliProcessPGid, SIGKILL);
        }
    }

}