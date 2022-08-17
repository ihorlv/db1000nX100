<?php

abstract class PuppeteerApplicationStatic extends HackApplication
{
    // ----------------------  Static part of the class ----------------------

    protected static int     $puppeteerApplicationStartedDuringThisSession = 0;
    protected static bool    $showCaptchaBrowsersSentDuringThisSession = false;
    protected static string  $workingDirectoryRoot,
        $cliAppPath;
    protected static object  $closedPuppeteerApplicationsNetworkStats,
        $runningPuppeteerApplicationsNetworkStats,
        $runningPuppeteerApplicationsNetworkStatsThisSession;
    protected static int     $runningPuppeteerApplicationsCount;

    protected static object  $maxMindGeoLite2;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $HOME_DIR, $TEMP_DIR, 
               $PUPPETEER_DDOS_CONNECTIONS_INITIAL;
               //$PUPPETEER_DDOS_CONNECTIONS_QUOTA;

        if (!intval($PUPPETEER_DDOS_CONNECTIONS_INITIAL)) {
            return;
        }

        static::$workingDirectoryRoot = $TEMP_DIR . '/puppeteer-ddos';
        static::$cliAppPath = __DIR__ . "/secret/puppeteer-ddos.cli.js";
        if (!file_exists(static::$cliAppPath)) {
            static::$cliAppPath = __DIR__ . "/puppeteer-ddos-dist.cli.js";
        }

        $linuxProcesses = getLinuxProcesses();
        killZombieProcesses($linuxProcesses, 'chrome');
        killZombieProcesses($linuxProcesses, 'chromium');
        killZombieProcesses($linuxProcesses, static::$cliAppPath);

        static::$closedPuppeteerApplicationsNetworkStats = new \stdClass();
        static::$closedPuppeteerApplicationsNetworkStats->received = 0;
        static::$closedPuppeteerApplicationsNetworkStats->transmitted = 0;
        static::$closedPuppeteerApplicationsNetworkStats->effectiveResponsesReceived = 0;

        static::$runningPuppeteerApplicationsNetworkStats = new \stdClass();
        static::$runningPuppeteerApplicationsNetworkStatsThisSession = new \stdClass();
        static::$runningPuppeteerApplicationsCount = 0;

        static::$maxMindGeoLite2 = new GeoIp2\Database\Reader($HOME_DIR . '/composer/max-mind/GeoLite2-Country.mmdb');

        Actions::addFilter('InitSessionResourcesCorrection', [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession'], 11);
        Actions::addAction('BeforeTerminateSession',         [static::class, 'actionBeforeTerminateSession'], 8);

        Actions::addAction('BeforeTerminateSession',         [static::class, 'cleanTmpDirStep1']);
        Actions::addAction('TerminateSession',               [static::class, 'cleanTmpDirStep2']);
        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'terminateInstances']);
        Actions::addAction('TerminateFinalSession',          [static::class, 'killInstances']);

        Actions::addFilter('OpenVpnStatisticsBadge',         [static::class, 'filterOpenVpnStatisticsBadge']);
        Actions::addFilter('OpenVpnStatisticsSessionBadge',  [static::class, 'filterOpenVpnStatisticsSessionBadge']);
        Actions::addAction('BeforeMainOutputLoopIterations', [static::class, 'closeInstances']);
        //Actions::addAction('AfterMainOutputLoopIterations', [static::class, 'showCaptchaBrowsers']);

        static::createTempWorkingDirectory();
        Actions::addAction('AfterCleanTempDir',              [static::class, 'createTempWorkingDirectory']);

        BrainServerLauncher::doAfterCalculateResources();
    }

    public static function actionAfterInitSession()
    {
        global $SESSIONS_COUNT, $PARALLEL_VPN_CONNECTIONS_QUANTITY, $DB1000N_SCALE_MAX_STEP, $DB1000N_SCALE_INITIAL,
               $PUPPETEER_DDOS_CONNECTIONS_INITIAL, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT,
               //$PUPPETEER_DDOS_CONNECTIONS_QUOTA, $PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT,
               $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;

        if ($SESSIONS_COUNT === 1) {

            if (Config::isOptionValueInPercents($PUPPETEER_DDOS_CONNECTIONS_INITIAL)) {
                $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = intRound(intval($PUPPETEER_DDOS_CONNECTIONS_INITIAL) / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY);
            } else {
                $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = $PUPPETEER_DDOS_CONNECTIONS_INITIAL;
            }
            $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = max(1, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT);

            /*if (Config::isOptionValueInPercents($PUPPETEER_DDOS_CONNECTIONS_QUOTA)) {
                $PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT = intRound(intval($PUPPETEER_DDOS_CONNECTIONS_QUOTA) / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY);
            } else {
                $PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT = $PUPPETEER_DDOS_CONNECTIONS_QUOTA;
            }
            $PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT = max(1, $PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT);*/

            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT;
            //$DB1000N_SCALE_MAX_STEP = $DB1000N_SCALE_INITIAL;

            MainLog::log("PuppeteerDDoS initial connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT");  //, range $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT-$PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT
        }

        // ---

        static::$puppeteerApplicationStartedDuringThisSession = 0;
        static::$showCaptchaBrowsersSentDuringThisSession = false;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $puppeteerApplication->jsonDataReceivedDuringThisSession = false;
        }
    }

    public static function actionBeforeTerminateSession()
    {
        static::$runningPuppeteerApplicationsNetworkStats->received = 0;
        static::$runningPuppeteerApplicationsNetworkStats->transmitted = 0;
        static::$runningPuppeteerApplicationsNetworkStats->effectiveResponsesReceived = 0;

        static::$runningPuppeteerApplicationsNetworkStatsThisSession->received = 0;
        static::$runningPuppeteerApplicationsNetworkStatsThisSession->transmitted = 0;

        static::$runningPuppeteerApplicationsCount = 0;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $networkStats = $puppeteerApplication->vpnConnection->calculateNetworkStats();

            static::$runningPuppeteerApplicationsNetworkStats->received    += $networkStats->total->received;
            static::$runningPuppeteerApplicationsNetworkStats->transmitted += $networkStats->total->transmitted;
            static::$runningPuppeteerApplicationsNetworkStats->effectiveResponsesReceived += $puppeteerApplication->stat->total->httpEffectiveResponsesReceived ?? 0;

            static::$runningPuppeteerApplicationsNetworkStatsThisSession->received    += $networkStats->session->received;
            static::$runningPuppeteerApplicationsNetworkStatsThisSession->transmitted += $networkStats->session->transmitted;

            static::$runningPuppeteerApplicationsCount++;
        }
    }

    public static function filterOpenVpnStatisticsSessionBadge($value)
    {
        if (
            $value
            &&  static::$runningPuppeteerApplicationsNetworkStatsThisSession->received
            &&  static::$runningPuppeteerApplicationsNetworkStatsThisSession->transmitted
        ) {
            $value .= getHumanBytesLabel(
                'PuppeteerDDoS session traffic',
                static::$runningPuppeteerApplicationsNetworkStatsThisSession->received,
                static::$runningPuppeteerApplicationsNetworkStatsThisSession->transmitted
            );
            $value .= ', through ' . static::$runningPuppeteerApplicationsCount . ' VPN connection(s)';
            $value .= " \n";
        }
        return $value;
    }

    public static function filterOpenVpnStatisticsBadge($value)
    {
        global $SCRIPT_STARTED_AT;

        $totalReceivedTraffic       = static::$closedPuppeteerApplicationsNetworkStats->received
            + static::$runningPuppeteerApplicationsNetworkStats->received;

        $totalTransmittedTraffic    = static::$closedPuppeteerApplicationsNetworkStats->transmitted
            + static::$runningPuppeteerApplicationsNetworkStats->transmitted;

        $effectiveResponsesReceived = static::$closedPuppeteerApplicationsNetworkStats->effectiveResponsesReceived
            + static::$runningPuppeteerApplicationsNetworkStats->effectiveResponsesReceived;

        if (
            $value
            &&  $totalReceivedTraffic
            &&  $totalTransmittedTraffic
            &&  $effectiveResponsesReceived
        ) {
            $value .= getHumanBytesLabel(
                'PuppeteerDDoS total traffic',
                $totalReceivedTraffic,
                $totalTransmittedTraffic
            );

            $effectiveResponsesReceivedRate = roundLarge($effectiveResponsesReceived / (time() - $SCRIPT_STARTED_AT));
            $value .= ", $effectiveResponsesReceived effective response(s) received (~$effectiveResponsesReceivedRate per second)\n";
        }
        return $value;
    }

    public static function closeInstances()
    {
        global $CURRENT_SESSION_DURATION;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $networkStats = $puppeteerApplication->vpnConnection->calculateNetworkStats();
            if (
                $networkStats->total->receiveSpeed < 50 * 1024
                &&  $networkStats->session->duration > $CURRENT_SESSION_DURATION / 2
            ) {
                $puppeteerApplication->terminateMessage = 'Network speed low';
                $puppeteerApplication->requireTerminate = true;
            } else if ($networkStats->total->duration > rand(15, 30) * 60) {
                $puppeteerApplication->terminateMessage = 'The attack lasts ' . intRound($networkStats->total->duration / 60) . " minutes. Close this connection to cool it's IP";
                $puppeteerApplication->requireTerminate = true;
            }
        }
    }

    public static function createTempWorkingDirectory()
    {
        rmdirRecursive(static::$workingDirectoryRoot);
        mkdir(static::$workingDirectoryRoot);
        chmod(static::$workingDirectoryRoot, changeLinuxPermissions(0, 'rwx', 'rwx'));
        chown(static::$workingDirectoryRoot, 'user');
        chgrp(static::$workingDirectoryRoot, 'user');
        //MainLog::log('!yes!');
        //if (!is_dir(static::$workingDirectoryRoot)) {
        //    MainLog::log('!shit!');
       //     die();
        //}
    }

    public static function cleanTmpDirStep1()
    {
        global $SESSIONS_COUNT;
        if ($SESSIONS_COUNT % 10 === 0) {
            static::terminateInstances();
        }
    }

    public static function cleanTmpDirStep2()
    {
        global $SESSIONS_COUNT;
        if ($SESSIONS_COUNT % 10 === 0) {
            static::killInstances();
            cleanTmpDir();
        }
    }

    public static function showCaptchaBrowsers()
    {
        if (static::$showCaptchaBrowsersSentDuringThisSession) {
            return;
        }

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            if (!$puppeteerApplication->jsonDataReceivedDuringThisSession) {
                return;
            }
        }

        $soundWasPlayed = false;
        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            if ($puppeteerApplication->isWaitingForManualCaptchaResolution) {
                $puppeteerApplication->isWaitingForManualCaptchaResolution = false;
                $puppeteerApplication->sendStdinCommand('show-captcha-browsers');
                if (!$soundWasPlayed) {
                    _shell_exec('/usr/bin/music123 /usr/share/sounds/freedesktop/stereo/complete.oga');
                    $soundWasPlayed = true;
                }
            }
        }

        static::$showCaptchaBrowsersSentDuringThisSession = true;
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT,
               //$PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT,
               $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT,
               $PARALLEL_VPN_CONNECTIONS_QUANTITY;

        $usageValuesCopy = $usageValues;

        // ---

        $puppeteerDDoSCpuCurrent     = $usageValuesCopy['systemAverageCpuUsage']['current']
                                     - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['current'];

        $puppeteerDDoSCpuGoal        = $usageValuesCopy['systemAverageCpuUsage']['goal']
                                     - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['goal'];

        $puppeteerDDoSCpuConfigLimit = $usageValuesCopy['db1000nProcessesAverageCpuUsage']['configLimit'];

        $usageValuesCopy['puppeteerDDoSAverageCpuUsage'] =[
            'current'      => $puppeteerDDoSCpuCurrent,
            'goal'         => $puppeteerDDoSCpuGoal,
            'configLimit'  => $puppeteerDDoSCpuConfigLimit
        ];

        unset($usageValuesCopy['db1000nProcessesAverageCpuUsage']);

        // ---

        $puppeteerDDoSMemCurrent     = $usageValuesCopy['systemAverageRamUsage']['current']
                                     + $usageValuesCopy['systemAverageSwapUsage']['current']
                                     - $usageValuesCopy['db1000nProcessesAverageMemUsage']['current'];

        $puppeteerDDoSMemGoal        =  $usageValuesCopy['systemAverageRamUsage']['goal']
                                     -  $usageValuesCopy['db1000nProcessesAverageMemUsage']['goal'];

        $puppeteerDDoSMemConfigLimit =  $usageValuesCopy['db1000nProcessesAverageMemUsage']['configLimit'];

        $usageValuesCopy['puppeteerDDoSAverageMemUsage'] = [
            'current'      => $puppeteerDDoSMemCurrent,
            'goal'         => $puppeteerDDoSMemGoal,
            'configLimit'  => $puppeteerDDoSMemConfigLimit
        ];

        unset($usageValuesCopy['db1000nProcessesAverageMemUsage']);

        // ---

        unset($usageValuesCopy['averageNetworkUsageReceive']);
        unset($usageValuesCopy['averageNetworkUsageTransmit']);

        // ---

        MainLog::log('PuppeteerDDoS connections count calculation rules', 1, 0, MainLog::LOG_DEBUG);
        $resourcesCorrection = ResourcesConsumption::getResourcesCorrection($usageValuesCopy);
        $correctionPercent   = $resourcesCorrection['percent'] ?? false;

        if ($correctionPercent) {
            $previousSessionPuppeteerDdosConnectionsCount = $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
            $diff = intRound($correctionPercent / 100 * $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT);
            $diff = fitBetweenMinMax(-$PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT, $diff);

            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT += $diff;
            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = fitBetweenMinMax(ceil($PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT / 3), $PARALLEL_VPN_CONNECTIONS_QUANTITY /*$PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT*/, $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT);

            if ($PUPPETEER_DDOS_CONNECTIONS_COUNT_INT !== $previousSessionPuppeteerDdosConnectionsCount) {
                MainLog::log($diff > 0  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" PuppeteerDDoS connections count from $previousSessionPuppeteerDdosConnectionsCount to $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT because of the rule \"" . $resourcesCorrection['rule'] . '"');
            }

            $runningInstances = static::sortInstancesArrayByExecutionTime(static::getRunningInstances(), false);
            $runningInstancesCount = count($runningInstances);
            $runningInstancesReduceCount = $runningInstancesCount - $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
            for ($i = 1; $i <= $runningInstancesReduceCount; $i++) {
                $runningInstance = $runningInstances[$i - 1];
                $runningInstance->terminateAndKill(false);
            }

        }
        MainLog::log("PuppeteerDDoS connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, initial value $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT", 2);  //, range $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT-$PUPPETEER_DDOS_CONNECTIONS_QUOTA_INT
        return $usageValues;
    }

    public static function getNewObject($vpnConnection)
    {
        global $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;

        if (!$PUPPETEER_DDOS_CONNECTIONS_COUNT_INT) {
            return false;
        }

        $puppeteerApplicationRunningInstancesCount = count(PuppeteerApplication::getRunningInstances());
        //$newPuppeteerApplicationInstancesCount     = $puppeteerApplicationRunningInstancesCount - static::$runningPuppeteerApplicationsCount;

        if (
            $puppeteerApplicationRunningInstancesCount  <  $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT

        ) {
            static::$puppeteerApplicationStartedDuringThisSession++;
            return new PuppeteerApplication($vpnConnection);
        } else {
            return false;
        }
    }

    // --------------------------------------
}

PuppeteerApplicationStatic::constructStatic();