<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/ResourcesConsumption.php';
require_once __DIR__ . '/open-vpn/OpenVpnProvider.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/DB1000N/db1000nAutoUpdater.php';
require_once __DIR__ . '/HackApplication.php';

//-------------------------------------------------------

$LOG_WIDTH = 115;
$LOG_PADDING_LEFT = 2;
$LOG_BADGE_WIDTH = 23;
$LOG_BADGE_PADDING_LEFT = 1;
$LOG_BADGE_PADDING_RIGHT = 1;
$LONG_LINE = str_repeat('─', $LOG_WIDTH + $LOG_BADGE_WIDTH);
/*$REDUCE_DB1000N_OUTPUT = false;*/
$ONE_VPN_SESSION_DURATION = 15 * 60;
$PING_INTERVAL = 5 * 60;
$VPN_CONNECTIONS = [];

function calculateResources()
{
    global
    $VPN_QUANTITY_PER_CPU,
    $VPN_QUANTITY_PER_1_GIB_RAM,
    $FIXED_VPN_QUANTITY,
    $IS_IN_DOCKER,
    $OS_RAM_CAPACITY,
    $CPU_QUANTITY,
    $CPU_ARCHITECTURE,
    $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;

    passthru('reset');  // Clear console

    if ($CPU_ARCHITECTURE !== 'x86_64') {
        echo "Cpu architecture $CPU_ARCHITECTURE\n";
    }

    $VPN_QUANTITY_PER_CPU       = 10;
    $VPN_QUANTITY_PER_1_GIB_RAM = 6;
    $FIXED_VPN_QUANTITY         = 0;

    if (($config = getDockerConfig())) {
        $IS_IN_DOCKER = true;
        echo "Docker container detected\n";
        $OS_RAM_CAPACITY = $config['memory'];
        $CPU_QUANTITY = $config['cpus'];
        $FIXED_VPN_QUANTITY = $config['vpnQuantity'];
    } else {
        $IS_IN_DOCKER = false;
        $OS_RAM_CAPACITY = bytesToGiB(ResourcesConsumption::getRAMCapacity());
        $CPU_QUANTITY    = ResourcesConsumption::getCPUQuantity();
    }

    if ($FIXED_VPN_QUANTITY) {
        echo "The script is configured to establish $FIXED_VPN_QUANTITY VPN connection(s)\n";
        $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL = $FIXED_VPN_QUANTITY;
    } else {

        $connectionsLimitByCpu = round($CPU_QUANTITY * $VPN_QUANTITY_PER_CPU);
        echo "Detected $CPU_QUANTITY virtual CPU core(s). This grants $connectionsLimitByCpu parallel VPN connections\n";

        $connectionsLimitByRam = round(($OS_RAM_CAPACITY - ($IS_IN_DOCKER  ?  0.5 : 1)) * $VPN_QUANTITY_PER_1_GIB_RAM);
        $connectionsLimitByRam = $connectionsLimitByRam < 1  ?  0 : $connectionsLimitByRam;
        echo "Detected $OS_RAM_CAPACITY GiB of RAM. This grants $connectionsLimitByRam parallel VPN connections\n";

        $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL = min($connectionsLimitByCpu, $connectionsLimitByRam);
        echo "Script will try to establish $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL parallel VPN connections";

        if ($connectionsLimitByCpu > $connectionsLimitByRam) {
            echo " (limit by RAM)\n";
        } else {
            echo " (limit by CPU cores)\n";
        }

        if ($PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL < 1) {
            _die("Not enough resources");
        }

    }

    echo "\n";
}

passthru('ulimit -n 102400');
calculateResources();
OpenVpnProvider::initStatic();
SelfUpdate::constructStatic();

$SESSIONS_COUNT = 0;
function initSession()
{
    global $SCRIPT_VERSION,
           $SESSIONS_COUNT,
           $FIXED_VPN_QUANTITY,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
           $VPN_CONNECTIONS_ESTABLISHED_COUNT,
           $CONNECT_PORTION_SIZE,
           $MAX_FAILED_VPN_CONNECTIONS_QUANTITY;

    $SESSIONS_COUNT++;
    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
    }

    $newSessionMessage = "db1000nX100 DDoS script version " . SelfUpdate::getSelfVersion() . "\nStarting $SESSIONS_COUNT session at " . date('Y/m/d H:i:s');
    echo "$newSessionMessage\n";
    syslog(LOG_INFO, $newSessionMessage);

    //-----------------------------------------------------------

    if ($SESSIONS_COUNT === 1  ||  $FIXED_VPN_QUANTITY) {
        $PARALLEL_VPN_CONNECTIONS_QUANTITY = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    } else {
        $previousSessionAverageCPUUsage = ResourcesConsumption::getAverageCPUUsageSinceStart();
        echo 'Average CPU usage during previous session was ' . $previousSessionAverageCPUUsage . "%\n";
        $previousSessionPeakRAMUsage = ResourcesConsumption::getPeakRAMUsageSinceStart();
        echo 'Peak    RAM usage during previous session was ' . $previousSessionPeakRAMUsage . "%\n";

        if (
              ($previousSessionAverageCPUUsage >= 100  ||  $previousSessionPeakRAMUsage >= 95)
            && $PARALLEL_VPN_CONNECTIONS_QUANTITY > max(5, $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL / 4)         // Don't decrease less than 1/4 from initial calculation
        ) {
            $PARALLEL_VPN_CONNECTIONS_QUANTITY = round($PARALLEL_VPN_CONNECTIONS_QUANTITY * 0.8);
            echo "Resources usage was to height. Reducing quantity of parallel VPN connections by 20%\n";
        } else if (
            ($previousSessionAverageCPUUsage < 85  &&  $previousSessionPeakRAMUsage < 80)
            &&  $PARALLEL_VPN_CONNECTIONS_QUANTITY < $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL * 3          // Don't rise more than x3 from initial calculation
            &&  $VPN_CONNECTIONS_ESTABLISHED_COUNT > $PARALLEL_VPN_CONNECTIONS_QUANTITY * 3 / 4              // At least 3/4 connections were established on previous session
        ) {
            if ($previousSessionAverageCPUUsage < 60  &&  $previousSessionPeakRAMUsage < 60) {
                $increasePercent = 20;
            } else {
                $increasePercent = 10;
            }
            $increaseMultiplier = 1 + $increasePercent / 100;

            $PARALLEL_VPN_CONNECTIONS_QUANTITY = round($PARALLEL_VPN_CONNECTIONS_QUANTITY * $increaseMultiplier);
            echo "Resources usage was incomplete. Increasing quantity of parallel VPN connections by $increasePercent%\n";
        }
    }

    if ($SESSIONS_COUNT !== 1) {
        echo "Script will try to establish $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connections";
        if ($PARALLEL_VPN_CONNECTIONS_QUANTITY !== $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL) {
            echo" (initially calculated $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL)";
        }
        echo "\n";
    }

    $CONNECT_PORTION_SIZE                 = max(5, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 5));
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY  = max(10, $CONNECT_PORTION_SIZE);

    //-----------------------------------------------------------

    if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
        db1000nAutoUpdater::update();
        SelfUpdate::constructStatic();
    }

    HackApplication::reset();
    Efficiency::reset();

    echo "\n\nEstablishing VPN connections. Please, wait ...\n";
}

//gnome-terminal --window --maximize -- /bin/bash -c "/root/DDOS/db1000nx100-su-run.elf ; read -p \"Program was terminated\""
//apt -y install  procps kmod iputils-ping curl php-cli php-mbstring php-curl openvpn git