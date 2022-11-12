<?php

abstract class PuppeteerApplicationStatic extends HackApplication
{
    // ----------------------  Static part of the class ----------------------

    protected static int     $puppeteerApplicationStartedDuringThisSession = 0;
    protected static string  $workingDirectoryRoot,
                             $cliAppPath;
    protected static object  $networkStatsTotal,
                             $networkStatsThisSession,
                             $networkStatsEmpty;

    protected static int     $puppeteerApplicationInstancesCountThisSession;

    protected static array   $websitesRequestsStatAllSessions,
                             $requestsStatAllSessions,
                             $websitesRequestsStatThisSession,
                             $requestsStatThisSession,
                             $threadTerminateReasons;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        global $TEMP_DIR,
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

        static::$threadTerminateReasons = json_decode(file_get_contents(__DIR__ . '/thread-terminate-reasons.json'), JSON_OBJECT_AS_ARRAY);

        static::$networkStatsEmpty = (object) [
            'received' => 0,
            'transmitted' => 0
        ];
        static::$networkStatsTotal = clone static::$networkStatsEmpty;
        static::$websitesRequestsStatAllSessions = [];
        static::$requestsStatAllSessions           = static::newThreadRequestsStatItem();

        // ---

        Actions::addFilter('RegisterHackApplicationClasses',  [static::class, 'filterRegisterHackApplicationClasses'], 9);
        Actions::addFilter('InitSessionResourcesCorrection',  [static::class, 'filterInitSessionResourcesCorrection']);
        Actions::addAction('AfterInitSession',                [static::class, 'actionAfterInitSession'], 11);

        static::createTempWorkingDirectory();
        Actions::addAction('AfterCleanTempDir',               [static::class, 'createTempWorkingDirectory']);

        Actions::addAction('BeforeMainOutputLoopIteration',   [static::class, 'actionBeforeMainOutputLoopIteration']);
        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);

        Actions::addAction('BeforeTerminateFinalSession',     [static::class, 'terminateInstances']);
        Actions::addAction('BeforeTerminateSession',          [static::class, 'cleanTmpDirStep1']);

        Actions::addAction('TerminateSession',                [static::class, 'killInstances']);
        Actions::addAction('TerminateSession',                [static::class, 'cleanTmpDirStep2']);

        Actions::addAction('TerminateSession',                [static::class, 'calculateStatistics'], 12);
        Actions::addAction('TerminateFinalSession',           [static::class, 'calculateStatistics'], 12);

        Actions::addFilter('OpenVpnStatisticsBadge',          [static::class, 'filterOpenVpnStatisticsBadge']);
        Actions::addFilter('OpenVpnStatisticsSessionBadge',   [static::class, 'filterOpenVpnStatisticsSessionBadge']);

        BrainServerLauncher::doAfterCalculateResources();
    }

    public static function filterRegisterHackApplicationClasses($classNamesArray)
    {
        $classNamesArray[] = 'PuppeteerApplication';
        return $classNamesArray;
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
            MainLog::log("PuppeteerDDoS initial connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT. Possible maximum $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT", 2);
        }

        // ---

        static::$puppeteerApplicationStartedDuringThisSession = 0;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            foreach ($puppeteerApplication->threadsStates as $threadId => $threadState) {
                $threadState->dataReceivedDuringThisSession = false;
                $threadState->browserWasWaitingForFreeRamDuringThisSession = false;
                $puppeteerApplication->threadsStates[$threadId] = $threadState;
            }
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
                                     - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['current']
                                     - $usageValuesCopy['distressProcessesAverageCpuUsage']['current'];

        $puppeteerDDoSCpuGoal        = $usageValuesCopy['systemAverageCpuUsage']['goal']
                                     - $usageValuesCopy['db1000nProcessesAverageCpuUsage']['current']
                                     - $usageValuesCopy['distressProcessesAverageCpuUsage']['current']
                                     - 10;

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
                                     - $usageValuesCopy['db1000nProcessesAverageMemUsage']['current']
                                     - $usageValuesCopy['distressProcessesAverageMemUsage']['current'];

        $puppeteerDDoSMemGoal        = $usageValuesCopy['systemAverageRamUsage']['goal']
                                     - $usageValuesCopy['db1000nProcessesAverageMemUsage']['current']
                                     - $usageValuesCopy['distressProcessesAverageMemUsage']['current']
                                     - 10;

        $puppeteerDDoSMemConfigLimit =  $usageValuesCopy['db1000nProcessesAverageMemUsage']['configLimit'];

        $usageValuesCopy['puppeteerDDoSAverageMemUsage'] = [
            'current'      => $puppeteerDDoSMemCurrent,
            'goal'         => $puppeteerDDoSMemGoal,
            'configLimit'  => $puppeteerDDoSMemConfigLimit
        ];

        unset($usageValuesCopy['db1000nProcessesAverageMemUsage']);

        // ---

        unset($usageValuesCopy['systemTopNetworkUsageReceive']);
        unset($usageValuesCopy['systemTopNetworkUsageTransmit']);

        // ---

        MainLog::log('PuppeteerDs average  CPU   usage during previous session was ' . padPercent($usageValuesCopy['puppeteerDDoSAverageCpuUsage']['current']));
        MainLog::log('PuppeteerDs average  RAM   usage during previous session was ' . padPercent($usageValuesCopy['puppeteerDDoSAverageMemUsage']['current']), 2);

        // ---

        $runningInstances = static::sortInstancesArrayByEfficiencyLevel(static::getRunningInstances());
        $runningInstancesCount = count($runningInstances);

        $threadsCountWaitingForFreeRamRecently = 0;
        foreach ($runningInstances as $runningInstance) {
            foreach ($runningInstance->threadsStates as $threadState) {
                if ($threadState->browserWasWaitingForFreeRamAt + 5 * 60 > time()) {
                    $threadsCountWaitingForFreeRamRecently++;
                }
            }
        }

        // ---

        $resourcesCorrectionRule = ResourcesConsumption::reCalculateScaleNG($usageValuesCopy, $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, 1, $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT);
        MainLog::log('PuppeteerDDoS connections count calculation rules', 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
        MainLog::log(print_r($usageValuesCopy, true), 2, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);

        $previousSessionConnectionsCount = $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
        $thisSessionConnectionsCount = intRound($resourcesCorrectionRule['newScale']);

        if ($thisSessionConnectionsCount !== $previousSessionConnectionsCount) {

            if (
                     $threadsCountWaitingForFreeRamRecently
                &&   $thisSessionConnectionsCount > $previousSessionConnectionsCount
            ) {
                MainLog::log("$threadsCountWaitingForFreeRamRecently PuppeteerDDoS thread(s) were waiting for OS free Ram during this session", 1, 0, MainLog::LOG_HACK_APPLICATION);
                $thisSessionConnectionsCount = $previousSessionConnectionsCount;
            }

            if ($thisSessionConnectionsCount !== $previousSessionConnectionsCount) {
                MainLog::log($thisSessionConnectionsCount > $previousSessionConnectionsCount  ?  'Increasing' : 'Decreasing', 0);
                MainLog::log(" PuppeteerDDoS connections count from $previousSessionConnectionsCount to $thisSessionConnectionsCount because of the rule \"" . $resourcesCorrectionRule['name'] . '"');
            }

            // ---

            $runningInstancesReduceCount = $runningInstancesCount - $thisSessionConnectionsCount;
            for ($i = 1; $i <= $runningInstancesReduceCount; $i++) {
                $runningInstance = $runningInstances[$i - 1];
                $runningInstance->terminateAndKill(false);
            }

            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = $thisSessionConnectionsCount;
        }

        MainLog::log("PuppeteerDDoS connections count $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, range $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT-$PUPPETEER_DDOS_CONNECTIONS_MAXIMUM_INT", 2);
        return $usageValues;
    }

    public static function countPossibleInstances() : int
    {
        global $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
        return $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT;
    }

    public static function getNewInstance($vpnConnection)
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
        static::$websitesRequestsStatThisSession = [];
        static::$requestsStatThisSession           = static::newThreadRequestsStatItem();

        $puppeteerApplicationInstances = PuppeteerApplication::getInstances();
        static::$puppeteerApplicationInstancesCountThisSession = count($puppeteerApplicationInstances);
        foreach ($puppeteerApplicationInstances as $puppeteerApplicationInstance) {
            $networkStats = $puppeteerApplicationInstance->vpnConnection->calculateNetworkStats();
            static::$networkStatsThisSession->received    += $networkStats->session->received;
            static::$networkStatsThisSession->transmitted += $networkStats->session->transmitted;
            // ---
            foreach ($puppeteerApplicationInstance->threadsStates as $threadId => $threadState) {
                $threadRequestsStat = $puppeteerApplicationInstance->threadsRequestsStat[$threadId];

                $websiteRequestsStat = static::$websitesRequestsStatThisSession[$threadState->entryUrl] ?? static::newThreadRequestsStatItem() ;
                $websiteRequestsStat = sumSameArrays($websiteRequestsStat, $threadRequestsStat);
                static::$websitesRequestsStatThisSession[$threadState->entryUrl] = $websiteRequestsStat;
            }
        }

        if (count(static::$websitesRequestsStatThisSession)) {
            static::$requestsStatThisSession = sumSameArrays(...array_values(static::$websitesRequestsStatThisSession));
        }

        // ---

        static::$networkStatsTotal->received    += static::$networkStatsThisSession->received;
        static::$networkStatsTotal->transmitted += static::$networkStatsThisSession->transmitted;

        foreach (static::$websitesRequestsStatThisSession as $entryUrl => $websiteRequestsStat) {

            $websiteRequestsStatAllSessions = static::$websitesRequestsStatAllSessions[$entryUrl] ?? static::newThreadRequestsStatItem() ;
            $websiteRequestsStatAllSessions = sumSameArrays($websiteRequestsStatAllSessions, $websiteRequestsStat);
            static::$websitesRequestsStatAllSessions[$entryUrl] = $websiteRequestsStatAllSessions;
        }

        if (count(static::$websitesRequestsStatAllSessions)) {
            static::$requestsStatAllSessions = sumSameArrays(...array_values(static::$websitesRequestsStatAllSessions));
        }

        //MainLog::log(print_r(static::$threadsStatsPerWebsiteThisSession, true), 2, 1, MainLog::LOG_DEBUG);
        //MainLog::log(print_r(static::$threadsStatsPerWebsiteThisSession, true), 2, 0, MainLog::LOG_DEBUG);
    }

    protected static function newThreadStateItem() : stdClass
    {
        $ret = new stdClass();
        $ret->entryUrl = '';
        $ret->totalLinksCount = 0;
        $ret->usingProxy = false;
        $ret->dataReceivedDuringThisSession = false;
        $ret->browserWasWaitingForFreeRamAt = false;
        $ret->terminateReasonCode = '';
        return $ret;
    }

    protected static function newThreadRequestsStatItem() : array
    {
        return [
            'httpRequestsSent'                       => 0,
            'httpEffectiveResponsesReceived'         => 0,
            'httpRequestsSentViaProxy'               => 0,
            'httpEffectiveResponsesReceivedViaProxy' => 0,
            'httpRenderRequestsSent'                 => 0,
            'navigateTimeouts'                       => 0,
            'httpStatusCode5xx'                      => 0,
            'ddosBlockedRequests'                    => 0,
            'captchasWereFound'                      => 0,
            'captchasWereResolved'                   => 0,
            'sumPlainDuration'                       => 0
        ];
    }

    protected static function newThreadsSumItem() : array
    {
        $terminateReasonCodesCountEmpty = [];
        foreach (array_keys(static::$threadTerminateReasons) as $code) {
            $terminateReasonCodesCountEmpty[$code] = 0;
        }

        return [
            'threadsRequestsStat'       => static::newThreadRequestsStatItem(),
            'runningThreads'            => 0,
            'terminateReasonCodesCount' => $terminateReasonCodesCountEmpty
        ];
    }

    protected static function getThreadStatItemBadge($threadStatItem, $duration) : string
    {
        if (!$threadStatItem['httpRequestsSent']) {
            return '';
        }

        $httpRequestsSentRate                       = static::padThreadStatItemValue(roundLarge($threadStatItem['httpRequestsSent']               / $duration));
        $httpEffectiveResponsesReceivedRate         = static::padThreadStatItemValue(roundLarge($threadStatItem['httpEffectiveResponsesReceived'] / $duration));
        $httpRequestsSentViaProxyRate               = static::padThreadStatItemValue(roundLarge($threadStatItem['httpRequestsSentViaProxy']               / $duration));
        $httpEffectiveResponsesReceivedViaProxyRate = static::padThreadStatItemValue(roundLarge($threadStatItem['httpEffectiveResponsesReceivedViaProxy'] / $duration));

        $httpRenderRequestsSentRate         = static::padThreadStatItemValue(roundLarge($threadStatItem['httpRenderRequestsSent']         / $duration));
        $navigateTimeoutsRate               = static::padThreadStatItemValue(roundLarge($threadStatItem['navigateTimeouts']               / $duration));
        $httpStatusCode5xxRate              = static::padThreadStatItemValue(roundLarge($threadStatItem['httpStatusCode5xx']              / $duration));
        $ddosBlockedRequestsRate            = static::padThreadStatItemValue(roundLarge($threadStatItem['ddosBlockedRequests']            / $duration));
        $captchasWereFoundRate              = static::padThreadStatItemValue(roundLarge($threadStatItem['captchasWereFound']              / $duration));

        // ------------------
                $ret = '';

            if ($threadStatItem['httpRequestsSent']  !== $threadStatItem['httpRequestsSentViaProxy']) {
                $ret .= static::padThreadStatItemValue($threadStatItem['httpRequestsSent'])                        . " requests sent                   $httpRequestsSentRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['httpEffectiveResponsesReceived'])         . " effective responses received    $httpEffectiveResponsesReceivedRate per second\n";
            }

            if ($threadStatItem['httpRequestsSentViaProxy']) {
                $ret .= static::padThreadStatItemValue($threadStatItem['httpRequestsSentViaProxy'])               . " requests sent via proxy         $httpRequestsSentViaProxyRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['httpEffectiveResponsesReceivedViaProxy']) . " effective responses via proxy   $httpEffectiveResponsesReceivedViaProxyRate per second\n";
            }

                $ret .= static::padThreadStatItemValue($threadStatItem['httpRenderRequestsSent'])                 . " rendered requests               $httpRenderRequestsSentRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['navigateTimeouts'])                       . " navigate timeouts               $navigateTimeoutsRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['httpStatusCode5xx'])                      . " status code 5xx                 $httpStatusCode5xxRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['ddosBlockedRequests'])                    . " DDoS blocked requests           $ddosBlockedRequestsRate per second\n";
                $ret .= static::padThreadStatItemValue($threadStatItem['captchasWereFound'])                      . " captchas were found             $captchasWereFoundRate per second\n";

        // ------------------

        $averagePlainDuration = intRound($threadStatItem['sumPlainDuration'] / $threadStatItem['httpRequestsSent']);
        $ret .= "         average response duration $averagePlainDuration second(s)\n";

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
            $value .= "\n\n" . static::getThreadStatItemBadge(static::$requestsStatThisSession, $duration);
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
            $value .= "\n\n" . static::getThreadStatItemBadge(static::$requestsStatAllSessions, $duration);
        }
        return $value;
    }

    /*public static function actionBeforeMainOutputLoopIteration()
    {
        global $CURRENT_SESSION_DURATION, $ONE_SESSION_MAX_DURATION;

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            $networkStats = $puppeteerApplication->vpnConnection->calculateNetworkStats();
            if (
                    $networkStats->total->receiveSpeed < 50 * 1024
                &&  $networkStats->total->duration > $CURRENT_SESSION_DURATION / 2
            ) {
                $puppeteerApplication->requireTerminate('Network speed low');
            } else if ($networkStats->total->duration > rand(1 * $ONE_SESSION_MAX_DURATION, 3 * $ONE_SESSION_MAX_DURATION)) {
                $puppeteerApplication->requireTerminate('The attack lasts ' . intRound($networkStats->total->duration / 60) . " minutes. Close this connection to cool it's IP");
            }
        }
    }*/

    public static function actionBeforeMainOutputLoopIteration()
    {
        global $MAIN_OUTPUT_LOOP_LAST_ITERATION;

        if (!$MAIN_OUTPUT_LOOP_LAST_ITERATION) {
            return;
        }

        $runningInstances = static::sortInstancesArrayByEfficiencyLevel(static::getRunningInstances());
        $runningInstancesCount = count($runningInstances);
        $weakestInstancesCount = intRound($runningInstancesCount * 0.2);

        for ($i = 0; $i < $weakestInstancesCount; $i++) {
            $puppeteerApplication = $runningInstances[$i];
            $puppeteerApplication->requireTerminate('Lowest response rate (' . $puppeteerApplication->getEfficiencyLevel() . '%)');
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
        if ($SESSIONS_COUNT % 20 === 0) {
            static::terminateInstances();
        }
    }

    public static function cleanTmpDirStep2()
    {
        global $SESSIONS_COUNT, $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT, $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT;
        if ($SESSIONS_COUNT % 20 === 0) {
            static::killInstances();
            cleanTmpDir();
            $PUPPETEER_DDOS_CONNECTIONS_COUNT_INT = $PUPPETEER_DDOS_CONNECTIONS_INITIAL_INT;
        }
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

}

PuppeteerApplicationStatic::constructStatic();