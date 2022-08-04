<?php

class PuppeteerApplication extends HackApplication
{
    const MAX_ATTACK_DURATION = 30 * 60;

    private $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $stat,
            $jsonDataReceivedDuringThisSession,
            $stdoutBrokenLineCount,
            $stdoutBuffer = '',
            $statisticsBadgePreviousRet = '',
            $requireBlockerBypass = 0,
            $totalLinksCount = 0,
            $workingDirectory,
            $exitCode = -1;

    public function processLaunch()
    {
        global $IS_IN_DOCKER, $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX;

        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        //---

        $this->workingDirectory = static::$workingDirectoryRoot . '/VPN' . $this->vpnConnection->getIndex();
        rmdirRecursive($this->workingDirectory);
        mkdir($this->workingDirectory, changeLinuxPermissions(0, 'rwx', 'rwx'));
        chmod($this->workingDirectory, changeLinuxPermissions(0, 'rwx', 'rwx'));
        chown($this->workingDirectory, 'user');
        chgrp($this->workingDirectory, 'user');

        $caHeadless                = $IS_IN_DOCKER  ?  '  --headless' : '';
        $caBrowserVisible          = $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX  ?  '  --browser-visible' : '';

        $googleVisionKeyPath = Config::$putYourOvpnFilesHerePath . '/google-vision-key.json';
        $caGoogleVisionKey = $googleVisionKeyPath ? '  --google-vision-key-path="' . $googleVisionKeyPath . '"' : '';

        $command = 'cd "' . __DIR__ . '" ;   '
                 . 'ip netns exec ' . $this->vpnConnection->getNetnsName() . '   '
                 . "nice -n 10   /sbin/runuser  -u user  -g user   --   "
                 . static::$cliAppPath . '  '
                 . '  --connection-index=' . $this->vpnConnection->getIndex()
                 . "  --working-directory=\"{$this->workingDirectory}\""
                 .    $caHeadless
                 .    $caBrowserVisible
                 .    $caGoogleVisionKey
                 . '  2>&1';

        $this->log($command);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $this->process = proc_open($command, $descriptorSpec, $this->pipes);
        $this->processPGid = procChangePGid($this->process, $log);
        $this->log($log);
        if ($this->processPGid === false) {
            $this->terminateAndKill(true);
            $this->log('Command failed: ' . $command);
            $this->launchFailed = true;
            return -1;
        }

        stream_set_blocking($this->pipes[1], false);
        $this->wasLaunched = true;
        $this->stat = new \stdClass();
        $this->stat->targets = [];
        $this->stat->total = null;
        return true;
    }

    public function pumpLog($flushBuffers = false) : string
    {
        $ret = $this->log;
        $this->log = '';

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read stdout -------------------

        $this->stdoutBuffer .= streamReadLines($this->pipes[1], 0);
        if ($flushBuffers) {
            $ret = $this->stdoutBuffer;
        } else {
            // --- Split lines
            $lines = mbSplitLines($this->stdoutBuffer);
            // --- Remove terminal markup
            $lines = array_map('\Term::removeMarkup', $lines);
            // --- Remove empty lines
            $lines = mbRemoveEmptyLinesFromArray($lines);

            foreach ($lines as $lineIndex => $line) {
                $lineObj = json_decode($line);

                if (is_object($lineObj)) {
                    unset($lines[$lineIndex]);
                    $this->stdoutBrokenLineCount = 0;
                    $ret .= $this->processJsonLine($line, $lineObj);
                } else if (
                        !$this->stdoutBrokenLineCount
                    &&  mb_substr($line, 0, 1) !== '{'
                ) {
                    unset($lines[$lineIndex]);
                    $ret .= $line;
                } else {
                    $this->stdoutBrokenLineCount++;
                    if ($this->stdoutBrokenLineCount > 3) {
                        $this->stdoutBrokenLineCount = 0;
                        $ret .= $line . "\n";
                        unset($lines[$lineIndex]);
                    }
                    break;
                }
            }
            $this->stdoutBuffer = implode("\n", $lines);
        }

        retu:
        $ret = mbRTrim($ret);

        return $ret;
    }

    private function processJsonLine($line, $lineObj)
    {
        $ret = '';
        $this->jsonDataReceivedDuringThisSession = true;

        $newStatItem = new \stdClass();
        $newStatItem->httpRequestsSent               = 0;
        $newStatItem->httpEffectiveResponsesReceived = 0;
        $newStatItem->navigateTimeouts               = 0;
        $newStatItem->httpRenderRequestsSent         = 0;
        $newStatItem->ddosBlockedRequests            = 0;
        $newStatItem->sumDuration                    = 0;

        $newStatItem->captchasWereFound              = 0;

        if (!$this->stat->total) {
            $this->stat->total = clone $newStatItem;
        }

        // ----------------------------------------

        if (isset($lineObj->requireBlockerBypass)) {
            $this->requireBlockerBypass = $lineObj->requireBlockerBypass;
        }

        if (isset($lineObj->totalLinksCount)) {
            $this->totalLinksCount = $lineObj->totalLinksCount;
        }

        // ----------------------------------------

        $requestType = val($lineObj, 'type');
        if ($requestType === 'terminate') {
            $this->stat->targets = [];
            return 'Exit message: ' . val($lineObj, 'message');

        } else if ($requestType  &&  in_array($requestType, ['http-plain-get', 'http-render-get'])) {

            $entryUrl        = getUrlOrigin(val($lineObj, 'navigateUrl'));
            $success         = val($lineObj, 'success');
            $duration        = val($lineObj, 'duration');
            $navigateTimeout = val($lineObj, 'navigateTimeout');
            $requireBlockerBypass = val($lineObj, 'requireBlockerBypass');
            $captchaWasFound = val($lineObj, 'captchaWasFound');

            $targetItem = $this->stat->targets[$entryUrl] ?? null;
            if (!$targetItem) {
                $targetItem = clone $newStatItem;
            }

            $this->stat->total->httpRequestsSent++;
                   $targetItem->httpRequestsSent++;
                   
            if ($success) {
                $this->stat->total->httpEffectiveResponsesReceived++;
                       $targetItem->httpEffectiveResponsesReceived++;
            }

            if ($navigateTimeout) {
                $this->stat->total->navigateTimeouts++;
                       $targetItem->navigateTimeouts++;
            }

            if ($requestType === 'http-render-get') {
                $this->stat->total->httpRenderRequestsSent++;
                       $targetItem->httpRenderRequestsSent++;
            }

            if ($requireBlockerBypass) {
                $this->stat->total->ddosBlockedRequests++;
                       $targetItem->ddosBlockedRequests++;
            }            

            if ($duration) {
                $this->stat->total->sumDuration += $duration;
                       $targetItem->sumDuration += $duration;
            }

            if (
                    $requestType === 'http-render-get'
                &&  $captchaWasFound
            ) {
                $this->stat->total->captchasWereFound++;
                       $targetItem->captchasWereFound++;
            }

            $this->stat->targets[$entryUrl] = $targetItem;

        } else if (!$this->debug) {
            $ret .= $this->lineObjectToString($lineObj) . "\n\n";
        }

        if ($this->debug) {
            $ret .= $this->lineObjectToString($lineObj) . "\n\n";
        }

        // ----------------------------------------

        if (
               $this->stat->total->httpRequestsSent >= 10
            && $this->stat->total->httpEffectiveResponsesReceived === 0
        ) {
            $this->terminateMessage = "Can't connect to target website through current Internet connection or VPN";
            $this->requireTerminate = true;
        } else if (
               $this->stat->total->httpRequestsSent >= 10
            && $this->totalLinksCount < 10
        ) {
            $this->terminateMessage = "Not enough links collected ({$this->totalLinksCount})";
            $this->requireTerminate = true;
        } else if (
               $this->stat->total->httpRenderRequestsSent > 20
            && $this->stat->total->httpRequestsSent / $this->stat->total->httpRenderRequestsSent < 100
        ) {
            $this->terminateMessage = "Too many render requests";
            $this->requireTerminate = true;
        } else if (
               $this->stat->total->captchasWereFound > 5
            && $this->stat->total->httpRequestsSent / $this->stat->total->captchasWereFound < 500
        ) {
            $this->terminateMessage = "Too many captcha";
            $this->requireTerminate = true;
        }

        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        
        if (!count($this->stat->targets)) {
            return null;
        }

        $columnsDefinition = [
            [
                'title' => ['Target'],
                'width' => $LOG_WIDTH - $LOG_PADDING_LEFT - 12 * 6,
                'trim'  => 4
            ],
            [
                'title' => ['Requests', 'sent'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],

            [
                'title' => ['Effective' , 'responses'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Navigate' , 'timeouts'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Render', 'requests'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Anti-DDoS' , 'block'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Average' , 'duration'],
                'width' => 12,
                'trim'  => 2,
                'alignRight' => true
            ],
        ];
        $rows[] = [];  // new line

        foreach ($this->stat->targets as $targetName => $targetStat) {
            $row = [
                $targetName,
                $targetStat->httpRequestsSent,
                $targetStat->httpEffectiveResponsesReceived,
                $targetStat->navigateTimeouts,
                $targetStat->httpRenderRequestsSent,
                $targetStat->ddosBlockedRequests,
                roundLarge($targetStat->sumDuration / $targetStat->httpRequestsSent / 1000)
            ];
            $rows[] = $row;
        }

        $rows[] = [];  // new line
        $rows[] = [
            'Total',
            $this->stat->total->httpRequestsSent,
            $this->stat->total->httpEffectiveResponsesReceived,
            $this->stat->total->navigateTimeouts,
            $this->stat->total->httpRenderRequestsSent,
            $this->stat->total->ddosBlockedRequests,
            roundLarge($this->stat->total->sumDuration / $this->stat->total->httpRequestsSent / 1000)
        ];

        $ret = mbRTrim(generateMonospaceTable($columnsDefinition, $rows));
        if ($ret === $this->statisticsBadgePreviousRet) {
            return null;
        } else {
            $this->statisticsBadgePreviousRet = $ret;
            return $ret;
        }
    }

    // Should be called after pumpLog()
    public function getEfficiencyLevel()
    {
        $requests           = $this->stat->total->httpRequestsSent                ??  0;
        $effectiveResponses = $this->stat->total->httpEffectiveResponsesReceived  ??  0;
        $navigateTimeouts   = $this->stat->total->navigateTimeouts  ??  0;

        if ($requests < 50) {
            return null;
        }

        if ($effectiveResponses > 10) {
            $averageResponseRate = ($effectiveResponses + $navigateTimeouts) * 100 / $requests;
        } else {
            $averageResponseRate = $effectiveResponses * 100 / $requests;
        }

        return roundLarge($averageResponseRate);
    }

    // Should be called after pumpLog()
    public function getCurrentCountry()
    {
        return null;
    }

    public function isAlive()
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $this->getExitCode();

        $processStatus = proc_get_status($this->process);
        return $processStatus['running'];
    }

    public function getExitCode()
    {
        $processStatus = proc_get_status($this->process);  // Only first call of this function return real value, next calls return -1.

        if ($processStatus  &&  $processStatus['exitcode'] !== -1) {
            $this->exitCode = $processStatus['exitcode'];
        }
        return $this->exitCode;
    }

    public function terminate($hasError)
    {
        $this->exitCode = $hasError  ?  1 : 0;

        if ($this->processPGid) {
            $networkStats = $this->vpnConnection->calculateNetworkStats();
            static::$closedPuppeteerApplicationsNetworkStats->received    += $networkStats->total->received;
            static::$closedPuppeteerApplicationsNetworkStats->transmitted += $networkStats->total->transmitted;
            static::$closedPuppeteerApplicationsNetworkStats->effectiveResponsesReceived += $this->stat->total->httpEffectiveResponsesReceived ?? 0;

            // ---

            $this->log("puppeteer-ddos.cli.js terminate PGID -{$this->processPGid}", true);
            @posix_kill(0 - $this->processPGid, SIGTERM);
            // ---
            $subProcessesPids = [];
            getProcessPidWithChildrenPids($this->processPGid, true, $subProcessesPids);
            if (count($subProcessesPids)) {
                $this->log('; browser PIDs:', true);
                foreach ($subProcessesPids as $subProcessPid) {
                    $this->log(' ' . $subProcessPid, true);
                    @posix_kill($subProcessPid, SIGTERM);
                }
            }
        }
    }

    public function kill()
    {
        if ($this->processPGid) {
            $this->log("puppeteer-ddos.cli.js kill PGID -{$this->processPGid}", true);
            @posix_kill(0 - $this->processPGid, SIGKILL);
            // ---
            $subProcessesPids = [];
            getProcessPidWithChildrenPids($this->processPGid, true, $subProcessesPids);
            if (count($subProcessesPids)) {
                $this->log('; browser PIDs:', true);
                foreach ($subProcessesPids as $subProcessPid) {
                    $this->log(' ' . $subProcessPid, true);
                    @posix_kill($subProcessPid, SIGKILL);
                }
            }
        }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);

        rmdirRecursive($this->workingDirectory);
    }

    public function isWaitingForCaptchaResolution()
    {
        return $this->requireBlockerBypass === 2;
    }

    public function sendStdinCommand($command)
    {
        fputs($this->pipes[0], "$command\n");
    }

    // ----------------------  Static part of the class ----------------------

    private static int     $puppeteerApplicationStartedDuringThisSession = 0;
    private static bool    $showCaptchaBrowsersSentDuringThisSession = false;
    private static string  $workingDirectoryRoot,
                           $cliAppPath;
    private static object  $closedPuppeteerApplicationsNetworkStats,
                           $runningPuppeteerApplicationsNetworkStats,
                           $runningPuppeteerApplicationsNetworkStatsThisSession;
   private static int      $runningPuppeteerApplicationsCount;

    public static function constructStatic()
    {
        global $TEMP_DIR;

        static::$workingDirectoryRoot = $TEMP_DIR . '/puppeteer-ddos';
        static::$cliAppPath = __DIR__ . "/secret/puppeteer-ddos.cli.js";
        if (!file_exists(static::$cliAppPath)) {
            static::$cliAppPath = __DIR__ . "/puppeteer-ddos-dist.cli.js";
        }

        static::$closedPuppeteerApplicationsNetworkStats = new \stdClass();
        static::$closedPuppeteerApplicationsNetworkStats->received = 0;
        static::$closedPuppeteerApplicationsNetworkStats->transmitted = 0;
        static::$closedPuppeteerApplicationsNetworkStats->effectiveResponsesReceived = 0;

        static::$runningPuppeteerApplicationsNetworkStats = new \stdClass();
        static::$runningPuppeteerApplicationsNetworkStatsThisSession = new \stdClass();
        static::$runningPuppeteerApplicationsCount = 0;

        rmdirRecursive(static::$workingDirectoryRoot);
        mkdir(static::$workingDirectoryRoot);
        chmod(static::$workingDirectoryRoot, changeLinuxPermissions(0, 'rwx', 'rwx'));
        chown(static::$workingDirectoryRoot, 'user');
        chgrp(static::$workingDirectoryRoot, 'user');

        killZombieProcesses('chrome');
        killZombieProcesses('nodejs');

        Actions::addAction('AfterInitSession',             [static::class, 'actionAfterInitSession']);
        Actions::addAction('BeforeTerminateSession',       [static::class, 'actionBeforeTerminateSession']);
        Actions::addFilter('OpenVpnStatisticsBadge',        [static::class, 'filterOpenVpnStatisticsBadge']);
        Actions::addFilter('OpenVpnStatisticsSessionBadge', [static::class, 'filterOpenVpnStatisticsSessionBadge']);
        Actions::addAction('BeforeMainOutputLoopIterations',[static::class, 'closeInstances']);
        Actions::addAction('AfterMainOutputLoopIterations', [static::class, 'showCaptchaBrowsers']);
    }

    public static function actionAfterInitSession()
    {
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
            } else if ($networkStats->total->duration > static::MAX_ATTACK_DURATION) {
                $puppeteerApplication->terminateMessage = 'Attack lasts ' . intRound($networkStats->total->duration / 60) . " minutes. Close this connection to cool it's IP";
                $puppeteerApplication->requireTerminate = true;
            }
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
            if ($puppeteerApplication->isWaitingForCaptchaResolution()) {
                $puppeteerApplication->sendStdinCommand('show-captcha-browsers');
                if (!$soundWasPlayed) {
                    _shell_exec('/usr/bin/music123 /usr/share/sounds/freedesktop/stereo/complete.oga');
                    $soundWasPlayed = true;
                }
            }
        }

        static::$showCaptchaBrowsersSentDuringThisSession = true;
    }

    public static function getNewObject($vpnConnection)
    {
        global $PARALLEL_VPN_CONNECTIONS_QUANTITY,
               $PUPPETEER_DDOS_CONNECTIONS_QUOTA, $PUPPETEER_DDOS_ADD_CONNECTIONS_PER_SESSION;

        $puppeteerApplicationRunningInstancesCount = count(PuppeteerApplication::getRunningInstances());
        $newPuppeteerApplicationInstancesCount     = $puppeteerApplicationRunningInstancesCount - static::$runningPuppeteerApplicationsCount;

        if (
            $PUPPETEER_DDOS_CONNECTIONS_QUOTA
            &&  $puppeteerApplicationRunningInstancesCount  <  $PUPPETEER_DDOS_CONNECTIONS_QUOTA / 100 * $PARALLEL_VPN_CONNECTIONS_QUANTITY
            &&  $newPuppeteerApplicationInstancesCount      <  $PUPPETEER_DDOS_ADD_CONNECTIONS_PER_SESSION
        ) {
            static::$puppeteerApplicationStartedDuringThisSession++;
            return new PuppeteerApplication($vpnConnection);
        } else {
            return false;
        }
    }
}

PuppeteerApplication::constructStatic();