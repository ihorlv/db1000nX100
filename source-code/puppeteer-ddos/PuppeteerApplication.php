<?php

class PuppeteerApplication extends PuppeteerApplicationStatic
{
    protected   $wasLaunched = false,
                $launchFailed = false,

                $threadsRequestsStat = [],
                $threadsStates       = [],

                $workingDirectory;


    public function processLaunch()
    {
        global $IS_IN_DOCKER, $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX, $FIXED_VPN_QUANTITY;

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

        $caConnectionIndex = '--connection-index=' . $this->vpnConnection->getIndex();
        $caWorkingDirectory = "--working-directory=\"{$this->workingDirectory}\"";
        $caVpnTitle     = '--vpn-title="' . $this->vpnConnection->getTitle() . '"';
        $caGeoIpCountry = '--geo-ip-country="' . $this->vpnConnection->getCurrentCountry() . '"';
        $googleVisionKeyPath = Config::$putYourOvpnFilesHerePath . '/google-vision-key.json';
        $caGoogleVisionKey = file_exists($googleVisionKeyPath) ? '--google-vision-key-path="' . $googleVisionKeyPath . '"' : '';

        $caHeadless                = ($IS_IN_DOCKER  ||  !$PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX) ?  '--headless' : '';
        $caBrowserVisible          = $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX  ?  '--browser-visible' : '';
        $caDebug                   = (SelfUpdate::$isDevelopmentVersion  &&  $FIXED_VPN_QUANTITY === 1)  ?  '--debug' : '';

        $command = 'cd "' . __DIR__ . '" ;   '
                 . 'ip netns exec ' . $this->vpnConnection->getNetnsName() . '   '
                 . "nice -n 10   /sbin/runuser  -u user  -g user   --   "
                 . static::$cliAppPath . '  '
                 . '  --enable-stdin-commands'
                 . "  $caConnectionIndex"
                 . "  $caWorkingDirectory"
                 . "  $caHeadless"
                 . "  $caBrowserVisible"
                 . "  $caGoogleVisionKey"
                 . "  $caVpnTitle"
                 . "  $caGeoIpCountry"
                 . "  $caDebug"
                 . '  2>&1';

        $this->log('Launching PuppeteerDDoS on VPN' . $this->vpnConnection->getIndex());
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
        return true;
    }

    protected function processJsonLine($line, $lineObj)
    {
        $requestType = val($lineObj, 'type');

        // ---

        if ($requestType === 'process-die') {
            $message = val($lineObj, 'message');
            $this->requireTerminate($message);
            goto reto;
        }

        // ---

        $threadId = val($lineObj, 'threadId');

        if ($threadId === null  ||  !$requestType) {
            goto reto;
        }

        $threadState        = $this->threadsStates[$threadId]       ?? null;
        $threadRequestsStat = $this->threadsRequestsStat[$threadId] ?? null;

        if (!$threadRequestsStat  ||  !$threadState) {
            $threadState        = static::newThreadStateItem();
            $threadRequestsStat = static::newThreadRequestsStatItem();
        }

        $threadState->dataReceivedDuringThisSession = true;

        if ($requestType === 'terminate') {

            $code = val($lineObj, 'code');
            $threadState->terminateReasonCode = $code;

        } else if ($requestType === 'statistics') {

            if (!$threadState->entryUrl) {
                $threadState->entryUrl = $lineObj->entryUrl;
            }

            $threadState->totalLinksCount = $lineObj->totalLinksCount;
            $threadState->browserWasWaitingForFreeRamAt = $lineObj->browserWasWaitingForFreeRamAt;

            if ($lineObj->httpRequestsSentViaProxy) {
                $threadState->usingProxy = true;
            }

            // ---

            $threadRequestsStat['httpRequestsSent']                       = $lineObj->httpRequestsSent;
            $threadRequestsStat['httpEffectiveResponsesReceived']         = $lineObj->httpEffectiveResponsesReceived;
            $threadRequestsStat['httpRequestsSentViaProxy']               = $lineObj->httpRequestsSentViaProxy;
            $threadRequestsStat['httpEffectiveResponsesReceivedViaProxy'] = $lineObj->httpEffectiveResponsesReceivedViaProxy;
            $threadRequestsStat['httpRenderRequestsSent']                 = $lineObj->httpRenderRequestsSent;
            $threadRequestsStat['navigateTimeouts']                       = $lineObj->navigateTimeouts;
            $threadRequestsStat['httpStatusCode5xx']                      = $lineObj->httpStatusCode5xx;
            $threadRequestsStat['ddosBlockedRequests']                    = $lineObj->ddosBlockedRequests;
            $threadRequestsStat['captchasWereFound']                      = $lineObj->captchasWereFound;
            $threadRequestsStat['captchasWereResolved']                   = $lineObj->captchasWereResolved;
            $threadRequestsStat['sumPlainDuration']                       = $lineObj->sumPlainDuration;
        }

        $this->threadsStates[$threadId]       = $threadState;
        $this->threadsRequestsStat[$threadId] = $threadRequestsStat;

        reto:

        $ret = $this->lineObjectToString($lineObj) . "\n\n";
        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge($returnSamePrevious = false) : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;

        $this->statisticsBadge = null;
        
        if (!count($this->threadsRequestsStat)) {
            goto retu;
        }

        $columnsDefinition = [
            [
                'title' => ['Target'],
                'width' => 0,
                'trim'  => 2
            ],
            [
                'title' => ['Threads', 'or stop'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Requests', 'sent'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Effective' , 'responses'],
                'width' => 11,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Render', 'requests'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Navigate' , 'timeouts'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Http' , '5xx'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Anti-DDoS' , 'block'],
                'width' => 11,
                'trim'  => 2,
                'alignRight' => true
            ],
            [
                'title' => ['Average' , 'duration'],
                'width' => 10,
                'trim'  => 2,
                'alignRight' => true
            ],
        ];
        $columnsDefinition[0]['width'] = $LOG_WIDTH - $LOG_PADDING_LEFT - array_sum(array_column($columnsDefinition, 'width'));

        $rows[] = [];  // new line

        // ---

        $sumPerEntryUrl = [];
        foreach ($this->threadsStates as $threadId => $threadState) {
            $threadRequestsStat = $this->threadsRequestsStat[$threadId];

            if ($threadState->entryUrl) {
                $entryUrl = ($threadState->usingProxy  ?  'P ' : 'D ') . $threadState->entryUrl;
            } else {
                $entryUrl = '';
            }

            $sum = $sumPerEntryUrl[$entryUrl]  ??  static::newThreadsSumItem();
            $sum['threadsRequestsStat'] = sumSameArrays($sum['threadsRequestsStat'], $threadRequestsStat);
            if ($threadState->terminateReasonCode) {
                $count = $sum['terminateReasonCodesCount'][$threadState->terminateReasonCode]  ??  0;
                $sum['terminateReasonCodesCount'][$threadState->terminateReasonCode] = $count + 1;
            } else {
                $sum['runningThreads']++;
            }
            $sumPerEntryUrl[$entryUrl] = $sum;
        }

        // ----------------------------------------------

        ksort($sumPerEntryUrl);
        $sumTotal = static::newThreadsSumItem();
        foreach ($sumPerEntryUrl as $threadEntryUrl => $sum) {
            $sumTotal = sumSameArrays($sumTotal, $sum);

            // ---

            $urlState = '';
            if ($sum['runningThreads']) {
                $urlState = $sum['runningThreads'];
            } else {
                asort($sum['terminateReasonCodesCount']);

                if (getArrayLastValue($sum['terminateReasonCodesCount'])) {
                    $urlState = array_key_last($sum['terminateReasonCodesCount']);
                }
            }

            // ---

            if ($sum['threadsRequestsStat']['httpRequestsSent']) {
                $averageRequestDuration = roundLarge($sum['threadsRequestsStat']['sumPlainDuration'] / $sum['threadsRequestsStat']['httpRequestsSent'], 1);
            } else {
                $averageRequestDuration = 0;
            }

            $row = [
                $threadEntryUrl,
                $urlState,
                $sum['threadsRequestsStat']['httpRequestsSent'],
                $sum['threadsRequestsStat']['httpEffectiveResponsesReceived'],
                $sum['threadsRequestsStat']['httpRenderRequestsSent'],
                $sum['threadsRequestsStat']['navigateTimeouts'],
                $sum['threadsRequestsStat']['httpStatusCode5xx'],
                $sum['threadsRequestsStat']['ddosBlockedRequests'],
                $averageRequestDuration
            ];
            $rows[] = $row;
        }
        
        // ----------------------------------------------

        if ($sumTotal['threadsRequestsStat']['httpRequestsSent']) {
            $totalAverageRequestDuration = roundLarge($sumTotal['threadsRequestsStat']['sumPlainDuration'] / $sumTotal['threadsRequestsStat']['httpRequestsSent'], 1);
        } else {
            $totalAverageRequestDuration = 0;
        }

        // ---

        $rows[] = [];  // new line
        $rows[] = [
            'Total',
            $sumTotal['runningThreads'] ?: '',
            $sumTotal['threadsRequestsStat']['httpRequestsSent'],
            $sumTotal['threadsRequestsStat']['httpEffectiveResponsesReceived'],
            $sumTotal['threadsRequestsStat']['httpRenderRequestsSent'],
            $sumTotal['threadsRequestsStat']['navigateTimeouts'],
            $sumTotal['threadsRequestsStat']['httpStatusCode5xx'],
            $sumTotal['threadsRequestsStat']['ddosBlockedRequests'],
            $totalAverageRequestDuration
        ];

        $this->statisticsBadge = generateMonospaceTable($columnsDefinition, $rows);

        retu:

        return parent::getStatisticsBadge($returnSamePrevious);
    }

    // Should be called after pumpLog()
    public function getEfficiencyLevel()
    {
        if (!count($this->threadsRequestsStat)) {
            return null;
        }

        $threadsStatTotal = sumSameArrays(...$this->threadsRequestsStat);

        if ($threadsStatTotal['httpRequestsSent'] > 20) {

            $averageResponseRate =
                ( $threadsStatTotal['httpEffectiveResponsesReceived']
                //+ $threadsStatTotal['httpStatusCode5xx']
                )

                / $threadsStatTotal['httpRequestsSent'] * 100;

        } else {
            return null;
        }

        return roundLarge($averageResponseRate);
    }

    public function terminate($hasError)
    {
        $this->exitCode = $hasError  ?  1 : 0;

        if ($this->processPGid) {

            $this->log("puppeteer-ddos.cli.js terminate PGID -{$this->processPGid}", true);
            @posix_kill(0 - $this->processPGid, SIGTERM);
            // ---
            $subProcessesPids = [];
            getProcessChildrenPids($this->processPGid, true, $subProcessesPids);
            if (count($subProcessesPids)) {
                $this->log('; children PIDs:', true);
                foreach ($subProcessesPids as $subProcessPid) {
                    $this->log(' ' . $subProcessPid, true);
                    @posix_kill($subProcessPid, SIGTERM);
                }
            }
        }

        $this->terminated = true;
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
                $this->log('; children PIDs:', true);
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

    public function sendStdinCommand($commandObject)
    {
        $commandJson = json_encode($commandObject);
        @fputs($this->pipes[0], "$commandJson\n");
    }

}

