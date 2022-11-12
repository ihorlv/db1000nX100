<?php

passthru('reset');
require_once __DIR__ . '/common.php';

global $TEMP_DIR, $NEW_DIR_ACCESS_MODE;
cleanTmpDir();

require_once __DIR__ . '/composer/vendor/autoload.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/resources-consumption/LinuxResources.php';
require_once __DIR__ . '/resources-consumption/ResourcesConsumption.php';
require_once __DIR__ . '/open-vpn/OpenVpnCommon.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnectionBase.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnectionStatic.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/open-vpn/OpenVpnProvider.php';
require_once __DIR__ . '/open-vpn/OpenVpnStatistics.php';
require_once __DIR__ . '/HackApplication.php';
require_once __DIR__ . '/1000/db1000nApplicationStatic.php';
require_once __DIR__ . '/1000/db1000nApplication.php';
require_once __DIR__ . '/DST/DistressApplicationStatic.php';
require_once __DIR__ . '/DST/DistressApplication.php';

require_once __DIR__ . '/puppeteer-ddos/BrainServerLauncher.php';
require_once __DIR__ . '/puppeteer-ddos/PuppeteerApplicationStatic.php';
require_once __DIR__ . '/puppeteer-ddos/PuppeteerApplication.php';

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
$OS_RAM_CAPACITY    = bytesToGiB(ResourcesConsumption::getSystemRamCapacity());
$CPU_CORES_QUANTITY =            ResourcesConsumption::getSystemCpuQuantity();
$VPN_QUANTITY_PER_CPU             = 10;
$VPN_QUANTITY_PER_1_GIB_RAM       = 10;
$DB1000N_SCALE_MIN                = 0.01;
$DB1000N_SCALE_MAX                = 5;
$DB1000N_SCALE_MAX_STEP           = 0.2;
$DISTRESS_SCALE_MIN               = 40;
$DISTRESS_SCALE_MAX               = 40960;
$DISTRESS_SCALE_MAX_STEP          = 200;
$WAIT_SECONDS_BEFORE_PROCESS_KILL = 2;

$DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();

//----------------------------------------------

checkMaxOpenFilesLimit();
calculateResources();
checkRootUser();

//----------------------------------------------

function calculateResources()
{
    global
    $VPN_QUANTITY_PER_CPU,
    $VPN_QUANTITY_PER_1_GIB_RAM,
    $IT_ARMY_USER_ID,
    $FIXED_VPN_QUANTITY,
    $IS_IN_DOCKER,
    $DOCKER_HOST,
    $OS_RAM_CAPACITY,
    $MAX_RAM_USAGE,
    $CPU_CORES_QUANTITY,
    $MAX_CPU_CORES_USAGE,
    $NETWORK_USAGE_LIMIT,
    $EACH_VPN_BANDWIDTH_MAX_BURST,
    $ONE_SESSION_MIN_DURATION,
    $ONE_SESSION_MAX_DURATION,
    $DELAY_AFTER_SESSION_MIN_DURATION,
    $DELAY_AFTER_SESSION_MAX_DURATION,
    $CPU_ARCHITECTURE,
    $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
    $LOG_FILE_MAX_SIZE_MIB,
    $DB1000N_SCALE,
    $DB1000N_SCALE_INITIAL,
    $DB1000N_SCALE_MAX,
    $DB1000N_SCALE_MIN,
    $DB1000N_CPU_AND_RAM_LIMIT,

    $DISTRESS_SCALE_INITIAL,
    $DISTRESS_SCALE_MIN,
    $DISTRESS_SCALE_MAX,
    $DISTRESS_SCALE,
    $DISTRESS_CPU_AND_RAM_LIMIT,
    $DISTRESS_DIRECT_CONNECTIONS_PERCENT,
    $DISTRESS_TOR_CONNECTIONS_PER_TARGET,
    $DISTRESS_USE_PROXY_POOL,

    $USE_X100_COMMUNITY_TARGETS,
    $PUPPETEER_DDOS_CONNECTIONS_INITIAL,
    $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM,
    $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX;

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

    $IT_ARMY_USER_ID = val(Config::$data, 'itArmyUserId');
    $IT_ARMY_USER_ID = Config::filterOptionValueInt($IT_ARMY_USER_ID, 1, PHP_INT_MAX);
    $IT_ARMY_USER_ID = $IT_ARMY_USER_ID === null  ?  Config::$dataDefault['itArmyUserId'] : $IT_ARMY_USER_ID;
    if ($IT_ARMY_USER_ID !== Config::$dataDefault['itArmyUserId']) {
        $addToLog[] = 'IT Army user id: ' . $IT_ARMY_USER_ID;
    }

    //--

    $FIXED_VPN_QUANTITY = val(Config::$data, 'fixedVpnConnectionsQuantity');
    $FIXED_VPN_QUANTITY = Config::filterOptionValueInt($FIXED_VPN_QUANTITY, 0, 1000);
    $FIXED_VPN_QUANTITY = $FIXED_VPN_QUANTITY === null  ?  Config::$dataDefault['fixedVpnConnectionsQuantity'] : $FIXED_VPN_QUANTITY;
    if ($FIXED_VPN_QUANTITY !== Config::$dataDefault['fixedVpnConnectionsQuantity']) {
        $addToLog[] = "Fixed Vpn connections quantity: $FIXED_VPN_QUANTITY";
    }

    //--

    $cpuUsageLimit = val(Config::$data, 'cpuUsageLimit');
    $cpuUsageLimit = Config::filterOptionValuePercents($cpuUsageLimit, 10, 100);
    $cpuUsageLimit = $cpuUsageLimit === null  ?  Config::$dataDefault['cpuUsageLimit'] : $cpuUsageLimit;
    if ($cpuUsageLimit !== Config::$dataDefault['cpuUsageLimit']) {
        $addToLog[] = "Cpu usage limit: $cpuUsageLimit";
    }
    $MAX_CPU_CORES_USAGE = intRound(intval($cpuUsageLimit) / 100 * $CPU_CORES_QUANTITY);
    $MAX_CPU_CORES_USAGE = max(0.5, $MAX_CPU_CORES_USAGE);

    //--

    $ramUsageLimit = val(Config::$data, 'ramUsageLimit');
    $ramUsageLimit = Config::filterOptionValuePercents($ramUsageLimit, 10, 100);
    $ramUsageLimit = $ramUsageLimit === null  ?  Config::$dataDefault['ramUsageLimit'] : $ramUsageLimit;
    if ($ramUsageLimit !== Config::$dataDefault['ramUsageLimit']) {
        $addToLog[] = "Ram usage limit: $ramUsageLimit";
    }
    $MAX_RAM_USAGE = roundLarge(intval($ramUsageLimit) / 100 * $OS_RAM_CAPACITY);

    //--

    $NETWORK_USAGE_LIMIT = val(Config::$data, 'networkUsageLimit');
    $NETWORK_USAGE_LIMIT = Config::filterOptionValueIntPercents($NETWORK_USAGE_LIMIT, 0, 100000, 0, 100);
    $NETWORK_USAGE_LIMIT = $NETWORK_USAGE_LIMIT === null  ?  Config::$dataDefault['networkUsageLimit'] : $NETWORK_USAGE_LIMIT;
    if ($NETWORK_USAGE_LIMIT !==  Config::$dataDefault['networkUsageLimit']) {
        $addToLog[] = 'Network usage limit: ' . ($NETWORK_USAGE_LIMIT  ?: 'no limit');
    }

    //--

    $EACH_VPN_BANDWIDTH_MAX_BURST = val(Config::$data, 'eachVpnBandwidthMaxBurst');
    $EACH_VPN_BANDWIDTH_MAX_BURST = Config::filterOptionValueInt($EACH_VPN_BANDWIDTH_MAX_BURST, 0, 1000);
    $EACH_VPN_BANDWIDTH_MAX_BURST = $EACH_VPN_BANDWIDTH_MAX_BURST === null  ?  Config::$dataDefault['eachVpnBandwidthMaxBurst'] : $EACH_VPN_BANDWIDTH_MAX_BURST;
    if ($EACH_VPN_BANDWIDTH_MAX_BURST !== Config::$dataDefault['eachVpnBandwidthMaxBurst']) {
        $addToLog[] = "Each Vpn chanel bandwidth maximal burst: $EACH_VPN_BANDWIDTH_MAX_BURST";
    }

    //--

    $LOG_FILE_MAX_SIZE_MIB = val(Config::$data, 'logFileMaxSize');
    $LOG_FILE_MAX_SIZE_MIB = Config::filterOptionValueInt($LOG_FILE_MAX_SIZE_MIB, 0, 5000);
    $LOG_FILE_MAX_SIZE_MIB = $LOG_FILE_MAX_SIZE_MIB === null  ?  Config::$dataDefault['logFileMaxSize'] : $LOG_FILE_MAX_SIZE_MIB;
    if ($LOG_FILE_MAX_SIZE_MIB !== Config::$dataDefault['logFileMaxSize']) {
        $addToLog[] = 'Log file max size: ' . ($LOG_FILE_MAX_SIZE_MIB  ?  $LOG_FILE_MAX_SIZE_MIB . 'MiB': 'No log file');
    }

    if (
            $LOG_FILE_MAX_SIZE_MIB
        &&  Config::$putYourOvpnFilesHerePath
    ) {
        MainLog::moveLog(Config::$putYourOvpnFilesHerePath);
    }

    //--

    $ONE_SESSION_MIN_DURATION = val(Config::$data, 'oneSessionMinDuration');
    $ONE_SESSION_MIN_DURATION = Config::filterOptionValueInt($ONE_SESSION_MIN_DURATION, 2 * 60, 60 * 60);
    $ONE_SESSION_MIN_DURATION = $ONE_SESSION_MIN_DURATION === null  ?  Config::$dataDefault['oneSessionMinDuration'] : $ONE_SESSION_MIN_DURATION;
    if ($ONE_SESSION_MIN_DURATION !== Config::$dataDefault['oneSessionMinDuration']) {
        $addToLog[] = "One session min duration: $ONE_SESSION_MIN_DURATION seconds";
    }

    $ONE_SESSION_MAX_DURATION = val(Config::$data, 'oneSessionMaxDuration');
    $ONE_SESSION_MAX_DURATION = Config::filterOptionValueInt($ONE_SESSION_MAX_DURATION, 2 * 60, 60 * 60);
    $ONE_SESSION_MAX_DURATION = $ONE_SESSION_MAX_DURATION === null  ?  Config::$dataDefault['oneSessionMaxDuration'] : $ONE_SESSION_MAX_DURATION;
    if ($ONE_SESSION_MAX_DURATION !== Config::$dataDefault['oneSessionMaxDuration']) {
        $addToLog[] = "One session max duration: $ONE_SESSION_MAX_DURATION seconds";
    }

    //--

    $DELAY_AFTER_SESSION_MIN_DURATION = val(Config::$data, 'delayAfterSessionMinDuration');
    $DELAY_AFTER_SESSION_MIN_DURATION = Config::filterOptionValueInt($DELAY_AFTER_SESSION_MIN_DURATION, 0, 15 * 60);
    $DELAY_AFTER_SESSION_MIN_DURATION = $DELAY_AFTER_SESSION_MIN_DURATION === null  ?  Config::$dataDefault['delayAfterSessionMinDuration'] : $DELAY_AFTER_SESSION_MIN_DURATION;
    if ($DELAY_AFTER_SESSION_MIN_DURATION !== Config::$dataDefault['delayAfterSessionMinDuration']) {
        $addToLog[] = "Delay after session min duration: $DELAY_AFTER_SESSION_MIN_DURATION seconds";
    }

    $DELAY_AFTER_SESSION_MAX_DURATION = val(Config::$data, 'delayAfterSessionMaxDuration');
    $DELAY_AFTER_SESSION_MAX_DURATION = Config::filterOptionValueInt($DELAY_AFTER_SESSION_MAX_DURATION, 0, 15 * 60);
    $DELAY_AFTER_SESSION_MAX_DURATION = $DELAY_AFTER_SESSION_MAX_DURATION === null  ?  Config::$dataDefault['delayAfterSessionMaxDuration'] : $DELAY_AFTER_SESSION_MAX_DURATION;
    if ($DELAY_AFTER_SESSION_MAX_DURATION !== Config::$dataDefault['delayAfterSessionMaxDuration']) {
        $addToLog[] = "Delay after session max duration: $DELAY_AFTER_SESSION_MAX_DURATION seconds";
    }

    //--

    $DB1000N_SCALE_INITIAL = val(Config::$data, 'initialDB1000nScale');
    $DB1000N_SCALE_INITIAL = Config::filterOptionValueFloat($DB1000N_SCALE_INITIAL, $DB1000N_SCALE_MIN, $DB1000N_SCALE_MAX);
    $DB1000N_SCALE_INITIAL = $DB1000N_SCALE_INITIAL === null  ?  Config::$dataDefault['initialDB1000nScale'] : $DB1000N_SCALE_INITIAL;
    if ($DB1000N_SCALE_INITIAL !== Config::$dataDefault['initialDB1000nScale']) {
        $addToLog[] = "Initial scale for DB1000n is: $DB1000N_SCALE_INITIAL";
    }
    $DB1000N_SCALE = $DB1000N_SCALE_INITIAL;

    //-------

    $DB1000N_CPU_AND_RAM_LIMIT = val(Config::$data, 'db1000nCpuAndRamLimit');
    $DB1000N_CPU_AND_RAM_LIMIT = Config::filterOptionValuePercents($DB1000N_CPU_AND_RAM_LIMIT, 0, 100);
    $DB1000N_CPU_AND_RAM_LIMIT = $DB1000N_CPU_AND_RAM_LIMIT === null  ?  Config::$dataDefault['db1000nCpuAndRamLimit'] : $DB1000N_CPU_AND_RAM_LIMIT;
    if ($DB1000N_CPU_AND_RAM_LIMIT !== Config::$dataDefault['db1000nCpuAndRamLimit']) {
        $addToLog[] = "db1000n Cpu and Ram usage limit: $DB1000N_CPU_AND_RAM_LIMIT";
    }

    //------

    $DISTRESS_SCALE_INITIAL = val(Config::$data, 'initialDistressScale');
    $DISTRESS_SCALE_INITIAL = Config::filterOptionValueInt($DISTRESS_SCALE_INITIAL, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX);
    $DISTRESS_SCALE_INITIAL = $DISTRESS_SCALE_INITIAL === null  ?  Config::$dataDefault['initialDistressScale'] : $DISTRESS_SCALE_INITIAL;
    if ($DISTRESS_SCALE_INITIAL !== Config::$dataDefault['initialDistressScale']) {
        $addToLog[] = "Initial scale for Distress: $DISTRESS_SCALE_INITIAL";
    }
    $DISTRESS_SCALE = $DISTRESS_SCALE_INITIAL;

    $DISTRESS_CPU_AND_RAM_LIMIT = val(Config::$data, 'distressCpuAndRamLimit');
    $DISTRESS_CPU_AND_RAM_LIMIT = Config::filterOptionValuePercents($DISTRESS_CPU_AND_RAM_LIMIT, 0, 100);
    $DISTRESS_CPU_AND_RAM_LIMIT = $DISTRESS_CPU_AND_RAM_LIMIT === null  ?  Config::$dataDefault['distressCpuAndRamLimit'] : $DISTRESS_CPU_AND_RAM_LIMIT;
    if ($DISTRESS_CPU_AND_RAM_LIMIT !== Config::$dataDefault['distressCpuAndRamLimit']) {
        $addToLog[] = "Distress Cpu and Ram usage limit: $DISTRESS_CPU_AND_RAM_LIMIT";
    }

    $DISTRESS_DIRECT_CONNECTIONS_PERCENT = val(Config::$data, 'distressDirectConnectionsPercent');
    $DISTRESS_DIRECT_CONNECTIONS_PERCENT = Config::filterOptionValuePercents($DISTRESS_DIRECT_CONNECTIONS_PERCENT, 0, 100);
    $DISTRESS_DIRECT_CONNECTIONS_PERCENT = $DISTRESS_DIRECT_CONNECTIONS_PERCENT === null  ?  Config::$dataDefault['distressDirectConnectionsPercent'] : $DISTRESS_DIRECT_CONNECTIONS_PERCENT;
    if ($DISTRESS_DIRECT_CONNECTIONS_PERCENT !== Config::$dataDefault['distressDirectConnectionsPercent']) {
        $addToLog[] = "Distress direct connections percent: $DISTRESS_DIRECT_CONNECTIONS_PERCENT";
    }

    $DISTRESS_TOR_CONNECTIONS_PER_TARGET = val(Config::$data, 'distressTorConnectionsPerTarget');
    $DISTRESS_TOR_CONNECTIONS_PER_TARGET = Config::filterOptionValueInt($DISTRESS_TOR_CONNECTIONS_PER_TARGET, 0, 100);
    $DISTRESS_TOR_CONNECTIONS_PER_TARGET = $DISTRESS_TOR_CONNECTIONS_PER_TARGET === null  ?  Config::$dataDefault['distressTorConnectionsPerTarget'] : $DISTRESS_TOR_CONNECTIONS_PER_TARGET;
    if ($DISTRESS_TOR_CONNECTIONS_PER_TARGET !== Config::$dataDefault['distressTorConnectionsPerTarget']) {
        $addToLog[] = "Distress Tor connections per target: $DISTRESS_TOR_CONNECTIONS_PER_TARGET";
    }

    $DISTRESS_USE_PROXY_POOL = val(Config::$data, 'distressUseProxyPool');
    $DISTRESS_USE_PROXY_POOL = boolval(Config::filterOptionValueBoolean($DISTRESS_USE_PROXY_POOL));
    if ($DISTRESS_USE_PROXY_POOL != Config::$dataDefault['distressUseProxyPool']) {
        $addToLog[] = 'Distress use proxy pool from Mhddos: ' . ($DISTRESS_USE_PROXY_POOL ? 'true' : 'false');
    }

    //-------

    $USE_X100_COMMUNITY_TARGETS = val(Config::$data, 'useX100CommunityTargets');
    $USE_X100_COMMUNITY_TARGETS = boolval(Config::filterOptionValueBoolean($USE_X100_COMMUNITY_TARGETS));
    if ($USE_X100_COMMUNITY_TARGETS != Config::$dataDefault['useX100CommunityTargets']) {
        $addToLog[] = "Use X100 community targets: " . ($USE_X100_COMMUNITY_TARGETS ? 'true' : 'false');
    }

    //-------

    $PUPPETEER_DDOS_CONNECTIONS_INITIAL = val(Config::$data, 'puppeteerDdosConnectionsInitial');
    $PUPPETEER_DDOS_CONNECTIONS_INITIAL = Config::filterOptionValueIntPercents($PUPPETEER_DDOS_CONNECTIONS_INITIAL, 0, PHP_INT_MAX, 0, 100);
    $PUPPETEER_DDOS_CONNECTIONS_INITIAL = $PUPPETEER_DDOS_CONNECTIONS_INITIAL === null  ?  Config::$dataDefault['puppeteerDdosConnectionsInitial'] : $PUPPETEER_DDOS_CONNECTIONS_INITIAL;
    if ($PUPPETEER_DDOS_CONNECTIONS_INITIAL !==  Config::$dataDefault['puppeteerDdosConnectionsInitial']) {
        $addToLog[] = "Puppeteer DDoS initial connections count: $PUPPETEER_DDOS_CONNECTIONS_INITIAL";
    }

    $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM = val(Config::$data, 'puppeteerDdosConnectionsMaximum');
    $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM = Config::filterOptionValueIntPercents($PUPPETEER_DDOS_CONNECTIONS_MAXIMUM, 0, PHP_INT_MAX, 0, 100);
    $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM = $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM === null  ?  Config::$dataDefault['puppeteerDdosConnectionsMaximum'] : $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM;
    if ($PUPPETEER_DDOS_CONNECTIONS_MAXIMUM !==  Config::$dataDefault['puppeteerDdosConnectionsMaximum']) {
        $addToLog[] = "Puppeteer DDoS maximum connections count: $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM";
    }

    $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX = val(Config::$data, 'puppeteerDdosBrowserVisibleInVBox');
    $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX = boolval(Config::filterOptionValueBoolean($PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX));
    if ($PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX != Config::$dataDefault['puppeteerDdosBrowserVisibleInVBox']) {
        $addToLog[] = 'Puppeteer DDoS visible browser in VirtualBox: ' . ($PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX ? 'true' : 'false');
    }

    //------

    if (count($addToLog)) {
        MainLog::log("User defined settings:\n    " . implode("\n    ", $addToLog), 2);
    }

    //---

    if ($FIXED_VPN_QUANTITY) {
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

    // ---

    if (!$dockerHost) {
        Actions::addAction('DelayAfterSession', 'trimDisks');
    }
    Actions::addAction('DelayAfterSession', 'findAndKillAllZombieProcesses');
    Actions::addAction('DelayAfterSession', 'gc_collect_cycles');

    // ---

    MainLog::log('');
    Actions::doAction('AfterCalculateResources');
}

function initSession()
{
    global $SESSIONS_COUNT,
           $VPN_SESSION_STARTED_AT,
           $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY,
           $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL,
           $CONNECT_PORTION_SIZE,
           $MAX_FAILED_VPN_CONNECTIONS_QUANTITY,
           $ONE_SESSION_MIN_DURATION,
           $ONE_SESSION_MAX_DURATION,
           $CURRENT_SESSION_DURATION,
           $DELAY_AFTER_SESSION_MIN_DURATION,
           $DELAY_AFTER_SESSION_MAX_DURATION,
           $DELAY_AFTER_SESSION_DURATION,
           $STATISTICS_BLOCK_INTERVAL,
           $CPU_CORES_QUANTITY,
           $OS_RAM_CAPACITY;

    $SESSIONS_COUNT++;

    if ($SESSIONS_COUNT !== 1) {
        passthru('reset');  // Clear console
    } else {
        findAndKillAllZombieProcesses();
    }

    MainLog::log("X100 DDoS script version " . SelfUpdate::getSelfVersion());
    MainLog::log("Starting $SESSIONS_COUNT session at " . date('Y/m/d H:i:s'), 2);
    $VPN_SESSION_STARTED_AT = time();
    $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT = 0;

    Actions::doAction('BeforeInitSession');

    //-----------------------------------------------------------
    $PARALLEL_VPN_CONNECTIONS_QUANTITY = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY = fitBetweenMinMax(10, false, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 4));
    $CONNECT_PORTION_SIZE = fitBetweenMinMax(20, false, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 2));

    if ($SESSIONS_COUNT !== 1) {
        ResourcesConsumption::calculateNetworkBandwidthLimit(1, 2);
        $usageValues = ResourcesConsumption::previousSessionUsageValues();

        MainLog::log('System      average  CPU   usage during previous session was ' . padPercent($usageValues['systemAverageCpuUsage']['current']) . " of {$CPU_CORES_QUANTITY} core(s) installed");
        MainLog::log('System      average  RAM   usage during previous session was ' . padPercent($usageValues['systemAverageRamUsage']['current']) . " of {$OS_RAM_CAPACITY}GiB installed");
        MainLog::log('System      peak     RAM   usage during previous session was ' . padPercent($usageValues['systemPeakRamUsage']['current']));
        MainLog::log('System      average  SWAP  usage during previous session was ' . padPercent($usageValues['systemAverageSwapUsage']['current']) . " of " . humanBytes(LinuxResources::getSystemSwapCapacity()) . " available");
        MainLog::log('System      peak     SWAP  usage during previous session was ' . padPercent($usageValues['systemPeakSwapUsage']['current']));
        MainLog::log('System      average  TMP   usage during previous session was ' . padPercent($usageValues['systemAverageTmpUsage']['current']) . " of " . humanBytes(LinuxResources::getSystemTmpCapacity()) . " available");
        MainLog::log('System      peak     TMP   usage during previous session was ' . padPercent($usageValues['systemPeakTmpUsage']['current']));

        if (isset($usageValues['systemTopNetworkUsageReceive'])) {
            $netUsageMessageTitle = 'System      top  network   usage during previous session was: ';
            $netUsageMessage = $netUsageMessageTitle
                . 'download ' . padPercent($usageValues['systemTopNetworkUsageReceive']['current']) . ' of ' . humanBytes(ResourcesConsumption::$receiveSpeedLimitBits, HUMAN_BYTES_BITS) . " allowed,\n"
                . str_repeat(' ', strlen($netUsageMessageTitle))
                . 'upload   ' . padPercent($usageValues['systemTopNetworkUsageTransmit']['current']) . ' of ' . humanBytes(ResourcesConsumption::$transmitSpeedLimitBits, HUMAN_BYTES_BITS) . ' allowed';

            MainLog::log($netUsageMessage);
        }

        MainLog::log('X100        average  CPU   usage during previous session was ' . padPercent($usageValues['x100ProcessesAverageCpuUsage']['current']), 1, 1);
        MainLog::log('X100        average  RAM   usage during previous session was ' . padPercent($usageValues['x100ProcessesAverageMemUsage']['current']));
        MainLog::log('X100        peak     RAM   usage during previous session was ' . padPercent($usageValues['x100ProcessesPeakMemUsage']['current']), 2);

        MainLog::log('MainCliPhp  average  CPU   usage during previous session was ' . padPercent($usageValues['x100MainCliPhpCpuUsage']['current']));
        MainLog::log('MainCliPhp  average  RAM   usage during previous session was ' . padPercent($usageValues['x100MainCliPhpMemUsage']['current']), 2);

        $usageValues = Actions::doFilter('InitSessionResourcesCorrection', $usageValues);
    }

    //-----------------------------------------------------------

    Actions::doAction('AfterInitSession');

    if ($SESSIONS_COUNT === 1) {
        ResourcesConsumption::calculateNetworkBandwidthLimit(1);
    }

    $CURRENT_SESSION_DURATION = rand($ONE_SESSION_MIN_DURATION, $ONE_SESSION_MAX_DURATION);
    $STATISTICS_BLOCK_INTERVAL = intRound($CURRENT_SESSION_DURATION / 2);
    $DELAY_AFTER_SESSION_DURATION = rand($DELAY_AFTER_SESSION_MIN_DURATION, $DELAY_AFTER_SESSION_MAX_DURATION);
    MainLog::log('This session will last ' . humanDuration($CURRENT_SESSION_DURATION) . ', and after will be ' . humanDuration($DELAY_AFTER_SESSION_DURATION) . ' idle delay', 2, 1);

    $hackApplicationPossibleInstancesCount = HackApplication::countPossibleInstances();
    if ($hackApplicationPossibleInstancesCount > $PARALLEL_VPN_CONNECTIONS_QUANTITY) {
        MainLog::log("Establishing $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connection(s). Please, wait ...", 2);
    } else {
        MainLog::log("Establishing $hackApplicationPossibleInstancesCount of $PARALLEL_VPN_CONNECTIONS_QUANTITY VPN connection(s). Please, wait ...", 2);
        $PARALLEL_VPN_CONNECTIONS_QUANTITY = $hackApplicationPossibleInstancesCount;
    }
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

function checkRootUser()
{
    if (!(
            getmyuid() === 0
        ||  getmygid() === 0
        ||  in_array(0, posix_getgroups())
    )) {
        _die("Root access is required to run this script");
    }
}

function cleanTmpDir()
{
    global $TEMP_DIR, $NEW_DIR_ACCESS_MODE;
    rmdirRecursive($TEMP_DIR);

    // --- delete chromium temp files ---

    $tmpFilesList = getFilesListOfDirectory('/tmp', true);

    $chromiumFilesList = searchInFilesList(
        $tmpFilesList,
        SEARCH_IN_FILES_LIST_RETURN_FILES + SEARCH_IN_FILES_LIST_RETURN_DIRS,
        preg_quote('chromium')
    );

    foreach ($chromiumFilesList as $unpackedFilePath) {
        is_dir($unpackedFilePath)  ?  rmdir($unpackedFilePath) : unlink($unpackedFilePath);
    }

    // ---

    @mkdir($TEMP_DIR, $NEW_DIR_ACCESS_MODE, true);
    Actions::doAction('AfterCleanTempDir');
}

function findAndKillAllZombieProcesses()
{
    $x100ProcessesPidsList = [];
    getProcessPidWithChildrenPids(posix_getpid(), true, $x100ProcessesPidsList);

    $killZombieProcessesData = [
        'linuxProcesses' => getLinuxProcesses(),
        'x100ProcessesPidsList' => $x100ProcessesPidsList
    ];
    Actions::doFilter('KillZombieProcesses', $killZombieProcessesData);
}

function padPercent($percent) : string
{
    return str_pad($percent, 3, ' ', STR_PAD_LEFT) . '%';
}

//xfce4-terminal  --maximize  --execute    /bin/bash -c "super x100-run.bash ;   read -p \"Program was terminated\""