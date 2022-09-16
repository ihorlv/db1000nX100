<?php

class PuppeteerApplication extends PuppeteerApplicationStatic
{
    protected   $wasLaunched = false,
                $launchFailed = false,

                $threadsRequestsStat = [],
                $threadsStates       = [],

                $statisticsBadgePreviousRet = '',

                $workingDirectory,
                $currentCountry = '';

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

        $caHeadless                = ($IS_IN_DOCKER  ||  !$PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX) ?  '  --headless' : '';
        $caBrowserVisible          = $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX  ?  '  --browser-visible' : '';

        $googleVisionKeyPath = Config::$putYourOvpnFilesHerePath . '/google-vision-key.json';
        $caGoogleVisionKey = file_exists($googleVisionKeyPath) ? '  --google-vision-key-path="' . $googleVisionKeyPath . '"' : '';
        $caVpnTitle     = '  --vpn-title="' . $this->vpnConnection->getTitle() . '"';
        $caGeoIpCountry = '  --geo-ip-country="' . $this->getCurrentCountry() . '"';

        $command = 'cd "' . __DIR__ . '" ;   '
                 . 'ip netns exec ' . $this->vpnConnection->getNetnsName() . '   '
                 . "nice -n 10   /sbin/runuser  -u user  -g user   --   "
                 . static::$cliAppPath . '  '
                 . '  --connection-index=' . $this->vpnConnection->getIndex()
                 . "  --working-directory=\"{$this->workingDirectory}\""
                 . '  --enable-stdin-commands'
                 .    $caHeadless
                 .    $caBrowserVisible
                 .    $caGoogleVisionKey
                 .    $caVpnTitle
                 .    $caGeoIpCountry
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
        $ret = '';

        // ----------------------------------------

        $threadId = val($lineObj, 'threadId');
        $requestType = val($lineObj, 'type');

        if ($threadId === null  ||  !$requestType) {
            return $line;
        }

        $threadState        = $this->threadsStates[$threadId]       ?? null;
        $threadRequestsStat = $this->threadsRequestsStat[$threadId] ?? null;

        if (!$threadRequestsStat ||  !$threadState) {
            $threadState        = static::newThreadStateItem();
            $threadRequestsStat = static::newThreadRequestsStatItem();
        }

        $threadState->dataReceivedDuringThisSession = true;

        if ($requestType === 'terminate') {
            $code = val($lineObj, 'code');
            $threadState->terminateReasonCode = $code;
        } else if (in_array($requestType, ['http-plain-get', 'http-render-get'])) {

            $entryUrl = getUrlOrigin(val($lineObj, 'navigateUrl'));
            if ($entryUrl  &&  !$threadState->entryUrl) {
                $threadState->entryUrl = $entryUrl;
            }

            // ---

            $threadRequestsStat['httpRequestsSent']++;

            // ---

            $success = val($lineObj, 'success');
            if ($success) {
                $threadRequestsStat['httpEffectiveResponsesReceived']++;
            }

            // ---

            $navigateTimeout = val($lineObj, 'navigateTimeout');
            if ($navigateTimeout) {
                $threadRequestsStat['navigateTimeouts']++;
            }

            // ---

            $httpStatusCode5xx = val($lineObj, 'httpStatusCode5xx');
            if ($httpStatusCode5xx) {
                $threadRequestsStat['httpStatusCode5xx']++;
            }

            // ---

            if ($requestType === 'http-render-get') {
                 $threadRequestsStat['httpRenderRequestsSent']++;
            } else {
                $duration = val($lineObj, 'duration');
                $duration /= 1000; // Don't round here. We need floating point, because it can fit very large numbers
                $threadRequestsStat['sumPlainDuration'] += $duration;
            }

            // ---

            $requireBlockerBypass = val($lineObj, 'requireBlockerBypass');
            if ($requireBlockerBypass) {
                $threadRequestsStat['ddosBlockedRequests']++;
            }

            $captchaWasFound = val($lineObj, 'captchaWasFound');
            if ($captchaWasFound) {
                 $threadRequestsStat['captchasWereFound']++;
            }

            // ---

            $browserWasWaitingForFreeRam = val($lineObj, 'browserWasWaitingForFreeRam');
            if ($browserWasWaitingForFreeRam) {
                $threadState->browserWasWaitingForFreeRamDuringThisSession = true;
            }

            // ---

            $lineTotalLinksCount = val($lineObj, 'totalLinksCount');
            if ($lineTotalLinksCount > $threadState->totalLinksCount) {
                $threadState->totalLinksCount = $lineTotalLinksCount;
            }

            // ---

            $proxy = val($lineObj, 'proxy');
            if ($proxy) {
                if (!$threadState->usingProxy) {
                    $threadState->usingProxy = true;
                }

                $threadRequestsStat['httpRequestsSentViaProxy']++;
                if ($success) {
                    $threadRequestsStat['httpEffectiveResponsesReceivedViaProxy']++;
                }
            }

        }

        $this->threadsStates[$threadId]       = $threadState;
        $this->threadsRequestsStat[$threadId] = $threadRequestsStat;

        $ret .= $this->lineObjectToString($lineObj) . "\n\n";
        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        
        if (!count($this->threadsRequestsStat)) {
            return null;
        }

        foreach ($this->threadsStates as $threadId => $threadState) {
            $threadRequestsStat = $this->threadsRequestsStat[$threadId];

            if (
                    !$threadState->parentTerminateRequestSent
                &&  !$threadState->terminateReasonCode
                &&   $threadState->dataReceivedDuringThisSession
            ) {
                $code = '';

                if (
                       !$threadState->usingProxy
                    && $threadRequestsStat['httpRequestsSent'] >= 20
                    && $threadRequestsStat['httpEffectiveResponsesReceived'] + $threadRequestsStat['httpStatusCode5xx'] === 0
                ) {
                    // Can't connect to target website through current Internet connection or VPN
                    $code = 'connect-';
                } else if (
                        $threadRequestsStat['httpRequestsSent'] >= 20
                    &&  $threadState->totalLinksCount      !== null
                    &&  $threadState->totalLinksCount      < 10
                ) {
                    // Not enough links collected
                    $code = 'links-';
                } else if (
                       $threadRequestsStat['httpRenderRequestsSent'] > 20
                    && $threadRequestsStat['httpRequestsSent'] / $threadRequestsStat['httpRenderRequestsSent'] < 50
                ) {
                    // Too many render requests
                    $code = 'render+';
                } else if (
                       $threadRequestsStat['httpRequestsSent']  > 20
                    && $threadRequestsStat['captchasWereFound'] > 5
                    && $threadRequestsStat['httpRequestsSent'] / $threadRequestsStat['captchasWereFound'] < 100
                ) {
                    // Too many captcha
                    $code = 'captcha+';
                } /*else if ($threadStat['httpRequestsSent'] > 5) {
                    // Test kill
                    $code = 'test!';
                }*/

                if ($code) {
                    $this->sendStdinCommand(
                        (object) [
                            'name'     => 'terminateThreadFromParent',
                            'threadId' => $threadId,
                            'code'     => $code
                        ]
                    );

                    $threadState->parentTerminateRequestSent = true;
                }
            }

            $this->threadsStates[$threadId]       = $threadState;
            $this->threadsRequestsStat[$threadId] = $threadRequestsStat;
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

            $sum = $sumPerEntryUrl[$threadState->entryUrl]  ??  static::newThreadsSumItem();
            $sum['threadsRequestsStat'] = sumSameArrays($sum['threadsRequestsStat'], $threadRequestsStat);
            if (!(
                    $threadState->terminateReasonCode
                ||  $threadState->parentTerminateRequestSent
            )) {
                $sum['runningThreads']++;
            } else if ($threadState->terminateReasonCode) {
                $count = $sum['terminateReasonCodesCount'][$threadState->terminateReasonCode]  ??  0;
                $sum['terminateReasonCodesCount'][$threadState->terminateReasonCode] = $count + 1;
            }
            $sumPerEntryUrl[$threadState->entryUrl] = $sum;
        }
        ksort($sumPerEntryUrl);

        // ----------------------------------------------

        $sumTotal = static::newThreadsSumItem();
        foreach ($sumPerEntryUrl as $threadEntryUrl => $sum) {
            $sumTotal = sumSameArrays($sumTotal, $sum);

            $urlState = '';
            if ($sum['runningThreads']) {
                $urlState = $sum['runningThreads'];
            } else {
                asort($sum['terminateReasonCodesCount']);

                if (getArrayLastValue($sum['terminateReasonCodesCount'])) {
                    $urlState = array_key_last($sum['terminateReasonCodesCount']);
                }
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
                roundLarge($sum['threadsRequestsStat']['sumPlainDuration'] / $sum['threadsRequestsStat']['httpRequestsSent'], 1)
            ];
            $rows[] = $row;
        }
        
        // ----------------------------------------------

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
            roundLarge($sumTotal['threadsRequestsStat']['sumPlainDuration'] / $sumTotal['threadsRequestsStat']['httpRequestsSent'], 1)

        ];
        $ret = generateMonospaceTable($columnsDefinition, $rows);

        // ----------------------------------------------

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

    // Should be called after pumpLog()
    public function getCurrentCountry()
    {
        if ($this->currentCountry) {
            return $this->currentCountry;
        }

        try {
            $record = static::$maxMindGeoLite2->country($this->vpnConnection->getVpnPublicIp());
            $this->currentCountry = $record->country->name;
        } catch (\Exception $e) {
            $this->currentCountry = '';
        }

        return $this->currentCountry;
    }

    public function terminate($hasError)
    {
        $this->exitCode = $hasError  ?  1 : 0;

        if ($this->processPGid) {

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

    public function sendStdinCommand($commandObject)
    {
        $commandJson = json_encode($commandObject);
        @fputs($this->pipes[0], "$commandJson\n");
    }

}

