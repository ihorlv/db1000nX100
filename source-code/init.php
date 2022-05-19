<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Config.php';
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

$PING_INTERVAL = 5.5 * 60;
$VPN_CONNECTIONS = [];
$SCRIPT_STARTED_AT = time();
$OS_RAM_CAPACITY    = bytesToGiB(ResourcesConsumption::getRAMCapacity());
$CPU_CORES_QUANTITY =            ResourcesConsumption::getCPUQuantity();
$VPN_QUANTITY_PER_CPU          = 10;
$VPN_QUANTITY_PER_1_GIB_RAM    = 15;
$DB1000N_SCALE_MAX_INITIAL     = 1;
$DB1000N_SCALE_MIN             = 0.01;
$DB1000N_SCALE_INITIAL         = 0.02;
$DB1000N_SCALE                 = $DB1000N_SCALE_INITIAL;

//----------------------------------------------

checkMaxOpenFilesLimit();
global $TEMP_DIR;
rmdirRecursive($TEMP_DIR);
calculateResources();

//----------------------------------------------

function calculateResources()
{
    global
    $VPN_QUANTITY_PER_CPU,
    $VPN_QUANTITY_PER_1_GIB_RAM,
    $FIXED_VPN_QUANTITY,
    $IS_IN_DOCKER,
    $DOCKER_HOST,
    $OS_RAM_CAPACITY,
    $MAX_RAM_USAGE,
    $CPU_CORES_QUANTITY,
    $MAX_CPU_CORES_USAGE,
    $DB1000N_SCALE_MAX,
    $DB1000N_SCALE_MAX_INITIAL,
    $NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS,
    $MAX_VPN_NETWORK_SPEED,
    $ONE_VPN_SESSION_DURATION,
    $CPU_ARCHITECTURE,
    $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
    $LOGS_ENABLED;

    $addToLog = [];

    if ($CPU_ARCHITECTURE !== 'x86_64') {
        MainLog::log("Cpu architecture $CPU_ARCHITECTURE");
    }

    $dockerHost = Config::$data['dockerHost'] ?? false;
    if ($dockerHost) {
        MainLog::log("Docker container in $dockerHost host");
        $IS_IN_DOCKER = true;
        $DOCKER_HOST = strtolower($dockerHost);
    } else {
        $IS_IN_DOCKER = false;
        $DOCKER_HOST = '';
    }

    $vpnMaxConnectionsLimit = (int) Config::$data['vpnMaxConnectionsLimit'];
    if ($vpnMaxConnectionsLimit) {
        $FIXED_VPN_QUANTITY = $vpnMaxConnectionsLimit;
        $addToLog[] = "Maximal VPN connections limit: $FIXED_VPN_QUANTITY";
    }

    $cpuUsageLimit = (int) Config::$data['cpuUsageLimit'];
    if ($cpuUsageLimit > 9  &&  $cpuUsageLimit < 100) {
        $MAX_CPU_CORES_USAGE = (int) round($cpuUsageLimit / 100 * $CPU_CORES_QUANTITY);
        $MAX_CPU_CORES_USAGE = max(0.5, $MAX_CPU_CORES_USAGE);

        $DB1000N_SCALE_MAX = intRound($DB1000N_SCALE_MAX_INITIAL * $cpuUsageLimit / 100);
        $addToLog[] = "Cpu usage limit: $cpuUsageLimit%";

        /* crutch for bug https://github.com/docker/for-win/issues/12730 *
        if ($IS_IN_DOCKER  &&  $DOCKER_HOST === 'windows') {
            $MAX_VPN_NETWORK_SPEED = $cpuUsageLimit > 50  ?  0.2 : 0.1;
            $DB1000N_SCALE_MAX     = $cpuUsageLimit > 50  ?  0.2 : 0.1;
        }
        /* end of crutch */

    } else {
        $MAX_CPU_CORES_USAGE = $CPU_CORES_QUANTITY;
        $DB1000N_SCALE_MAX = $DB1000N_SCALE_MAX_INITIAL;
    }

    $ramUsageLimit = (int) Config::$data['ramUsageLimit'];
    if ($ramUsageLimit > 9  &&  $ramUsageLimit < 100) {
        $MAX_RAM_USAGE = roundLarge($ramUsageLimit / 100 * $OS_RAM_CAPACITY);
        $addToLog[] = "Ram usage limit: $ramUsageLimit%";
    } else {
        $MAX_RAM_USAGE = $OS_RAM_CAPACITY;
    }

    $networkUsageLimit = (int) Config::$data['networkUsageLimit'];
    if ($networkUsageLimit > 19  &&  $networkUsageLimit < 100) {
        $NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS = $networkUsageLimit;
        $addToLog[] = "Network usage limit: $networkUsageLimit%";
    } else {
        $NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS = 100;
    }

    $ONE_VPN_SESSION_DURATION = 15 * 60;
    $oneSessionDuration = (int) Config::$data['oneSessionDuration'];
    if ($oneSessionDuration  &&  $oneSessionDuration !== $ONE_VPN_SESSION_DURATION ) {
        $ONE_VPN_SESSION_DURATION = $oneSessionDuration;
        $addToLog[] = "One session duration: $oneSessionDuration seconds";
    }

    $logsEnabled = (bool) Config::$data['logsEnabled'];
    if ($logsEnabled) {
        $LOGS_ENABLED = true;
        if (Config::$putYourOvpnFilesHerePath) {
            MainLog::moveLog(Config::$putYourOvpnFilesHerePath);
        }
    } else {
        $addToLog[] = "No log file";
        $LOGS_ENABLED = false;
    }

    if (count($addToLog)) {
        MainLog::log("User defined settings:\n    " . implode("\n    ", $addToLog), 2);
    }

    //---

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

    if (!$PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL) {
        _die("Not enough resources");
    }

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
           $DB1000N_SCALE,
           $DB1000N_SCALE_MAX,
           $DB1000N_SCALE_MIN;

    $SESSIONS_COUNT++;
    ResourcesConsumption::startTaskTimeTracking('session');
    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
    }

    $newSessionMessage = "db1000nX100 DDoS script version " . SelfUpdate::getSelfVersion() . "\nStarting $SESSIONS_COUNT session at " . date('Y/m/d H:i:s');
    MainLog::log($newSessionMessage);

    //-----------------------------------------------------------
    $PARALLEL_VPN_CONNECTIONS_QUANTITY   = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    $CONNECT_PORTION_SIZE                = max(5,  round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 3));
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY = max(10, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 5));

    if ($SESSIONS_COUNT !== 1) {

        $previousSessionSystemAverageCPUUsage = ResourcesConsumption::getSystemAverageCPUUsageFromStartToFinish();
        MainLog::log('System      average CPU     usage during previous session was ' . $previousSessionSystemAverageCPUUsage . "% of {$CPU_CORES_QUANTITY} core(s) installed");
        $previousSessionSystemAverageRamUsage = ResourcesConsumption::getSystemAverageRamUsageFromStartToFinish();
        MainLog::log('System      average RAM     usage during previous session was ' . $previousSessionSystemAverageRamUsage . "% of {$OS_RAM_CAPACITY}GiB installed");

        $previousSessionProcessesAverageCPUUsage = ResourcesConsumption::getProcessesAverageCPUUsageFromStartToFinish();
        MainLog::log('db1000nX100 average CPU     usage during previous session was ' . $previousSessionProcessesAverageCPUUsage . "% of {$MAX_CPU_CORES_USAGE} core(s) allowed");
        $previousSessionProcessesPeakRAMUsage = ResourcesConsumption::getProcessesPeakRAMUsageFromStartToFinish();
        MainLog::log('db1000nX100 peak    RAM     usage during previous session was ' . $previousSessionProcessesPeakRAMUsage . "% of {$MAX_RAM_USAGE}GiB allowed");

        $previousSessionUsageValues = [
            'SystemAverageCPUUsage'          => $previousSessionSystemAverageCPUUsage,
            'SystemAverageRamUsage'          => $previousSessionSystemAverageRamUsage,
            'db1000nX100AverageCPUUsage'     => $previousSessionProcessesAverageCPUUsage,
            'db1000nX100PeakRAMUsage'        => $previousSessionProcessesPeakRAMUsage
        ];
        $previousSessionHighestUsageValue       = max($previousSessionUsageValues);
        $previousSessionHighestUsageParameter   = array_search($previousSessionHighestUsageValue, $previousSessionUsageValues);
        MainLog::log("Previous session highest used resource was " . Term::bold . " $previousSessionHighestUsageParameter $previousSessionHighestUsageValue%" . Term::clear);

        $maxUsage = 98;
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
            $currentSessionScale = min($currentSessionScale, $DB1000N_SCALE_MAX);
            $currentSessionScale = max($currentSessionScale, $DB1000N_SCALE_MIN);

            if ($currentSessionScale < $previousSessionScale) {
                MainLog::log("This resources usage was higher then $maxUsage%, decreasing db1000n scale value from $previousSessionScale to $currentSessionScale  (range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX)");
            } else if ($currentSessionScale > $previousSessionScale){
                MainLog::log("This resources usage was lower then $minUsage%, increasing db1000n scale value from $previousSessionScale to $currentSessionScale  (range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX)");
            }
            $DB1000N_SCALE = $currentSessionScale;
        }

    }

    MainLog::log('');
    if (!isset($fitUsageToValue) ||  !$fitUsageToValue) {
        MainLog::log("db1000n scale value $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX");
    }

    //-----------------------------------------------------------

    if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
        db1000nAutoUpdater::update();
        SelfUpdate::update();
    }
    OpenVpnConnection::newIteration();
    HackApplication::newIteration();

    calculateNetworkBandwidth();
    if ($SESSIONS_COUNT === 1) {
        MainLog::log("Reading ovpn files. Please, wait ...", 1, 2);
        OpenVpnProvider::constructStatic();
    }
    MainLog::log("Establishing VPN connections. Please, wait ...", 1, 2);
}

function checkMaxOpenFilesLimit()
{
    $ulimitRequired = 65535;
    $ulimitSoft = (int) _shell_exec('ulimit -Sn');
    $ulimitHard = (int) _shell_exec('ulimit -Hn');
    $ulimit = min($ulimitSoft, $ulimitHard);
    if ($ulimit < $ulimitRequired) {
        _die("Increase open files limit from $ulimit to $ulimitRequired  (ulimit -n)");
    }
}

function calculateNetworkBandwidth()
{
    global $NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS,
           $UPLOAD_SPEED_LIMIT,
           $DOWNLOAD_SPEED_LIMIT;

    if ($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS === 100) {
        return;
    }

    MainLog::log("Starting your internet connection speed test", 1, 2);

    ResourcesConsumption::startTaskTimeTracking('InternetConnectionSpeedTest');
    $output = _shell_exec('speedtest-cli --json');
    ResourcesConsumption::stopTaskTimeTracking( 'InternetConnectionSpeedTest');

    $testJson = @json_decode($output);
    if (
           !is_object($testJson)
        || !$testJson->upload
        || !$testJson->download
    ) {
        MainLog::log("Network speed test failed", 1, 0, MainLog::LOG_GENERAL_ERROR);
        return;
    }
    $uploadSpeed   = $testJson->upload;
    $uploadSpeedMebibits = roundLarge($uploadSpeed / 1024 / 1024);
    $UPLOAD_SPEED_LIMIT  = roundLarge($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS * $uploadSpeedMebibits / 100);

    $downloadSpeed = $testJson->download;
    $downloadSpeedMebibits = roundLarge($downloadSpeed / 1024 / 1024);
    $DOWNLOAD_SPEED_LIMIT  = roundLarge($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS * $downloadSpeedMebibits / 100);

    MainLog::log("Test results: Upload speed $uploadSpeedMebibits Mebibits, set limit to $UPLOAD_SPEED_LIMIT Mebibits ($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS%)");
    MainLog::log("            Download speed $downloadSpeedMebibits Mebibits, set limit to $DOWNLOAD_SPEED_LIMIT Mebibits ($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS%)", 2);
}

//gnome-terminal --window --maximize -- /bin/bash -c "ulimit -Sn 65535 ;   /root/DDOS/x100-suid-run.elf ;   read -p \"Program was terminated\""
