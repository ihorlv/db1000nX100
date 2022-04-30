#!/usr/bin/env php
<?php

require_once __DIR__ . '/init.php';
global $PARALLEL_VPN_CONNECTIONS_QUANTITY,
       $MAX_FAILED_VPN_CONNECTIONS_QUANTITY,
       $PING_INTERVAL,
       $ONE_VPN_SESSION_DURATION,
       $CONNECT_PORTION_SIZE,
       $TERM,
       $LONG_LINE,
       $TOTAL_EFFICIENCY_LEVEL,
       $VPN_CONNECTIONS,
       $VPN_CONNECTIONS_ESTABLISHED_COUNT,
       $LOG_BADGE_WIDTH,
       $FIXED_VPN_QUANTITY;


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
                MainLog::log($line, MainLog::LOG_GENERAL_ERROR);
            }
        }
    }
    _shell_exec('killall openvpn');
    _shell_exec('killall db1000n');

    // ------------------- Start VPN connections and Hack applications -------------------

    $VPN_CONNECTIONS = [];
    $VPN_CONNECTIONS_ESTABLISHED_COUNT = 0;
    $connectingStartedAt = time();
    $failedVpnConnectionsCount = 0;
    $briefConnectionLog = false;
    $workingConnectionsCount = 0;
    while (true) {   // Connection starting loop
        // ------------------- Start a portion of VPN connections simultaneously -------------------
        if ($briefConnectionLog) { echo "-------------------------------------\n"; }


        //----------------------------------
        if (
                $failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_QUANTITY
            &&  count($VPN_CONNECTIONS) < $PARALLEL_VPN_CONNECTIONS_QUANTITY
        ) {
            if ($briefConnectionLog) { echo "Too many fails\n"; }
            if (count($VPN_CONNECTIONS) === $workingConnectionsCount) {
                if (count($VPN_CONNECTIONS)) {
                    MainLog::log("Reached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections, because $failedVpnConnectionsCount connection attempts failed\n", MainLog::LOG_GENERAL_ERROR, 1, 1);
                    break;
                } else {
                    MainLog::log("No VPN connections were established. $failedVpnConnectionsCount attempts failed\n", MainLog::LOG_GENERAL_ERROR, 1, 1);
                    goto finish;
                }
            }

        //----------------------------------
        } else if (
                !OpenVpnProvider::hasFreeOpenVpnConfig()
            &&  count($VPN_CONNECTIONS) === $workingConnectionsCount
        ) {
            if (count($VPN_CONNECTIONS)) {
                MainLog::log("Reached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections. No more ovpn files available\n", MainLog::LOG_GENERAL_ERROR, 1, 1);
                break;
            } else {
                MainLog::log("No VPN connections were established. No ovpn files available\n", MainLog::LOG_GENERAL_ERROR, 1, 1);
                goto finish;
            }

        //----------------------------------
        } else if (
                $workingConnectionsCount
            &&  count($VPN_CONNECTIONS) === $workingConnectionsCount
            &&  count($VPN_CONNECTIONS) >= $PARALLEL_VPN_CONNECTIONS_QUANTITY
        ) {
            if ($briefConnectionLog) { echo "Break: connections quantity reached\n"; }
            break;

        //----------------------------------
        } else if (
                   count($VPN_CONNECTIONS) < $PARALLEL_VPN_CONNECTIONS_QUANTITY
                && (!($FIXED_VPN_QUANTITY === 1  &&  count($VPN_CONNECTIONS) === 1))
        ) {

            for ($i = 1; $i <= $CONNECT_PORTION_SIZE; $i++) {

                if (count($VPN_CONNECTIONS) === 0) {
                    $connectionIndex = 0;
                } else {
                    $connectionIndex = array_key_last($VPN_CONNECTIONS) + 1;
                }

                $openVpnConfig = OpenVpnProvider::holdRandomOpenVpnConfig();
                if ($openVpnConfig === -1) {
                    break;
                }

                if ($briefConnectionLog) { echo "VPN $connectionIndex starting\n"; }
                $VPN_CONNECTIONS[$connectionIndex] = new OpenVpnConnection($connectionIndex, $openVpnConfig);
                sayAndWait(1);  // This delay is done to avoid setting same IP to two connections

                if ($FIXED_VPN_QUANTITY === 1) {
                    break;
                }

                /*if (count($VPN_CONNECTIONS) >= $PARALLEL_VPN_CONNECTIONS_QUANTITY) {
                    if ($briefConnectionLog) { echo "break because of max count\n"; }
                    break;
                }

                if (count($VPN_CONNECTIONS) + $failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_QUANTITY) {
                    if ($briefConnectionLog) { echo "break because of fail count\n"; }
                    break;
                }*/
            }

        }

        // ------------------- Checking connection states -------------------

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            if (gettype($vpnConnection->getApplicationObject()) === 'object') {
                if ($briefConnectionLog) { echo "VPN/App $connectionIndex is already working\n"; }
                continue;
            }
            $vpnState = $vpnConnection->processConnection();

            switch (true) {

                case ($vpnState === -1):
                    if ($briefConnectionLog) { echo "VPN $connectionIndex failed to connect\n"; }
                    unset($VPN_CONNECTIONS[$connectionIndex]);
                    $failedVpnConnectionsCount++;
                    if (! $briefConnectionLog) {
                        MainLog::log($LONG_LINE,               MainLog::LOG_PROXY_ERROR, 2);
                        MainLog::log($vpnConnection->getLog(), MainLog::LOG_PROXY_ERROR, 3);
                    }
                break;

                case ($vpnState === true):
                    if ($briefConnectionLog) { echo "VPN $connectionIndex just connected"; }
                    if (! $briefConnectionLog) {
                        MainLog::log($LONG_LINE,               MainLog::LOG_PROXY, 2);
                        MainLog::log($vpnConnection->getLog(), MainLog::LOG_PROXY, 3);

                    }

                    // Launch Hack Application
                    $hackApplication = new HackApplication($vpnConnection->getNetnsName());
                    do {
                        $appState = $hackApplication->processLaunch();
                        if ($appState === -1) {
                            if ($briefConnectionLog) { echo ", app launch failed\n"; }
                            $vpnConnection->terminate();
                            unset($VPN_CONNECTIONS[$connectionIndex]);
                            if (! $briefConnectionLog) {
                                MainLog::log($hackApplication->pumpLog(), MainLog::LOG_HACK_APPLICATION_ERROR, 3);
                            }
                        } else if ($appState === true) {
                            if ($briefConnectionLog) { echo ", app launched successfully\n"; }
                            $workingConnectionsCount++;
                            $vpnConnection->setApplicationObject($hackApplication);
                            if (! $briefConnectionLog) {
                                MainLog::log($hackApplication->pumpLog(), MainLog::LOG_HACK_APPLICATION, 3);
                            }
                            $hackApplication->setReadChildProcessOutput(true);
                        } else {
                            if ($briefConnectionLog) { echo ", app launch in process\n"; }
                        }
                        sayAndWait(0.25);
                    } while ($appState === false);
                break;
            }

            if (isTimeForLongBrake()) {
                sayAndWait(10);
            } else {
                sayAndWait(0.5);
            }
        }
    }
    $VPN_CONNECTIONS_ESTABLISHED_COUNT = count($VPN_CONNECTIONS);


    $connectingDuration = time() - $connectingStartedAt;
    $connectingDurationMinutes = floor($connectingDuration / 60);
    $connectingDurationSeconds = $connectingDuration - ($connectingDurationMinutes * 60);
    MainLog::log(count($VPN_CONNECTIONS) . " connections established during {$connectingDurationMinutes}min {$connectingDurationSeconds}sec", MainLog::LOG_GENERAL, 3, 3);

    // ------------------- Watch VPN connections and Hack applications -------------------
    ResourcesConsumption::resetAndStartTracking();
    $vpnSessionStartedAt = time();
    $lastPing = 0;
    while (true) {

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {

            // ------------------- Echo the Hack applications output -------------------
            $hackApplication = $vpnConnection->getApplicationObject();
            $output = mbTrim($hackApplication->pumpLog());
            if ($output) {
                $output .= "\n\n";
            }
            $output .= $hackApplication->getStatisticsBadge();
            $label = '';

            if ($output) {
                $label = getInfoBadge($vpnConnection);
                if (count(mbSplitLines($output)) <= count(mbSplitLines($label))) {
                    $label = '';
                }
                _echo($connectionIndex, $label, $output, false);
            }

            // ------------------- Check the Hack applications alive state and VPN connection effectiveness -------------------
            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
            Efficiency::addValue($connectionIndex, $connectionEfficiencyLevel);
            $hackApplicationIsAlive = $hackApplication->isAlive();
            if (!$hackApplicationIsAlive  ||  $connectionEfficiencyLevel === 0) {

                // ------------------- Check  alive state -------------------
                if (! $hackApplicationIsAlive) {
                    $exitCode = $hackApplication->getExitCode();
                    $message = "\n\n" . Term::red
                             . "Application " . ($exitCode === 0 ? 'was terminated' : 'died with exit code ' . $exitCode)
                             . Term::clear;
                    _echo($connectionIndex, $label, $message);
                    $hackApplication->terminate(true);
                }

                // ------------------- Check effectiveness -------------------
                if ($connectionEfficiencyLevel === 0) {
                    $message = "\n" . Term::red
                             . "Zero efficiency. Terminating"
                             . Term::clear;
                    _echo($connectionIndex, $label, $message);
                    $hackApplication->terminate();
                }

                sayAndWait(1);
                $vpnConnection->terminate();
                unset($VPN_CONNECTIONS[$connectionIndex]);
            }


            // ------------------- Check session duration -------------------
            $vpnSessionTimeElapsed = time() - $vpnSessionStartedAt;
            if ($vpnSessionTimeElapsed > $ONE_VPN_SESSION_DURATION) {
                goto finish;
            }


            if (count($VPN_CONNECTIONS) < 5  ||  isTimeForLongBrake()) {
                ResourcesConsumption::trackRamUsage();
                sayAndWait(10);
            } else {
                //echo "small delay\n";
                sayAndWait(1);
            }
        }

        if ($lastPing + $PING_INTERVAL < time()) {
            // ------------------- Check VPN pings -------------------
            foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
                $hackApplication = $vpnConnection->getApplicationObject();
                $country = $hackApplication->getCurrentCountry();
                $vpnTitle = $vpnConnection->getTitle();
                $vpnTitlePadded = str_pad($vpnTitle, 50);
                _echo($connectionIndex, $country, $vpnTitlePadded, true, true);

                $ping = $vpnConnection->checkPing();
                if ($ping) {
                    MainLog::log("  [Ping OK]");
                } else {
                    MainLog::log( Term::red . '  [Ping timeout]' . Term::clear);
                }
            }

            $lastPing = time();
        }

        if (count($VPN_CONNECTIONS) === 0) {
            goto finish;
        }
    }


    finish:
    terminateSession();
    sayAndWait(30);
}

function getInfoBadge($vpnConnection) : string
{
    $hackApplication = $vpnConnection->getApplicationObject();
    $ret = '';

    $countryOrIp = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
    if ($countryOrIp) {
        $ret .= "\n" . $countryOrIp;
    }

    $vpnTitle = $vpnConnection->getTitle(false);
    if ($vpnTitle) {
        $ret .= "\n" . $vpnTitle;
    }

    $trafficStat = $vpnConnection->getNetworkTrafficStat();
    $trafficTotal = $trafficStat->transmitted + $trafficStat->received;
    if ($trafficTotal) {
        $ret .= "\n" . infoBadgeKeyValue('Traffic', humanBytes($trafficTotal));
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
    global $LOG_BADGE_WIDTH, $LONG_LINE, $VPN_CONNECTIONS, $IS_IN_DOCKER;

    MainLog::log($LONG_LINE, MainLog::LOG_GENERAL, 3, 3);
    ResourcesConsumption::finishTracking();
    Efficiency::newIteration();
    Statistic::show();

    //--------------------------------------------------------------------------
    // Close everything

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (gettype($hackApplication) === 'object') {
                $hackApplication->setReadChildProcessOutput(false);
                $hackApplication->clearLog();
                $hackApplication->terminate();
                MainLog::log($hackApplication->pumpLog(), MainLog::LOG_HACK_APPLICATION);
            }
        }
    }

    MainLog::log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Waiting 10 seconds");
    sleep(10);

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $vpnConnection->clearLog();
            $vpnConnection->terminate();
            MainLog::log($vpnConnection->getLog(), MainLog::LOG_PROXY);
            unset($VPN_CONNECTIONS[$connectionIndex]);
        }
    }

    //--------------------------------------------------------------------------

    MainLog::log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "SESSION FINISHED", MainLog::LOG_GENERAL, 3, 3);

    MainLog::trimLog();
    if (! $IS_IN_DOCKER) {
        cleanSwapDisk();
    }
    MainLog::log($LONG_LINE, MainLog::LOG_GENERAL, 3, 3);
}