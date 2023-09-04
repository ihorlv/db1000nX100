#!/usr/bin/env php
<?php

require_once __DIR__ . '/init.php';
global $PARALLEL_VPN_CONNECTIONS_QUANTITY,
       $MAX_FAILED_VPN_CONNECTIONS_QUANTITY,
       $CURRENT_SESSION_DURATION_LIMIT,
       $STATISTICS_BLOCK_INTERVAL,
       $DELAY_AFTER_SESSION_DURATION,
       $CONNECT_PORTION_SIZE,
       $LONG_LINE,
       $VPN_CONNECTIONS,
       $VPN_CONNECTIONS_ESTABLISHED_COUNT,
       $LOG_BADGE_WIDTH,
       $FIXED_VPN_QUANTITY,
       $LONG_LINE_CLOSE,
       $LONG_LINE_OPEN,
       $VPN_SESSION_STARTED_AT,
       $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT,
       $MAIN_OUTPUT_LOOP_LAST_ITERATION;

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

                    $openVpnConfig = OpenVpnProvider::pickOpenVpnConfig();
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
                        MainLog::log($LONG_LINE,               2, 1, MainLog::LOG_PROXY_ERROR);
                        MainLog::log($vpnConnection->getLog(), 1, 0, MainLog::LOG_PROXY_ERROR);
                    }

                    unset($VPN_CONNECTIONS[$connectionIndex]);
                    $failedVpnConnectionsCount++;
                    break;

                case ($vpnState === true):
                    $hackApplication = $vpnConnection->getApplicationObject();

                    if (!is_object($hackApplication)) {
                        if ($briefConnectionLog) { echo "VPN $connectionIndex just connected"; }
                        if (!$briefConnectionLog) {
                            $vpnConnectionLogParts = mbSeparateNLines($vpnConnection->getLog());

                            MainLog::log($LONG_LINE,                        1, 1, MainLog::LOG_PROXY + MainLog::LOG_DEBUG);
                            MainLog::log($vpnConnectionLogParts->nLines,    1, 1, MainLog::LOG_PROXY);
                            MainLog::log($vpnConnectionLogParts->restLines, 2, 0, MainLog::LOG_PROXY + MainLog::LOG_DEBUG);
                        }

                        $vpnConnection->calculateAndSetBandwidthLimit($PARALLEL_VPN_CONNECTIONS_QUANTITY);
                        // Launch Hack Application
                        $hackApplication = HackApplication::getNewInstance($vpnConnection);
                        $vpnConnection->setApplicationObject($hackApplication);
                    }

                    $appState = $hackApplication->processLaunch();
                    if ($appState === -1) {
                        if ($briefConnectionLog) { echo ", app launch failed\n"; }
                        if (!$briefConnectionLog) {
                            MainLog::log($hackApplication->pumpLog(), 1, 0, MainLog::LOG_HACK_APPLICATION_ERROR);
                        }

                        $vpnConnection->terminateAndKill();
                        unset($VPN_CONNECTIONS[$connectionIndex]);
                    } else if ($appState === true) {
                        if ($briefConnectionLog) { echo ", app launched successfully\n"; }
                        if (! $briefConnectionLog) {
                            $hackApplicationLogParts = mbSeparateNLines($hackApplication->pumpLog());
                            MainLog::log($hackApplicationLogParts->nLines,    1, 0, MainLog::LOG_HACK_APPLICATION);
                            MainLog::log($hackApplicationLogParts->restLines, 1, 0, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
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

    Actions::doAction('BeforeMainOutputLoop');

    while (true) {

        Actions::doAction('BeforeMainOutputLoopIteration');
        $activeVpnConnections = 0;
        $sessionTimeFinished = false;

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            // ------------------- Echo the Hack applications output -------------------
            TimeTracking::startTaskTimeTracking('HackApplicationOutputBlock');

            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                    $vpnConnection->isTerminated()
                ||  $hackApplication->isTerminated()
            ) {
                continue;
            }

            $activeVpnConnections++;
            $hackApplicationLog = $hackApplication->pumpLog();                             /* always first */

            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
            Efficiency::addValue($connectionIndex, $connectionEfficiencyLevel);

            // ------------------- Check the alive state and VPN connection effectiveness -------------------

            $errorMessage = '';
            // ------------------- Check require terminate -------------------
            if ($hackApplication->isTerminateRequired()) {
                $errorMessage .= "\n\n" . Term::red
                    . $hackApplication->getTerminateMessage()
                    . Term::clear;
                $hackApplication->terminate(false);
                $vpnConnection->terminate(false);

            // ------------------- Check HackApplication alive state -------------------
            } else if (!$hackApplication->isAlive()) {
                $exitCode = $hackApplication->getExitCode();
                if ($exitCode) {
                    $errorMessage .= "\n\n" . $hackApplication->pumpLog(true);
                    $errorMessage .= "\n\n" . Term::red
                        . get_class($hackApplication) . ' died with exit code ' . $exitCode
                        . Term::clear;
                    $hackApplication->terminate(true);
                } else {
                    $errorMessage .= "\n\n" . get_class($hackApplication) . ' has exited';
                    $hackApplication->terminate(false);
                }
                $vpnConnection->terminate(false);

            // ------------------- Check VPN connection alive state -------------------
            } else if (
                   !$vpnConnection->isAlive()
                || !$vpnConnection->isConnected()
            ) {
                $errorMessage .= "\n\n" . Term::red
                    . 'Lost VPN connection'
                    . Term::clear;
                $hackApplication->terminate(false);
                $vpnConnection->terminate(true);
            }

            // -------------------------------------------------------------------------

            $statisticsBadge = $hackApplication->getStatisticsBadge(boolval($errorMessage));
            $infoBadge = getInfoBadge($vpnConnection);

            // ---

            if ($hackApplicationLog) {
                $hackApplicationLog .= "\n\n";
                _echo($connectionIndex, $infoBadge, $hackApplicationLog, MainLog::LOG_HACK_APPLICATION + MainLog::LOG_DEBUG);
            }

            if ($statisticsBadge) {
                _echo($connectionIndex, $infoBadge, $statisticsBadge, MainLog::LOG_HACK_APPLICATION, true);
            }

            if ($errorMessage) {
                _echo($connectionIndex, $infoBadge, $errorMessage, MainLog::LOG_GENERAL_OTHER, !$statisticsBadge);
            }

            TimeTracking::stopTaskTimeTracking('HackApplicationOutputBlock');

            if (isTimeForLongBrake()) {
                Actions::doAction('MainOutputLongBrake');
                sayAndWait(10);
            } else {
                if ($errorMessage  &&  count($VPN_CONNECTIONS) < 100) {
                    sayAndWait(1);
                } else {
                    sayAndWait(0.5);
                }
            }

            // ---

            if (time() - $VPN_SESSION_STARTED_AT > $CURRENT_SESSION_DURATION_LIMIT) {
                $sessionTimeFinished = true;
                break;
            }
        }

        Actions::doAction('AfterMainOutputLoopIteration');

        if (
            $activeVpnConnections === 0
            ||  $sessionTimeFinished
        ) {
            goto finish;
        }
    }

    finish:
    if ($VPN_CONNECTIONS_ESTABLISHED_COUNT) {
        terminateSession(false);
    }
}

function getInfoBadge($vpnConnection) : string
{
    $hackApplication = $vpnConnection->getApplicationObject();
    $networkStats    = $vpnConnection->calculateNetworkStats();

    $ret = "\n\n";

    switch (get_class($hackApplication)) {

        case 'db1000nApplication':
            $ret .= "db1000n";
        break;

        case 'DistressApplication':
            $ret .= "Distress";
        break;

        case 'PuppeteerApplication':
            $ret .= "X111 (PuppeteerDDoS)";
        break;
    }

    // ---

    $countryOrIp = $vpnConnection->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
    if ($countryOrIp) {
        $ret .= "\n" . $countryOrIp;
    }

    // ---

    $vpnTitle = $vpnConnection->getTitle(false);
    if ($vpnTitle) {
        $ret .= "\n" . $vpnTitle;
    }

    // ---

    if ($networkStats->session->sumTraffic) {
        $ret .= "\n";
        if ($networkStats->session->sumSpeed) {
            $ret .= "\n" . infoBadgeKeyValue('Speed', humanBytes($networkStats->session->sumSpeed, HUMAN_BYTES_BITS) . '/s');
        }
        $ret .= "\n" . infoBadgeKeyValue('Traffic', humanBytes($networkStats->session->sumTraffic));
    }

    // ---

    $ret .= "\n" . infoBadgeKeyValue('Duration', HHMMSSduration($networkStats->total->duration));

    // ---

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
    global $VPN_SESSION_STARTED_AT, $VPN_SESSION_FINISHED_AT, $PAST_VPN_SESSION_DURATION, $VPN_DISCONNECT_TIMEOUT,
           $LONG_LINE, $WAIT_SECONDS_BEFORE_PROCESS_KILL, $DELAY_AFTER_SESSION_DURATION;

    //--------------------------------------------------------------------------

    MainLog::log($LONG_LINE, 3, 0, MainLog::LOG_GENERAL_OTHER);

    $final = Actions::doFilter('IsFinalSession', $final);
    if ($final) {
        Actions::doAction('BeforeTerminateFinalSession');
    } else {
        Actions::doAction('BeforeTerminateSession');
    }

    MainLog::log('', 1, 0, MainLog::LOG_GENERAL_OTHER);

    $VPN_SESSION_FINISHED_AT = time();
    $PAST_VPN_SESSION_DURATION = $VPN_SESSION_FINISHED_AT - $VPN_SESSION_STARTED_AT;

    sleep($WAIT_SECONDS_BEFORE_PROCESS_KILL * 2);

    //--------------------------------------------------------------------------

    if ($final) {
        Actions::doAction('TerminateFinalSession');
    } else {
        Actions::doAction('TerminateSession');
    }

    MainLog::log('', 1, 0, MainLog::LOG_GENERAL_OTHER);
    sleep($VPN_DISCONNECT_TIMEOUT);

    //--------------------------------------------------------------------------

    if ($final) {
        Actions::doAction('AfterTerminateFinalSession');
    } else {
        Actions::doAction('AfterTerminateSession');
    }

    //--------------------------------------------------------------------------

    $delayAfterSessionStartedAt = time();

    Actions::doAction('DelayAfterSession');

    if (!$final) {
        $delayAfterSessionCallbacksDuration = time() - $delayAfterSessionStartedAt;
        $delayAfterSessionSecondsLeft       = $DELAY_AFTER_SESSION_DURATION - $delayAfterSessionCallbacksDuration;
        if ($delayAfterSessionSecondsLeft > 0) {
            sleep($delayAfterSessionSecondsLeft);
        }
    }

    //--------------------------------------------------------------------------

    MainLog::log("SESSION FINISHED", 3, 3, MainLog::LOG_GENERAL_OTHER);
    MainLog::log($LONG_LINE, 3);

    if ($final) {
        MainLog::log("The script exited normally");
        posix_kill(posix_getppid(), SIGTERM);
        exit(0);
    }
}