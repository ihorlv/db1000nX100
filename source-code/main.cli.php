#!/usr/bin/env php
<?php

require_once __DIR__ . '/init.php';
global $PARALLEL_VPN_CONNECTIONS_QUANTITY,
       $MAX_FAILED_VPN_CONNECTIONS_QUANTITY,
       $ONE_SESSION_DURATION,
       $STATISTICS_BLOCK_INTERVAL,
       $DELAY_AFTER_SESSION_DURATION,
       $CONNECT_PORTION_SIZE,
       $LONG_LINE,
       $VPN_CONNECTIONS,
       $VPN_CONNECTIONS_ESTABLISHED_COUNT,
       $LOG_BADGE_WIDTH,
       $FIXED_VPN_QUANTITY,
       $LONG_LINE_CLOSE,
       $LONG_LINE_OPEN;

while (true) {

    initSession();

    // ------------------- Checking for openvpv and db1000n processes, which may stall in memory since last session -------------------

    MainLog::log();
    $checkProcessesCommands = [
        'ps -aux | grep db1000n',
        'ps -aux | grep openvpn',
    ];
    foreach ($checkProcessesCommands as $checkProcessCommand) {
        $r = _shell_exec($checkProcessCommand);
        $lines = mbSplitLines((string) $r);
        foreach ($lines as $line) {
            if (strpos($line, 'grep') === false) {
                MainLog::log($line, 1, 0, MainLog::LOG_GENERAL_ERROR);
            }
        }
    }
    _shell_exec('killall openvpn');
    _shell_exec('killall db1000n');
    ResourcesConsumption::killTrackCliPhp();

    // ------------------- Start VPN connections and Hack applications -------------------

    $VPN_CONNECTIONS = [];
    $VPN_CONNECTIONS_ESTABLISHED_COUNT = 0;

    $connectingStartedAt = $isTimeForBrake_lastBreak = time();
    $failedVpnConnectionsCount = 0;
    $briefConnectionLog = false;
    $workingConnections = [];
    while (true) {   // Connection starting loop
        // ------------------- Start a portion of VPN connections simultaneously -------------------
        if ($briefConnectionLog) { echo "-------------------------------------\n"; }


        if (
                count($workingConnections)
            &&  count($VPN_CONNECTIONS) === count($workingConnections)
            &&  count($VPN_CONNECTIONS) >= $PARALLEL_VPN_CONNECTIONS_QUANTITY
        ) {
            if ($briefConnectionLog) {
                echo "Break: connections quantity reached\n";
            }
            break;

        //----------------------------------
        } else if (
                $failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_QUANTITY
            &&  count($VPN_CONNECTIONS)    === count($workingConnections)
            &&  count($VPN_CONNECTIONS)     < $PARALLEL_VPN_CONNECTIONS_QUANTITY
        ) {
            if ($briefConnectionLog) { echo "Too many fails\n"; }
            if (count($VPN_CONNECTIONS)) {
                MainLog::log("Reached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections, because $failedVpnConnectionsCount connection attempts failed\n", 1, 1, MainLog::LOG_GENERAL_ERROR);
                break;
            } else {
                MainLog::log("No VPN connections were established. $failedVpnConnectionsCount attempts failed\n", 1, 1, MainLog::LOG_GENERAL_ERROR);
                goto finish;
            }
        //----------------------------------
        } else if (
                !OpenVpnProvider::hasFreeOpenVpnConfig()
            &&  count($VPN_CONNECTIONS) === count($workingConnections)
        ) {
            if ($briefConnectionLog) { echo "No configs\n"; }
            if (count($VPN_CONNECTIONS)) {
                MainLog::log("Reached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections. No more ovpn files available\n", 1, 1, MainLog::LOG_GENERAL_ERROR);
                break;
            } else {
                MainLog::log("No VPN connections were established. No ovpn files available\n", 1, 1, MainLog::LOG_GENERAL_ERROR);
                goto finish;
            }

        //----------------------------------
        } else if (
            count($VPN_CONNECTIONS) < $PARALLEL_VPN_CONNECTIONS_QUANTITY
        ) {
            $connectNextPortion = true;

            if (   count($VPN_CONNECTIONS)
                && count($workingConnections) + $failedVpnConnectionsCount < intRound(count($VPN_CONNECTIONS) * 0.9)
            ) {
                // Don't connect next portion, while 90% of previously started connections aren't established
                $connectNextPortion = false;
            }

            if ($connectNextPortion) {
                if ($briefConnectionLog) { echo "connecting portion of $CONNECT_PORTION_SIZE connections\n"; }

                for ($i = 1; $i <= $CONNECT_PORTION_SIZE; $i++) {

                    if (count($VPN_CONNECTIONS) >= $PARALLEL_VPN_CONNECTIONS_QUANTITY) {
                        if ($briefConnectionLog) { echo "portion break, because of max count\n"; }
                        break;
                    }

                    if ($failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_QUANTITY) {
                        if ($briefConnectionLog) { echo "portion break, because of fail count\n"; }
                        break;
                    }

                    $openVpnConfig = OpenVpnProvider::holdRandomOpenVpnConfig();
                    if ($openVpnConfig === -1) {
                        if ($briefConnectionLog) { echo "portion break, no configs\n"; }
                        break;
                    }

                    //---

                    if (count($VPN_CONNECTIONS) === 0) {
                        $connectionIndex = 0;
                    } else {
                        $connectionIndex = array_key_last($VPN_CONNECTIONS) + 1;
                    }

                    if ($briefConnectionLog) { echo "VPN $connectionIndex starting\n"; }
                    $openVpnConnection = new OpenVpnConnection($connectionIndex, $openVpnConfig);
                    $VPN_CONNECTIONS[$connectionIndex] = $openVpnConnection;
                }
            }
        }

        // ------------------- Checking connection states -------------------

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            if ($workingConnections[$connectionIndex]  ??  false) {
                if ($briefConnectionLog) { echo "VPN/App $connectionIndex is already working\n"; }
                continue;
            }

            $vpnState = $vpnConnection->processConnection();
            switch (true) {

                case ($vpnState === -1):
                    if ($briefConnectionLog) { echo "VPN $connectionIndex failed to connect\n"; }
                    if (! $briefConnectionLog) {
                        MainLog::log($LONG_LINE,               2, 0, MainLog::LOG_PROXY_ERROR);
                        MainLog::log($vpnConnection->getLog(), 3, 0, MainLog::LOG_PROXY_ERROR);
                    }

                    unset($VPN_CONNECTIONS[$connectionIndex]);
                    $failedVpnConnectionsCount++;
                    break;

                case ($vpnState === true):
                    $hackApplication = $vpnConnection->getApplicationObject();

                    if (!is_object($hackApplication)) {
                        if ($briefConnectionLog) { echo "VPN $connectionIndex just connected"; }
                        if (!$briefConnectionLog) {
                            MainLog::log($LONG_LINE,               2, 0, MainLog::LOG_PROXY);
                            MainLog::log($vpnConnection->getLog(), 3, 0, MainLog::LOG_PROXY);
                        }

                        $vpnConnection->calculateAndSetBandwidthLimit($PARALLEL_VPN_CONNECTIONS_QUANTITY);
                        // Launch Hack Application
                        $hackApplication = randomHackApplication($vpnConnection->getNetnsName());
                        $vpnConnection->setApplicationObject($hackApplication);
                    }

                    $appState = $hackApplication->processLaunch();
                    if ($appState === -1) {
                        if ($briefConnectionLog) { echo ", app launch failed\n"; }
                        if (!$briefConnectionLog) {
                            MainLog::log($hackApplication->pumpLog(), 3, 0, MainLog::LOG_HACK_APPLICATION_ERROR);
                        }

                        $vpnConnection->terminate();
                        unset($VPN_CONNECTIONS[$connectionIndex]);
                    } else if ($appState === true) {
                        if ($briefConnectionLog) { echo ", app launched successfully\n"; }
                        if (! $briefConnectionLog) {
                            MainLog::log($hackApplication->pumpLog(), 3, 0,  MainLog::LOG_HACK_APPLICATION);
                        }

                        $workingConnections[$connectionIndex] = $vpnConnection;
                        $hackApplication->setReadChildProcessOutput(true);
                    } else {
                        if ($briefConnectionLog) { echo ", app launch in process\n"; }
                    }
            }

            if (isTimeForLongBrake()) {
                sayAndWait(10);
            } else {
                sayAndWait(0.1);
            }
        }
    }
    $VPN_CONNECTIONS_ESTABLISHED_COUNT = count($VPN_CONNECTIONS);

    //-----------------------------------------------------------------------------------

    $connectingDuration = time() - $connectingStartedAt;
    MainLog::log("$VPN_CONNECTIONS_ESTABLISHED_COUNT connections established during " . humanDuration($connectingDuration), 3, 3, MainLog::LOG_GENERAL);

    // ------------------- Watch VPN connections and Hack applications -------------------
    ResourcesConsumption::resetAndStartTracking();
    $vpnSessionStartedAt = time();
    $lastStatisticsBadgeBlockAt = time();
    $previousLoopOnStartVpnConnectionsCount = $PARALLEL_VPN_CONNECTIONS_QUANTITY;
    while (true) {

        // Reapply bandwidth limit to VPN connections
        if (count($VPN_CONNECTIONS) !== $previousLoopOnStartVpnConnectionsCount) {
            foreach ($VPN_CONNECTIONS as $vpnConnection) {
                if ($vpnConnection->isConnected()) {
                    $vpnConnection->calculateAndSetBandwidthLimit(count($VPN_CONNECTIONS));
                }
            }
            $previousLoopOnStartVpnConnectionsCount = count($VPN_CONNECTIONS);
        }

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            // ------------------- Echo the Hack applications output -------------------
            ResourcesConsumption::startTaskTimeTracking('HackApplicationOutputBlock');
            $hackApplication = $vpnConnection->getApplicationObject();
            $output = $hackApplication->pumpLog();                                  /* step 1 */
            if ($output) {
                $output .= "\n\n";
            }
            $output .= $hackApplication->getStatisticsBadge();                      /* step 2 */
            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();    /* step 3 */
            $networkTrafficStat = $vpnConnection->calculateNetworkTrafficStat();

            $label = '';

            if ($output) {
                $label = getInfoBadge($vpnConnection, $networkTrafficStat);
                if (count(mbSplitLines($output)) <= count(mbSplitLines($label))) {
                    $label = '';
                }
                _echo($connectionIndex, $label, $output);
            }

            // ------------------- Check the alive state and VPN connection effectiveness -------------------
            Efficiency::addValue($connectionIndex, $connectionEfficiencyLevel);
            $vpnConnectionActive    = $vpnConnection->isAlive() & $networkTrafficStat->connected;
            $hackApplicationIsAlive = $hackApplication->isAlive();
            ResourcesConsumption::stopTaskTimeTracking('HackApplicationOutputBlock');

            if (
                !$vpnConnectionActive
                || !$hackApplicationIsAlive
                || $connectionEfficiencyLevel === 0
            ) {
                $message = '';

                // ------------------- Check  alive state -------------------
                if (! $vpnConnectionActive) {
                    $message = "\n" . Term::red
                        . 'Lost VPN connection'
                        . Term::clear;
                }

                // ------------------- Check  alive state -------------------
                if (! $hackApplicationIsAlive) {
                    $exitCode = $hackApplication->getExitCode();
                    $message = "\n" . Term::red
                        . "Application " . ($exitCode === 0 ? 'was terminated' : 'died with exit code ' . $exitCode)
                        . Term::clear;
                }

                // ------------------- Check effectiveness -------------------
                if ($connectionEfficiencyLevel === 0) {
                    $message = "\n" . Term::red
                        . "Zero efficiency. Terminating"
                        . Term::clear;
                }

                _echo($connectionIndex, $label, $message);
                $hackApplication->terminate();
                sayAndWait(1);
                $vpnConnection->terminate();
                unset($VPN_CONNECTIONS[$connectionIndex]);
            }

            // ------------------- Check session duration -------------------
            $vpnSessionTimeElapsed = time() - $vpnSessionStartedAt;
            if ($vpnSessionTimeElapsed > $ONE_SESSION_DURATION) {
                goto finish;
            }

            if (count($VPN_CONNECTIONS) < 5  ||  isTimeForLongBrake()) {
                sayAndWait(10);
            } else {
                       if ($output  &&  count($VPN_CONNECTIONS) < 100) {
                    sayAndWait(2);
                } else if ($output  &&  count($VPN_CONNECTIONS) < 200) {
                    sayAndWait(1);
                } else {
                    sayAndWait(0.5);
                }
            }
        }

        // ------------------- Statistics Badge block-------------------

        if ($lastStatisticsBadgeBlockAt + $STATISTICS_BLOCK_INTERVAL < time()) {

            $statisticsBadge = OpenVpnStatistics::generateBadge();
            if ($statisticsBadge) {
                MainLog::log($LONG_LINE_CLOSE, 0, 0, MainLog::LOG_GENERAL_STATISTICS);
                MainLog::log($statisticsBadge, 2, 2, MainLog::LOG_GENERAL_STATISTICS);
                sayAndWait(60);
                resetTimeForLongBrake();
            }

            $lastStatisticsBadgeBlockAt = time();
        }
        //----------------------------------------------------

        if (count($VPN_CONNECTIONS) === 0) {
            goto finish;
        }
    }

    finish:
    terminateSession();
    sayAndWait($DELAY_AFTER_SESSION_DURATION);
}

function getInfoBadge($vpnConnection, $networkTrafficStat) : string
{
    $hackApplication = $vpnConnection->getApplicationObject();
    $ret = "\n";

    $countryOrIp = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
    if ($countryOrIp) {
        $ret .= "\n" . $countryOrIp;
    }

    $vpnTitle = $vpnConnection->getTitle(false);
    if ($vpnTitle) {
        $ret .= "\n" . $vpnTitle;
    }

    if ($networkTrafficStat->sumTraffic) {
        $ret .= "\n";
        if ($networkTrafficStat->sumSpeed) {
            $ret .= "\n" . infoBadgeKeyValue('Speed', humanBytes($networkTrafficStat->sumSpeed, HUMAN_BYTES_BITS) . '/s');
        }
        $ret .= "\n" . infoBadgeKeyValue('Traffic', humanBytes($networkTrafficStat->sumTraffic));
    }

    $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
    if ($connectionEfficiencyLevel !== null) {
        $ret .= "\n" . infoBadgeKeyValue('Response rate', $connectionEfficiencyLevel . '%');
    }

    return $ret;
}

function infoBadgeKeyValue($key, $value)
{
    global $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT;

    $key =   (string) $key;
    $value = (string) $value;

    $paddingLength = $LOG_BADGE_WIDTH - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT
        - mb_strlen($key)         - mb_strlen($value);
    $paddingLength = max(0, $paddingLength);

    return $key . str_repeat(' ', $paddingLength) . $value;
}

function terminateSession()
{
    global $LONG_LINE, $IS_IN_DOCKER,
           $VPN_CONNECTIONS, $SESSIONS_COUNT;

    MainLog::log($LONG_LINE, 3, 0, MainLog::LOG_GENERAL);
    ResourcesConsumption::finishTracking();
    ResourcesConsumption::stopTaskTimeTracking('session');
    $statisticsBadge = OpenVpnStatistics::generateBadge();
    Efficiency::clear();
    MainLog::log(ResourcesConsumption::getTasksTimeTrackingResultsBadge($SESSIONS_COUNT), 1, 0, MainLog::LOG_GENERAL_STATISTICS);

    //--------------------------------------------------------------------------
    // Close everything

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (gettype($hackApplication) === 'object') {
                $hackApplication->setReadChildProcessOutput(false);
                $hackApplication->clearLog();
                $hackApplication->terminate();
                MainLog::log($hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
            }
        }
    }

    sayAndWait(2);

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $vpnConnection->clearLog();
            $vpnConnection->terminate();
            MainLog::log($vpnConnection->getLog(), 1, 0, MainLog::LOG_PROXY);
            unset($VPN_CONNECTIONS[$connectionIndex]);
        }
    }

    //--------------------------------------------------------------------------

    MainLog::log("SESSION FINISHED", 3, 3, MainLog::LOG_GENERAL);
    MainLog::log($statisticsBadge, 1, 0, MainLog::LOG_GENERAL_STATISTICS);
    MainLog::log($LONG_LINE, 3, 0, MainLog::LOG_GENERAL);

    MainLog::trimLog();
    if (! $IS_IN_DOCKER) {
        trimDisks();
    }
    MainLog::log('', 2, 0, MainLog::LOG_GENERAL);
}

function randomHackApplication($netnsName)
{
    return new db1000nApplication($netnsName);
    //return new PuppeteerApplication($netnsName);
}
