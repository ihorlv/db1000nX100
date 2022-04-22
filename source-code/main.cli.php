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


passthru('ulimit -n 102400');
calculateResources();
OpenVpnProvider::initStatic();

while (true) {

    initSession();

    // ------------------- Checking for openvpv and db1000n processes, which may stall in memory since last session -------------------

    echo "\n";
    $checkProcessesCommands = [
        'ps -aux | grep db1000n',
        'ps -aux | grep openvpn',
    ];
    foreach ($checkProcessesCommands as $checkProcessCommand) {
        $r = shell_exec($checkProcessCommand . ' 2>&1');
        $lines = mbSplitLines((string) $r);
        foreach ($lines as $line) {
            if (strpos($line, 'grep') === false) {
                echo "$line\n";
            }
        }
    }
    shell_exec('killall openvpn   2>&1');
    shell_exec('killall db1000n   2>&1');

    // ------------------- Start VPN connections and Hack applications -------------------

    $connectingStartedAt = time();
    $VPN_CONNECTIONS = [];
    $failedVpnConnectionsCount = 0;
    $noOpenVpnConfigsLeft = false;
    $tunDeviceIndex = OpenVpnConnection::getNextTunDeviceIndex(-1);

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
                    echo Term::red;
                    echo "\nReached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections, because $failedVpnConnectionsCount connection attempts failed\n\n";
                    echo Term::clear;
                    break;
                } else {
                    echo Term::red;
                    echo "\nNo VPN connections were established. $failedVpnConnectionsCount attempts failed\n\n";
                    echo Term::clear;
                    goto finish;
                }
            }

        //----------------------------------
        } else if (
                $noOpenVpnConfigsLeft
            &&  count($VPN_CONNECTIONS) === $workingConnectionsCount
        ) {
            if (count($VPN_CONNECTIONS)) {
                echo Term::red;
                echo "\nReached " . count($VPN_CONNECTIONS) . " of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections. No more ovpn files available\n\n";
                echo Term::clear;
                break;
            } else {
                echo Term::red;
                echo "\nNo VPN connections were established. No ovpn files available\n\n";
                echo Term::clear;
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
                    $noOpenVpnConfigsLeft = true;
                    break;
                }

                if ($briefConnectionLog) { echo "VPN $connectionIndex starting\n"; }
                $VPN_CONNECTIONS[$connectionIndex] = new OpenVpnConnection($connectionIndex, $tunDeviceIndex, $openVpnConfig);
                $tunDeviceIndex = OpenVpnConnection::getNextTunDeviceIndex($tunDeviceIndex);
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
                        echo "$LONG_LINE\n\n";
                        echo Term::red;
                        echo Term::removeMarkup($vpnConnection->getLog());
                        echo "\n\n";
                        echo Term::clear;
                    }
                break;

                case ($vpnState === true):
                    if ($briefConnectionLog) { echo "VPN $connectionIndex just connected"; }
                    if (! $briefConnectionLog) {
                        echo "$LONG_LINE\n\n";
                        echo $vpnConnection->getLog();
                        echo "\n\n";
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
                                echo Term::red;
                                echo Term::removeMarkup($hackApplication->pumpOutLog());
                                echo "\n\n";
                                echo Term::clear;
                            }
                        } else if ($appState === true) {
                            if ($briefConnectionLog) { echo ", app launched successfully\n"; }
                            $workingConnectionsCount++;
                            $vpnConnection->setApplicationObject($hackApplication);
                            if (! $briefConnectionLog) {
                                echo $hackApplication->pumpOutLog();
                                echo "\n\n";
                            }
                            $hackApplication->setReadChildProcessOutput(true);
                        } else {
                            if ($briefConnectionLog) { echo ", app launch in process\n"; }
                        }
                        sayAndWait(0.25);
                    } while ($appState === false);
                break;
            }

            if (isTimeForBrake()) {
                sayAndWait(10);
            } else {
                sayAndWait(0.25);
            }
        }
    }

    $VPN_CONNECTIONS_ESTABLISHED_COUNT = count($VPN_CONNECTIONS);


    $connectingDuration = time() - $connectingStartedAt;
    $connectingDurationMinutes = floor($connectingDuration / 60);
    $connectingDurationSeconds = $connectingDuration - ($connectingDurationMinutes * 60);
    echo "\n" . count($VPN_CONNECTIONS) . " connections established during {$connectingDurationMinutes}min {$connectingDurationSeconds}sec\n\n";

    // ------------------- Watch VPN connections and Hack applications -------------------
    ResourcesConsumption::resetAndStartTracking();
    $vpnSessionStartedAt = time();
    $lastPing = 0;
    while (true) {

        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {

            // ------------------- Echo the Hack applications output -------------------
            $hackApplication = $vpnConnection->getApplicationObject();
            $openVpnConfig = $vpnConnection->getOpenVpnConfig();
            $hackApplicationOutput = mbTrim($hackApplication->pumpOutLog());
            $country = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
            Efficiency::addValue($connectionIndex, $connectionEfficiencyLevel);
            if ($hackApplicationOutput) {
                $label  = "\n$country";
                if (count(mbSplitLines($hackApplicationOutput)) > 5) {
                   $label .= "\n" . $openVpnConfig->getProvider()->getName() . "\n" . $openVpnConfig->getOvpnFileBasename();
                   if ($connectionEfficiencyLevel !== null) {
                       $label .="\nResponse rate   $connectionEfficiencyLevel%";
                   }
                }
                _echo($connectionIndex, $label, $hackApplicationOutput);
            }

            // ------------------- Check the Hack applications alive state and VPN connection effectiveness -------------------
            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
            $hackApplicationIsAlive = $hackApplication->isAlive();
            if (!$hackApplicationIsAlive  ||  $connectionEfficiencyLevel === 0) {

                // ------------------- Check  alive state -------------------
                if (! $hackApplicationIsAlive) {
                    $exitCode = $hackApplication->getExitCode();
                    $message = "\n\n" . Term::red
                             . "Application " . ($exitCode === 0 ? 'was terminated' : 'died with exit code ' . $exitCode)
                             . Term::clear;
                    _echo($connectionIndex, '', $message);
                    $hackApplication->terminate(true);
                }

                // ------------------- Check effectiveness -------------------
                if ($connectionEfficiencyLevel === 0) {
                    $message = "\n" . Term::red
                             . "Zero efficiency. Terminating"
                             . Term::clear;
                    _echo($connectionIndex, '', $message);
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


            if (count($VPN_CONNECTIONS) < 5  ||  isTimeForBrake()) {
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
                $openVpnConfig = $vpnConnection->getOpenVpnConfig();
                $vpnName = $openVpnConfig->getProvider()->getName() . ' - ' . $openVpnConfig->getOvpnFileBasename();
                $vpnNamePadded = str_pad($vpnName, 50);
                _echo($connectionIndex, $country, $vpnNamePadded, true, true);

                $ping = $vpnConnection->checkPing();
                if ($ping) {
                    echo "  [Ping OK]\n";
                } else {
                    echo Term::red . '  [Ping timeout]' . Term::clear . "\n";
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

function terminateSession()
{
    global $LOG_BADGE_WIDTH, $LONG_LINE, $VPN_CONNECTIONS;

    echo "\n\n\n" . $LONG_LINE . "\n\n\n";

    Efficiency::reset();
    ResourcesConsumption::finishTracking();

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (gettype($hackApplication) === 'object') {
                $hackApplication->setReadChildProcessOutput(false);
                $hackApplication->clearLog();
                $hackApplication->terminate();
                echo $hackApplication->pumpOutLog() . "\n";
            }
        }
    }

    echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Waiting 10 seconds\n"; sleep(10);

    if (is_array($VPN_CONNECTIONS)  &&  count($VPN_CONNECTIONS)) {
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            $vpnConnection->clearLog();
            $vpnConnection->terminate();
            echo $vpnConnection->getLog();
            unset($VPN_CONNECTIONS[$connectionIndex]);
        }
    }

    writeStatistics();

    echo "\n\n\n" . str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "SESSION FINISHED\n";
}