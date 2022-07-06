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

    $VPN_CONNECTIONS = [];
    $VPN_CONNECTIONS_ESTABLISHED_COUNT = 0;
    $briefConnectionLog = false;
    $workingConnections = [];
    $workingConnectionsCountLeftFromPreviousSession = 0;

while (true) {

    initSession();

    // ------------------- Start VPN connections and Hack applications -------------------

    $connectingStartedAt = $isTimeForBrake_lastBreak = time();
    $failedVpnConnectionsCount = 0;
    $workingConnections = $VPN_CONNECTIONS;
    $workingConnectionsCountLeftFromPreviousSession = count($VPN_CONNECTIONS);

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

                    $connectionIndex = getArrayFreeIntKey($VPN_CONNECTIONS);
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
                        $hackApplication = HackApplication::getApplication($connectionIndex, $vpnConnection->getNetnsName());
                        $vpnConnection->setApplicationObject($hackApplication);
                    }

                    $appState = $hackApplication->processLaunch();
                    if ($appState === -1) {
                        if ($briefConnectionLog) { echo ", app launch failed\n"; }
                        if (!$briefConnectionLog) {
                            MainLog::log($hackApplication->pumpLog(), 3, 0, MainLog::LOG_HACK_APPLICATION_ERROR);
                        }

                        $vpnConnection->terminateAndKill();
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
    ksort($VPN_CONNECTIONS);

    //-----------------------------------------------------------------------------------

    $connectingDuration = time() - $connectingStartedAt;
    MainLog::log('', 3, 0, MainLog::LOG_GENERAL_OTHER);

    if ($workingConnectionsCountLeftFromPreviousSession) {
        MainLog::log($workingConnectionsCountLeftFromPreviousSession . ' connections where kept from previous session');
    }

    MainLog::log(
            ($VPN_CONNECTIONS_ESTABLISHED_COUNT - $workingConnectionsCountLeftFromPreviousSession)
          . " connections established during " . humanDuration($connectingDuration),
    3);

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
            Efficiency::addValue($connectionIndex, $connectionEfficiencyLevel);

            $networkTrafficStat = $vpnConnection->calculateNetworkTrafficStat();
            $infoBadge = getInfoBadge($vpnConnection, $networkTrafficStat);

            // ------------------- Check the alive state and VPN connection effectiveness -------------------

            $output = mbRTrim($output);
            $destroyThisConnection = false;
            // ------------------- Check VPN connection alive state -------------------
            if (
                   !$vpnConnection->isAlive()
                || !$networkTrafficStat->connected
            ) {
                $output .= "\n\n" . Term::red
                    . 'Lost VPN connection'
                    . Term::clear;
                $destroyThisConnection = true;
            // ------------------- Check HackApplication alive state -------------------
            } else if (!$hackApplication->isAlive()) {
                $exitCode = $hackApplication->getExitCode();
                if ($exitCode) {
                    $output .= "\n\n" . Term::red
                        . get_class($hackApplication) . ' died with exit code ' . $exitCode
                        . Term::clear;
                } else {
                    $output .= "\n\n" . get_class($hackApplication) . ' has exited';
                }
                $destroyThisConnection = true;
            // ------------------- Check effectiveness -------------------
            } else if (
                    $connectionEfficiencyLevel === 0
                &&  time() - $vpnSessionStartedAt > 3 * 60
            ) {
                $output .= "\n\n" . Term::red
                    . "Zero efficiency. Terminating"
                    . Term::clear;
                $destroyThisConnection = true;
            // ------------------- Check network speed -------------------
            } else if (
                    $networkTrafficStat->receiveSpeed < 20 * 1024
                &&  get_class($hackApplication) === 'PuppeteerApplication'
                &&  time() - $vpnSessionStartedAt > 3 * 60
            ) {
                $output .= "\n\n" . Term::red
                    . "Network speed low. Terminating"
                    . Term::clear;
                $destroyThisConnection = true;
            }

            // -------------------

            if ($destroyThisConnection) {
                $hackApplication->terminateAndKill();
                sayAndWait(1);
                $vpnConnection->terminateAndKill();
                unset($VPN_CONNECTIONS[$connectionIndex]);
            }

            if ($output) {
                _echo($connectionIndex, $infoBadge, $output);
            }

            ResourcesConsumption::stopTaskTimeTracking('HackApplicationOutputBlock');

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
        Actions::doAction('AfterHackApplicationOutputLoop');

        // ------------------- Statistics Badge block-------------------

        if ($lastStatisticsBadgeBlockAt + $STATISTICS_BLOCK_INTERVAL < time()) {

            $statisticsBadge = OpenVpnStatistics::generateBadge();
            if ($statisticsBadge) {
                MainLog::log($LONG_LINE_CLOSE, 0, 0, MainLog::LOG_GENERAL_OTHER);
                MainLog::log($statisticsBadge, 2, 2, MainLog::LOG_GENERAL_OTHER);
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
    terminateSession(false);
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

function terminateSession($final)
{
    global $LONG_LINE, $IS_IN_DOCKER, $WAIT_SECONDS_BEFORE_PROCESS_KILL,
           $VPN_CONNECTIONS, $SESSIONS_COUNT;

    MainLog::log($LONG_LINE, 3, 0, MainLog::LOG_GENERAL_OTHER);
    ResourcesConsumption::finishTracking();
    ResourcesConsumption::stopTaskTimeTracking('session');
    $statisticsBadge = OpenVpnStatistics::generateBadge();
    Efficiency::clear();
    MainLog::log(ResourcesConsumption::getTasksTimeTrackingResultsBadge($SESSIONS_COUNT), 1, 0, MainLog::LOG_DEBUG);

    //--------------------------------------------------------------------------
    // Close everything

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {

        for ($doKill = 0; $doKill <= 1; $doKill++) {   // First terminate, then kill

            foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
                MainLog::log("VPN$connectionIndex:", 1, 0, MainLog::LOG_GENERAL_OTHER);

                $hackApplication = $vpnConnection->getApplicationObject();
                if (is_object($hackApplication)) {
                    if (
                        !$final
                        &&  get_class($hackApplication) === 'PuppeteerApplication'
                    ) {
                        continue;
                        MainLog::log('puppeteer-ddos.cli.js will continue work during next session',1, 0, MainLog::LOG_HACK_APPLICATION);
                    }

                    $hackApplication->setReadChildProcessOutput(false);
                    $hackApplication->clearLog();
                    if (!$doKill) {
                        $hackApplication->terminate(false);
                    } else {
                        $hackApplication->kill();
                    }
                    MainLog::log($hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION);
                }

                // ---
                $vpnConnection->clearLog();
                if (!$doKill) {
                    $vpnConnection->terminate(false);
                } else {
                    $vpnConnection->kill();
                    unset($VPN_CONNECTIONS[$connectionIndex]);
                }
                MainLog::log($vpnConnection->getLog(), 1, 0, MainLog::LOG_PROXY);

            }

            if (!$doKill) {
                sayAndWait($WAIT_SECONDS_BEFORE_PROCESS_KILL);
                MainLog::log('', 1, 0, MainLog::LOG_GENERAL_OTHER);
            }
        }
    }

    //--------------------------------------------------------------------------

    MainLog::log("SESSION FINISHED", 3, 3);
    MainLog::log($statisticsBadge);
    MainLog::log($LONG_LINE, 3);

    MainLog::trimLog();
    if (! $IS_IN_DOCKER) {
        trimDisks();
    }
    MainLog::log('', 2, 0, MainLog::LOG_GENERAL_OTHER);
}