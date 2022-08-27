<?php

abstract class PuppeteerApplicationStatic extends HackApplication
{
    // ----------------------  Static part of the class ----------------------

    protected static int     $puppeteerApplicationStartedDuringThisSession = 0;
    protected static string  $workingDirectoryRoot,
                             $cliAppPath;
    protected static object  $networkStatsTotal,
                             $networkStatsThisSession,
                             $networkStatsEmpty,
                             $maxMindGeoLite2;
    protected static int     $puppeteerApplicationInstancesCountThisSession;

    protected static array   $threadsStatsPerWebsiteTotal,
                             $threadsStatsTotal,
                             $threadsStatsPerWebsiteThisSession,
                             $threadsStatsThisSession,
                             $threadTerminateReasons;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources',        [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $HOME_DIR, $TEMP_DIR, 
               $PUPPETEER_DDOS_CONNECTIONS_INITIAL,
               $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM;

        if (
                !intval($PUPPETEER_DDOS_CONNECTIONS_INITIAL)
            ||  !intval($PUPPETEER_DDOS_CONNECTIONS_MAXIMUM)
        ) {
            return;
        }

        static::$workingDirectoryRoot = $TEMP_DIR . '/puppeteer-ddos';
        static::$cliAppPath = __DIR__ . "/secret/puppeteer-ddos.cli.js";
        if (!file_exists(static::$cliAppPath)) {
            static::$cliAppPath = __DIR__ . "/puppeteer-ddos-dist.cli.js";
        }

        static::$maxMindGeoLite2 = new GeoIp2\Database\Reader($HOME_DIR . '/composer/max-mind/GeoLite2-Country.mmdb');
        static::$threadTerminateReasons = json_decode(file_get_contents(__DIR__ . '/thread-terminate-reasons.json'), JSON_OBJECT_AS_ARRAY);

        static::$networkStatsEmpty = (object) [
            'received' => 0,
            'transmitted' => 0
        ];
        static::$networkStatsTotal = clone static::$networkStatsEmpty;
        static::$threadsStatsPerWebsiteTotal = [];
        static::$threadsStatsTotal           = static::newThreadStatItem();

        // ---

        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession'], 11);

        static::createTempWorkingDirectory();
        Actions::addAction('AfterCleanTempDir',              [static::class, 'createTempWorkingDirectory']);

        Actions::addAction('BeforeMainOutputLoop',           [static::class, 'closeInstances']);
        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);

        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateSession',         [static::class, 'cleanTmpDirStep1']);

        Actions::addAction('TerminateFinalSession',          [static::class, 'killInstances']);
        Actions::addAction('TerminateSession',               [static::class, 'cleanTmpDirStep2']);

        Actions::addAction('TerminateSession',               [static::class, 'calculateStatistics'], 12);
        Actions::addAction('TerminateFinalSession',          [static::class, 'calculateStatistics'], 12);

        Actions::addFilter('OpenVpnStatisticsBadge',          [static::class, 'filterOpenVpnStatisticsBadge']);
        Actions::addFilter('OpenVpnStatisticsSessionBadge',   [static::class, 'filterOpenVpnStatisticsSessionBadge']);

        BrainServerLauncher::doAfterCalculateResources();
    }

    public static function filterKillZombieProcesses($data)
    {
        $linuxProcesses = $data['linuxProcesses'];

        if (count(PuppeteerApplication::getRunningInstances())) {
            $skipProcessesWithPids = $data['x100ProcessesPidsList'];
        } else {
            $skipProcessesWithPids = [];
        }

        killZombieProcesses($linuxProcesses, $skipProcessesWithPids, 'puppeteer/.local-chromium');
        killZombieProcesses($linuxProcesses, $skipProcessesWithPids, static::$cliAppPath);
        killZombieProcesses($linuxProcesses, $skipProcessesWithPids, BrainServerLauncher::$brainServerCliPath);
        return $data;
    }

    public static function actionAfterInitSession()
    {
        global $SESSIONS_COUNT, $PARALLEL_VPN_CONNECTIONS_QUANTITY,
               $PUPPETEER_DDOS_CONNECTIONS_INITIAL, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT,
               $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM, $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT,
               $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;

        if ($SESSIONS_COUNT === 1) {

            if (Config::isOptionValueInPercents($PUPPETEER_DDOS_CONNECTIONS_INITIAL)) {
                $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = intRound(intval($PUPPETEER_DDOS_CONNECTIONS_INITIAL) / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY);
            } else {
                $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = $PUPPETEER_DDOS_CONNECTIONS_INITIAL;
            }
            $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT = max(1, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT);

            if (Config::isOptionValueInPercents($PUPPETEER_DDOS_CONNECTIONS_MAXIMUM)) {
                $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT = intRound(intval($PUPPETEER_DDOS_CONNECTIONS_MAXIMUM) / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY);
            } else {
                $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT = $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM;
            }
            $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT = max(1, $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT);

            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT;
            MainLog::log("PuppeteerDDoS initial connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT. Possible maximum $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT");
        }

        // ---

        static::$puppeteerApplicationStartedDuringThisSession = 0;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $puppeteerApplication->jsonDataReceivedDuringThisSession = false;
            $puppeteerApplication->browserWasWaitingForFreeRamDuringThisSession = false;
        }
    }

    public static function filterInitSessionResourcesCorrection($usageValues)
    {
        global $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT,
               $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT,
               $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;

        $usageValuesCopy = $usageValues;

        // ---

        $puppeteerDDoSCpuCurrent     = $usageValuesCopy['systemAverageCpuUsage']['current']
            - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['current'];

        $puppeteerDDoSCpuGoal        = $usageValuesCopy['systemAverageCpuUsage']['goal']
            - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['current'] - 10;

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
            -  $usageValuesCopy['db1000nProcessesAverageMemUsage']['current'] - 10;

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
            $runningInstances = static::sortInstancesArrayByExecutionTime(static::getRunningInstances(), false);
            $runningInstancesCount = count($runningInstances);

            $previousSessionPuppeteerDdosConnectionsCount = $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
            $diff = intRound($correctionPercent / 100 * $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT);
            $diff = fitBetweenMinMax(-$PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT, $diff);

            $browsersCountWaitingForFreeRamDuringThisSession = array_sum(array_column($runningInstances, 'browserWasWaitingForFreeRamDuringThisSession'));
            if ($browsersCountWaitingForFreeRamDuringThisSession) {
                MainLog::log("$browsersCountWaitingForFreeRamDuringThisSession PuppeteerDDoS browser(s) were waiting for OS free Ram during this session", 1, 0, MainLog::LOG_DEBUG);
                $diff = $diff < 0  ?: 0;
            }

            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT += $diff;
            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = fitBetweenMinMax(1, $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT, $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT);

            if ($PUPPETEER_DDOS_CONNECTIONS_COUNT_INT !== $previousSessionPuppeteerDdosConnectionsCount) {
                MainLog::log($diff > 0  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" PuppeteerDDoS connections count from $previousSessionPuppeteerDdosConnectionsCount to $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT because of the rule \"" . $resourcesCorrection['rule'] . '"');
            }


            $runningInstancesReduceCount = $runningInstancesCount - $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
            for ($i = 1; $i <= $runningInstancesReduceCount; $i++) {
                $runningInstance = $runningInstances[$i - 1];
                $runningInstance->terminateAndKill(false);
            }

        }
        MainLog::log("PuppeteerDDoS connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, range $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT-$PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT", 2);
        return $usageValues;
    }

    public static function getNewObject($vpnConnection)
    {
        global $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT;

        if (!$PUPPETEER_DDOS_CONNECTIONS_COUNT_INT) {
            return false;
        }

        $puppeteerApplicationRunningInstancesCount = count(PuppeteerApplication::getRunningInstances());
        //$newPuppeteerApplicationInstancesCount     = $puppeteerApplicationRunningInstancesCount - static::$runningPuppeteerApplicationsCount;

        if (
            $puppeteerApplicationRunningInstancesCount < $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT
            &&  $puppeteerApplicationRunningInstancesCount < $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT
        ) {
            static::$puppeteerApplicationStartedDuringThisSession++;
            return new PuppeteerApplication($vpnConnection);
        } else {
            return false;
        }
    }

    public static function calculateStatistics()
    {
        static::$networkStatsThisSession = clone static::$networkStatsEmpty;
        static::$threadsStatsPerWebsiteThisSession = [];
        static::$threadsStatsThisSession           = static::newThreadStatItem();

        $puppeteerApplicationInstances = PuppeteerApplication::getInstances();
        static::$puppeteerApplicationInstancesCountThisSession = count($puppeteerApplicationInstances);
        foreach ($puppeteerApplicationInstances as $puppeteerApplicationInstance) {
            $networkStats = $puppeteerApplicationInstance->vpnConnection->calculateNetworkStats();
            static::$networkStatsThisSession->received    += $networkStats->session->received;
            static::$networkStatsThisSession->transmitted += $networkStats->session->transmitted;
            // ---
            foreach ($puppeteerApplicationInstance->threadsStat as $threadId => $threadStat) {
                $entryUrl = $puppeteerApplicationInstance->threadsEntryUrls[$threadId];
                if (!isset(static::$threadsStatsPerWebsiteThisSession[$entryUrl])) {
                    static::$threadsStatsPerWebsiteThisSession[$entryUrl] = static::newThreadStatItem();
                }
                static::$threadsStatsPerWebsiteThisSession[$entryUrl] =
                    sumSameArrays(static::$threadsStatsPerWebsiteThisSession[$entryUrl], $threadStat);
            }
        }

        if (count(static::$threadsStatsPerWebsiteThisSession)) {
            static::$threadsStatsThisSession = sumSameArrays(...array_values(static::$threadsStatsPerWebsiteThisSession));
        }

        // ---

        static::$networkStatsTotal->received    += static::$networkStatsThisSession->received;
        static::$networkStatsTotal->transmitted += static::$networkStatsThisSession->transmitted;

        foreach (static::$threadsStatsPerWebsiteThisSession as $entryUrl => $websiteThreadStat) {
            if (!isset(static::$threadsStatsPerWebsiteTotal[$entryUrl])) {
                static::$threadsStatsPerWebsiteTotal[$entryUrl] = static::newThreadStatItem();
            }
            static::$threadsStatsPerWebsiteTotal[$entryUrl] =
                sumSameArrays(static::$threadsStatsPerWebsiteTotal[$entryUrl], $websiteThreadStat);
        }

        if (count(static::$threadsStatsPerWebsiteTotal)) {
            static::$threadsStatsTotal = sumSameArrays(...array_values(static::$threadsStatsPerWebsiteTotal));
        }

        //MainLog::log(print_r(static::$threadsStatsPerWebsiteThisSession, true), 2, 1, MainLog::LOG_DEBUG);
        //MainLog::log(print_r(static::$threadsStatsPerWebsiteThisSession, true), 2, 0, MainLog::LOG_DEBUG);
    }

    protected static function newThreadStatItem() : array
    {
        $terminateReasonInitArray = [];
        foreach (array_keys(static::$threadTerminateReasons) as $code) {
            $terminateReasonInitArray[$code] = 0;
        }

        return [
            'httpRequestsSent'               => 0,
            'httpEffectiveResponsesReceived' => 0,
            'httpRenderRequestsSent'         => 0,
            'navigateTimeouts'               => 0,
            'httpStatusCode5xx'              => 0,
            'ddosBlockedRequests'            => 0,
            'captchasWereFound'              => 0,
            'sumPlainDuration'               => 0,
            'parentTerminateRequests'        => 0,
            'terminateReasonCodes'           => $terminateReasonInitArray,
        ];
    }

    protected static function getThreadStatItemBadge($threadStatItem, $duration) : string
    {
        $httpRequestsSentRate               = static::padThreadStatItemValue(roundLarge($threadStatItem['httpRequestsSent']               / $duration));
        $httpEffectiveResponsesReceivedRate = static::padThreadStatItemValue(roundLarge($threadStatItem['httpEffectiveResponsesReceived'] / $duration));
        $httpRenderRequestsSentRate         = static::padThreadStatItemValue(roundLarge($threadStatItem['httpRenderRequestsSent']         / $duration));
        $navigateTimeoutsRate               = static::padThreadStatItemValue(roundLarge($threadStatItem['navigateTimeouts']               / $duration));
        $httpStatusCode5xxRate              = static::padThreadStatItemValue(roundLarge($threadStatItem['httpStatusCode5xx']              / $duration));
        $ddosBlockedRequestsRate            = static::padThreadStatItemValue(roundLarge($threadStatItem['ddosBlockedRequests']            / $duration));
        $captchasWereFoundRate              = static::padThreadStatItemValue(roundLarge($threadStatItem['captchasWereFound']              / $duration));

        $ret  = static::padThreadStatItemValue($threadStatItem['httpRequestsSent'])               . " requests sent                   $httpRequestsSentRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['httpEffectiveResponsesReceived']) . " effective responses received    $httpEffectiveResponsesReceivedRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['httpRenderRequestsSent'])         . " rendered requests               $httpRenderRequestsSentRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['navigateTimeouts'])               . " navigate timeouts               $navigateTimeoutsRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['httpStatusCode5xx'])              . " status code 5xx                 $httpStatusCode5xxRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['ddosBlockedRequests'])            . " DDoS blocked requests           $ddosBlockedRequestsRate per second\n";
        $ret .= static::padThreadStatItemValue($threadStatItem['captchasWereFound'])              . " captchas were found             $captchasWereFoundRate per second\n";

        $averagePlainDuration = intRound($threadStatItem['sumPlainDuration'] / $threadStatItem['httpRequestsSent']);
        $ret .= "      average response duration $averagePlainDuration second(s)\n\n";

        return $ret;
    }

    protected static function padThreadStatItemValue($value) : string
    {
        return str_pad($value, 8, ' ', STR_PAD_LEFT);
    }

    public static function filterOpenVpnStatisticsSessionBadge($value)
    {
        global $VPN_SESSION_STARTED_AT;
        if (
                $value
            &&  static::$networkStatsThisSession->received
            &&  static::$networkStatsThisSession->transmitted
        ) {
            $value .= "\n". getHumanBytesLabel(
                'PuppeteerDDoS session traffic',
                static::$networkStatsThisSession->received,
                static::$networkStatsThisSession->transmitted
            );
            $duration = time() - $VPN_SESSION_STARTED_AT;
            $value .= "\n" . str_repeat(' ', 31) .  'through ' . static::$puppeteerApplicationInstancesCountThisSession . " VPN connection(s)";
            $value .= "\n\n" . static::getThreadStatItemBadge(static::$threadsStatsThisSession, $duration);
        }
        return $value;
    }

    public static function filterOpenVpnStatisticsBadge($value)
    {
        global $SCRIPT_STARTED_AT;
        if (
                $value
            &&  static::$networkStatsTotal->received
            &&  static::$networkStatsTotal->transmitted
        ) {
            $value .= "\n". getHumanBytesLabel(
                'PuppeteerDDoS total traffic',
                static::$networkStatsTotal->received,
                static::$networkStatsTotal->transmitted
            );
            $duration = time() - $SCRIPT_STARTED_AT;
            $value .= "\n\n" . static::getThreadStatItemBadge(static::$threadsStatsTotal, $duration);
        }
        return $value;
    }

    public static function closeInstances()
    {
        global $CURRENT_SESSION_DURATION, $ONE_SESSION_MAX_DURATION;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $networkStats = $puppeteerApplication->vpnConnection->calculateNetworkStats();
            if (
                    $networkStats->total->receiveSpeed < 50 * 1024
                &&  $networkStats->session->duration > $CURRENT_SESSION_DURATION / 2
            ) {
                $puppeteerApplication->requireTerminate('Network speed low');
            } else if ($networkStats->total->duration > rand(2 * $ONE_SESSION_MAX_DURATION, 4 * $ONE_SESSION_MAX_DURATION)) {
                $puppeteerApplication->requireTerminate('The attack lasts ' . intRound($networkStats->total->duration / 60) . " minutes. Close this connection to cool it's IP");
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

}

PuppeteerApplicationStatic::constructStatic();