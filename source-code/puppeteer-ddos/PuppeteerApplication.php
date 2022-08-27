<?php

class PuppeteerApplication extends PuppeteerApplicationStatic
{
    protected   $wasLaunched = false,
                $launchFailed = false,

                $threadsStat = [],
                $threadsEntryUrls = [],
                $statisticsBadgePreviousRet = '',

                $jsonDataReceivedDuringThisSession = false,
                $browserWasWaitingForFreeRamDuringThisSessiom = false,
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

        $caHeadless                = $IS_IN_DOCKER  ?  '  --headless' : '';
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
        $this->jsonDataReceivedDuringThisSession = true;

        // ----------------------------------------

        $threadId = val($lineObj, 'threadId');
        $requestType = val($lineObj, 'type');

        if (!$threadId || !$requestType) {
            return $ret;
        }

        $threadStat = $this->threadsStat[$threadId] ?? null;
        if (!$threadStat) {
            $threadStat = static::newThreadStatItem();
        }

        if ($requestType === 'terminate') {
            $code = val($lineObj, 'code');
            $threadStat['terminateReasonCodes'][$code] = 1;
        } else if ($requestType  &&  in_array($requestType, ['http-plain-get', 'http-render-get'])) {

            $entryUrl = getUrlOrigin(val($lineObj, 'navigateUrl'));
            if ($entryUrl  &&  !isset($this->threadsEntryUrls[$threadId])) {
                $this->threadsEntryUrls[$threadId] = $entryUrl;
            }

            // ---

            $threadStat['httpRequestsSent']++;

            // ---

            $success = val($lineObj, 'success');
            if ($success) {
                $threadStat['httpEffectiveResponsesReceived']++;
            }

            // ---

            $navigateTimeout = val($lineObj, 'navigateTimeout');
            if ($navigateTimeout) {
                $threadStat['navigateTimeouts']++;
            }

            // ---

            $httpStatusCode5xx = val($lineObj, 'httpStatusCode5xx');
            if ($httpStatusCode5xx) {
                $threadStat['httpStatusCode5xx']++;
            }

            // ---

            if ($requestType === 'http-render-get') {
                 $threadStat['httpRenderRequestsSent']++;
            } else {
                $duration = val($lineObj, 'duration');
                $duration /= 1000; // Don't round here. We need floating point, because it can fit very large numbers
                $threadStat['sumPlainDuration'] += $duration;
            }

            // ---

            $requireBlockerBypass = val($lineObj, 'requireBlockerBypass');
            if ($requireBlockerBypass) {
                $threadStat['ddosBlockedRequests']++;
            }

            $captchaWasFound = val($lineObj, 'captchaWasFound');
            if ($captchaWasFound) {
                 $threadStat['captchasWereFound']++;
            }

            // ---

            $browserWasWaitingForFreeRam = val($lineObj, 'browserWasWaitingForFreeRam');
            if ($browserWasWaitingForFreeRam) {
                $this->browserWasWaitingForFreeRamDuringThisSessiom = true;
            }

            // -----------------------------------------------

            if (
                    !$threadStat['parentTerminateRequests']
                &&  !array_sum($threadStat['terminateReasonCodes'])
            ) {

                $totalLinksCount = val($lineObj, 'totalLinksCount');
                $code = '';

                if (
                       $threadStat['httpRequestsSent'] >= 20
                    && $threadStat['httpEffectiveResponsesReceived'] === 0
                ) {
                    // Can't connect to target website through current Internet connection or VPN
                    $code = 'connect-';
                } else if (
                       $threadStat['httpRequestsSent'] >= 20
                    && $totalLinksCount < 10
                ) {
                    // Not enough links collected
                    $code = 'links-';
                } else if (
                       $threadStat['httpRenderRequestsSent'] > 20
                    && $threadStat['httpRequestsSent'] / $threadStat['httpRenderRequestsSent'] < 50
                ) {
                    // Too many render requests
                    $code = 'render+';
                } else if (
                       $threadStat['captchasWereFound'] > 5
                    && $threadStat['httpRequestsSent'] / $threadStat['captchasWereFound'] < 500
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

                    $threadStat['parentTerminateRequests'] = 1;
                }
            }

        // -----------------------------------------------
        } else if (!SelfUpdate::$isDevelopmentVersion) {
            $ret .= $this->lineObjectToString($lineObj) . "\n\n";
        }

        $this->threadsStat[$threadId] = $threadStat;

        if (SelfUpdate::$isDevelopmentVersion) {
            $ret .= $this->lineObjectToString($lineObj) . "\n\n";
        }

        return $ret;
    }

    // Should be called after pumpLog()
    public function getStatisticsBadge() : ?string
    {
        global $LOG_WIDTH, $LOG_PADDING_LEFT;
        
        if (!count($this->threadsStat)) {
            return null;
        }

        $columnsDefinition = [
            [
                'title' => ['Target'],
                'width' => 0,
                'trim'  => 4
            ],
            [
                'title' => ['Stop', 'reason'],
                'width' => 8,
                'trim'  => 0,
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
        foreach ($this->threadsStat as $threadId => $threadStat) {
            $maxReasonCountIndex = max($threadStat['terminateReasonCodes']);
            $reasonCode = $maxReasonCountIndex  ?  array_search($maxReasonCountIndex, $threadStat['terminateReasonCodes']) : '';

            $row = [
                $this->threadsEntryUrls[$threadId],
                $reasonCode,
                $threadStat['httpRequestsSent'],
                $threadStat['httpEffectiveResponsesReceived'],
                $threadStat['httpRenderRequestsSent'],
                $threadStat['navigateTimeouts'],
                $threadStat['httpStatusCode5xx'],
                $threadStat['ddosBlockedRequests'],
                roundLarge($threadStat['sumPlainDuration'] / $threadStat['httpRequestsSent'], 1)
            ];
            $rows[] = $row;
        }
        
        // ----------------------------------------------

        //print_r($this->threadsStat);
        $threadsStatTotal = sumSameArrays(...$this->threadsStat);
        if ($threadsStatTotal['httpRequestsSent']) {
            $rows[] = [];  // new line
            $rows[] = [
                'Total',
                '',
                $threadsStatTotal['httpRequestsSent'],
                $threadsStatTotal['httpEffectiveResponsesReceived'],
                $threadsStatTotal['httpRenderRequestsSent'],
                $threadsStatTotal['navigateTimeouts'],
                $threadsStatTotal['httpStatusCode5xx'],
                $threadsStatTotal['ddosBlockedRequests'],
                roundLarge($threadsStatTotal['sumPlainDuration'] / $threadsStatTotal['httpRequestsSent'], 1)
            ];
            $ret = generateMonospaceTable($columnsDefinition, $rows);
        }

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
        if (!count($this->threadsStat)) {
            return null;
        }

        $threadsStatTotal = sumSameArrays(...$this->threadsStat);

        if ($threadsStatTotal['httpRequestsSent'] < 50) {
            return null;
        }

        if ($threadsStatTotal['httpEffectiveResponsesReceived'] > 20) {
            $averageResponseRate =
                ( $threadsStatTotal['httpEffectiveResponsesReceived']
                + $threadsStatTotal['navigateTimeouts']
                + $threadsStatTotal['httpStatusCode5xx'])

                / $threadsStatTotal['httpRequestsSent'] * 100;
        } else {
            $averageResponseRate = $threadsStatTotal['httpEffectiveResponsesReceived'] * 100 / $threadsStatTotal['httpRequestsSent'];
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
            /*$networkStats = $this->vpnConnection->calculateNetworkStats();
            $this->stat->network->received    = $networkStats->total->received;
            $this->stat->network->transmitted = $networkStats->total->transmitted;
            $this->stat->network->sumTraffic  = $networkStats->total->sumTraffic;
            static::$closedPuppeteerApplicationsStats = static::mergeProcessStatStructures(static::$closedPuppeteerApplicationsStats, $this->stat);*/

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

