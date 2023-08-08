<?php

passthru('reset');
require_once __DIR__ . '/common.php';

global $TEMP_DIR, $NEW_DIR_ACCESS_MODE;
cleanTmpDir();

require_once __DIR__ . '/composer/vendor/autoload.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/SFunctions.php';
require_once __DIR__ . '/TelegramNotifications.php';

require_once __DIR__ . '/resources-consumption/NetworkConsumption.php';
require_once __DIR__ . '/resources-consumption/LinuxResources.php';
require_once __DIR__ . '/resources-consumption/LoadAverageStatistics.php';
require_once __DIR__ . '/resources-consumption/ResourcesConsumption.php';
require_once __DIR__ . '/resources-consumption/TimeTracking.php';

require_once __DIR__ . '/open-vpn/OpenVpnCommon.php';
require_once __DIR__ . '/open-vpn/OpenVpnConfig.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnectionBase.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnectionStatic.php';
require_once __DIR__ . '/open-vpn/ConnectionQualityTest.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/open-vpn/OpenVpnProvider.php';
require_once __DIR__ . '/open-vpn/OpenVpnStatistics.php';

require_once __DIR__ . '/HackApplication.php';
require_once __DIR__ . '/1000/db1000nApplicationStatic.php';
require_once __DIR__ . '/1000/db1000nApplication.php';

require_once __DIR__ . '/DST/DistressGetConfig.php';
require_once __DIR__ . '/DST/DistressGetTargetsFile.php';
require_once __DIR__ . '/DST/DistressApplicationStatic.php';
require_once __DIR__ . '/DST/DistressApplication.php';

require_once __DIR__ . '/puppeteer-ddos/BrainServerLauncher.php';
require_once __DIR__ . '/puppeteer-ddos/PuppeteerApplicationStatic.php';
require_once __DIR__ . '/puppeteer-ddos/PuppeteerApplication.php';

//-------------------------------------------------------

$STDIN = fopen('php://stdin', 'r');
stream_set_blocking($STDIN, false);

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
$DB1000N_SCALE_MIN                = 0.001;
$DB1000N_SCALE_MAX                = 10;
$DISTRESS_SCALE_MIN               = 20;
$WAIT_SECONDS_BEFORE_PROCESS_KILL = 5;

$DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();

//----------------------------------------------

checkMaxOpenFilesLimit();
calculateResources();
checkRootUser();

//----------------------------------------------

function calculateResources()
{
    global
    $IT_ARMY_USER_ID,
    $FIXED_VPN_QUANTITY,
    $VPN_CONNECTIONS_QUANTITY_PER_CPU,
    $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM,
    $IS_IN_DOCKER,
    $DOCKER_HOST,
    $OS_RAM_CAPACITY,
    $RAM_USAGE_GOAL,
    $CPU_CORES_QUANTITY,
    $CPU_USAGE_GOAL,
    $NETWORK_USAGE_GOAL,
    $EACH_VPN_BANDWIDTH_MAX_BURST,
    $ONE_SESSION_MIN_DURATION,
    $ONE_SESSION_MAX_DURATION,
    $DELAY_AFTER_SESSION_MIN_DURATION,
    $DELAY_AFTER_SESSION_MAX_DURATION,
    $WAIT_SECONDS_BEFORE_PROCESS_KILL,
    $VPN_DISCONNECT_TIMEOUT,
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
    $DISTRESS_USE_TOR,
    $DISTRESS_USE_PROXY_POOL,
    $DISTRESS_USE_DIRECT_UDP_FLOOD,

    $USE_X100_COMMUNITY_TARGETS,
    $PUPPETEER_DDOS_CONNECTIONS_INITIAL,
    $PUPPETEER_DDOS_CONNECTIONS_MAXIMUM,
    $PUPPETEER_DDOS_BROWSER_VISIBLE_IN_VBOX,

    $SHOW_CONSOLE_OUTPUT,
    $ENCRYPT_LOGS,
    $ENCRYPT_LOGS_PUBLIC_KEY,

    $TELEGRAM_NOTIFICATIONS_ENABLED,
    $TELEGRAM_NOTIFICATIONS_TO_USER_ID,
    $TELEGRAM_NOTIFICATIONS_AT_HOURS,
    $TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES,
    $TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES,
    $X100_INSTANCE_TITLE,

    $SOURCE_GUARDIAN_EXPIRATION_DATE;

    // ---

    if ($CPU_ARCHITECTURE !== 'x86_64') {
        MainLog::log("Cpu architecture $CPU_ARCHITECTURE");
    }

    $addToLog = [];

    // ---

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

    $VPN_CONNECTIONS_QUANTITY_PER_CPU = val(Config::$data, 'vpnConnectionsQuantityPerCpu');
    $VPN_CONNECTIONS_QUANTITY_PER_CPU = Config::filterOptionValueInt($VPN_CONNECTIONS_QUANTITY_PER_CPU, 0, 1000);
    $VPN_CONNECTIONS_QUANTITY_PER_CPU = $VPN_CONNECTIONS_QUANTITY_PER_CPU === null  ?  Config::$dataDefault['vpnConnectionsQuantityPerCpu'] : $VPN_CONNECTIONS_QUANTITY_PER_CPU;
    if ($VPN_CONNECTIONS_QUANTITY_PER_CPU !== Config::$dataDefault['vpnConnectionsQuantityPerCpu']) {
        $addToLog[] = "VPN connections quantity per CPU: $VPN_CONNECTIONS_QUANTITY_PER_CPU";
    }

    // ---

    $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM = val(Config::$data, 'vpnConnectionsQuantityPer1GibRam');
    $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM = Config::filterOptionValueInt($VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM, 0, 1000);
    $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM = $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM === null  ?  Config::$dataDefault['vpnConnectionsQuantityPer1GibRam'] : $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM;
    if ($VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM !== Config::$dataDefault['vpnConnectionsQuantityPer1GibRam']) {
        $addToLog[] = "VPN connections quantity per 1 Gib RAM: $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM";
    }
    
    // ---

    $CPU_USAGE_GOAL = val(Config::$data, 'cpuUsageGoal');
    $CPU_USAGE_GOAL = Config::filterOptionValuePercents($CPU_USAGE_GOAL, 10, 100);
    $CPU_USAGE_GOAL = $CPU_USAGE_GOAL === null  ?  Config::$dataDefault['cpuUsageGoal'] : $CPU_USAGE_GOAL;
    if ($CPU_USAGE_GOAL !== Config::$dataDefault['cpuUsageGoal']) {
        $addToLog[] = "Cpu usage goal: $CPU_USAGE_GOAL";
    }

    //--

    $RAM_USAGE_GOAL = val(Config::$data, 'ramUsageGoal');
    $RAM_USAGE_GOAL = Config::filterOptionValuePercents($RAM_USAGE_GOAL, 10, 100);
    $RAM_USAGE_GOAL = $RAM_USAGE_GOAL === null  ?  Config::$dataDefault['ramUsageGoal'] : $RAM_USAGE_GOAL;
    if ($RAM_USAGE_GOAL !== Config::$dataDefault['ramUsageGoal']) {
        $addToLog[] = "Ram usage goal: $RAM_USAGE_GOAL";
    }


    //--

    $NETWORK_USAGE_GOAL = val(Config::$data, 'networkUsageGoal');
    $NETWORK_USAGE_GOAL = Config::filterOptionValueIntPercents($NETWORK_USAGE_GOAL, 0, 100000, 0, 100);
    $NETWORK_USAGE_GOAL = $NETWORK_USAGE_GOAL === null  ?  Config::$dataDefault['networkUsageGoal'] : $NETWORK_USAGE_GOAL;
    if ($NETWORK_USAGE_GOAL !==  Config::$dataDefault['networkUsageGoal']) {
        $addToLog[] = 'Network usage goal: ' . ($NETWORK_USAGE_GOAL  ?: 'no network monitoring');
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
    $ONE_SESSION_MIN_DURATION = Config::filterOptionValueInt($ONE_SESSION_MIN_DURATION, 2 * 60, 24 * 60 * 60);
    $ONE_SESSION_MIN_DURATION = $ONE_SESSION_MIN_DURATION === null  ?  Config::$dataDefault['oneSessionMinDuration'] : $ONE_SESSION_MIN_DURATION;
    if ($ONE_SESSION_MIN_DURATION !== Config::$dataDefault['oneSessionMinDuration']) {
        $addToLog[] = "One session min duration: $ONE_SESSION_MIN_DURATION seconds";
    }

    $ONE_SESSION_MAX_DURATION = val(Config::$data, 'oneSessionMaxDuration');
    $ONE_SESSION_MAX_DURATION = Config::filterOptionValueInt($ONE_SESSION_MAX_DURATION, 2 * 60, 24 * 60 * 60);
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

    $VPN_DISCONNECT_TIMEOUT = val(Config::$data, 'vpnDisconnectTimeout');
    $VPN_DISCONNECT_TIMEOUT = Config::filterOptionValueInt($VPN_DISCONNECT_TIMEOUT, $WAIT_SECONDS_BEFORE_PROCESS_KILL * 2, 3 * 60);
    $VPN_DISCONNECT_TIMEOUT = $VPN_DISCONNECT_TIMEOUT === null  ?  Config::$dataDefault['vpnDisconnectTimeout'] : $VPN_DISCONNECT_TIMEOUT;
    if ($VPN_DISCONNECT_TIMEOUT !== Config::$dataDefault['vpnDisconnectTimeout']) {
        $addToLog[] = "Timeout of VPN disconnect: $VPN_DISCONNECT_TIMEOUT seconds";
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

    $DISTRESS_CPU_AND_RAM_LIMIT = val(Config::$data, 'distressCpuAndRamLimit');
    $DISTRESS_CPU_AND_RAM_LIMIT = Config::filterOptionValuePercents($DISTRESS_CPU_AND_RAM_LIMIT, 0, 100);
    $DISTRESS_CPU_AND_RAM_LIMIT = $DISTRESS_CPU_AND_RAM_LIMIT === null  ?  Config::$dataDefault['distressCpuAndRamLimit'] : $DISTRESS_CPU_AND_RAM_LIMIT;
    if ($DISTRESS_CPU_AND_RAM_LIMIT !== Config::$dataDefault['distressCpuAndRamLimit']) {
        $addToLog[] = "Distress Cpu and Ram usage limit: $DISTRESS_CPU_AND_RAM_LIMIT";
    }

    // ---

    // Should be before initialDistressScale
    $DISTRESS_SCALE_MAX = val(Config::$data, 'maxDistressScale');
    $DISTRESS_SCALE_MAX = Config::filterOptionValueInt($DISTRESS_SCALE_MAX, $DISTRESS_SCALE_MIN);
    $DISTRESS_SCALE_MAX = $DISTRESS_SCALE_MAX === null  ?  Config::$dataDefault['maxDistressScale'] : $DISTRESS_SCALE_MAX;
    if ($DISTRESS_SCALE_MAX !== Config::$dataDefault['maxDistressScale']) {
        $addToLog[] = "Maximal scale for Distress: $DISTRESS_SCALE_MAX";
    }

    // ---

    $DISTRESS_SCALE_INITIAL = val(Config::$data, 'initialDistressScale');
    $DISTRESS_SCALE_INITIAL = Config::filterOptionValueInt($DISTRESS_SCALE_INITIAL, $DISTRESS_SCALE_MIN, $DISTRESS_SCALE_MAX);
    $DISTRESS_SCALE_INITIAL = $DISTRESS_SCALE_INITIAL === null  ?  Config::$dataDefault['initialDistressScale'] : $DISTRESS_SCALE_INITIAL;
    if ($DISTRESS_SCALE_INITIAL !== Config::$dataDefault['initialDistressScale']) {
        $addToLog[] = "Initial scale for Distress: $DISTRESS_SCALE_INITIAL";
    }
    $DISTRESS_SCALE = $DISTRESS_SCALE_INITIAL;

    // ---

    $DISTRESS_USE_TOR = val(Config::$data, 'distressUseTor');
    $DISTRESS_USE_TOR = boolval(Config::filterOptionValueBoolean($DISTRESS_USE_TOR));
    if ($DISTRESS_USE_TOR != Config::$dataDefault['distressUseTor']) {
        $addToLog[] = 'Distress use Tor: '. ( $DISTRESS_USE_TOR ? 'true' : 'false');
    }

    // ---

    $DISTRESS_USE_PROXY_POOL = val(Config::$data, 'distressUseProxyPool');
    $DISTRESS_USE_PROXY_POOL = boolval(Config::filterOptionValueBoolean($DISTRESS_USE_PROXY_POOL));
    if ($DISTRESS_USE_PROXY_POOL != Config::$dataDefault['distressUseProxyPool']) {
        $addToLog[] = 'Distress use proxy pool: ' . ($DISTRESS_USE_PROXY_POOL ? 'true' : 'false');
    }

    // ---

    if ($DISTRESS_USE_PROXY_POOL) {
        $DISTRESS_DIRECT_CONNECTIONS_PERCENT = val(Config::$data, 'distressDirectConnectionsPercent');
        $DISTRESS_DIRECT_CONNECTIONS_PERCENT = Config::filterOptionValuePercents($DISTRESS_DIRECT_CONNECTIONS_PERCENT, 0, 100);
    } else {
        $DISTRESS_DIRECT_CONNECTIONS_PERCENT = '100%';
    }

    $DISTRESS_DIRECT_CONNECTIONS_PERCENT = $DISTRESS_DIRECT_CONNECTIONS_PERCENT === null  ?  Config::$dataDefault['distressDirectConnectionsPercent'] : $DISTRESS_DIRECT_CONNECTIONS_PERCENT;
    if ($DISTRESS_DIRECT_CONNECTIONS_PERCENT !== Config::$dataDefault['distressDirectConnectionsPercent']) {
        $addToLog[] = "Distress direct connections percent: $DISTRESS_DIRECT_CONNECTIONS_PERCENT";
    }

    // ---

    if (intval($DISTRESS_DIRECT_CONNECTIONS_PERCENT)) {
        $DISTRESS_USE_DIRECT_UDP_FLOOD = val(Config::$data, 'distressUseDirectUdpFlood');
        $DISTRESS_USE_DIRECT_UDP_FLOOD = boolval(Config::filterOptionValueBoolean($DISTRESS_USE_DIRECT_UDP_FLOOD));
    } else {
        $DISTRESS_USE_DIRECT_UDP_FLOOD = false;
    }

    if ($DISTRESS_USE_DIRECT_UDP_FLOOD != Config::$dataDefault['distressUseDirectUdpFlood']) {
        $addToLog[] = 'Distress use direct UDP flood: ' . ( $DISTRESS_USE_DIRECT_UDP_FLOOD ? 'true' : 'false');
    }

    // ---

    $USE_X100_COMMUNITY_TARGETS = val(Config::$data, 'useX100CommunityTargets');
    $USE_X100_COMMUNITY_TARGETS = boolval(Config::filterOptionValueBoolean($USE_X100_COMMUNITY_TARGETS));
    if ($USE_X100_COMMUNITY_TARGETS != Config::$dataDefault['useX100CommunityTargets']) {
        $addToLog[] = "Use X100 community targets: " . ($USE_X100_COMMUNITY_TARGETS ? 'true' : 'false');
    }

    /*-------

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

    */

    //-------

    $SHOW_CONSOLE_OUTPUT = val(Config::$data, 'showConsoleOutput');
    $SHOW_CONSOLE_OUTPUT = boolval(Config::filterOptionValueBoolean($SHOW_CONSOLE_OUTPUT));
    if ($SHOW_CONSOLE_OUTPUT != Config::$dataDefault['showConsoleOutput']) {
        $addToLog[] = "Show console output: " . ($SHOW_CONSOLE_OUTPUT ? 'true' : 'false');
    }

    //-------

    $ENCRYPT_LOGS = val(Config::$data, 'encryptLogs');
    $ENCRYPT_LOGS = boolval(Config::filterOptionValueBoolean($ENCRYPT_LOGS));
    if ($ENCRYPT_LOGS) {
        $ENCRYPT_LOGS_PUBLIC_KEY = val(Config::$data, 'encryptLogsPublicKey');
        $ENCRYPT_LOGS_PUBLIC_KEY = @openssl_pkey_get_public(urldecode($ENCRYPT_LOGS_PUBLIC_KEY));
        if ($ENCRYPT_LOGS_PUBLIC_KEY) {
            $addToLog[] = 'Encrypted logs: true';
        } else {
            $addToLog[] = 'Encrypted logs: invalid public key';
        }
    }

    //-------

    $TELEGRAM_NOTIFICATIONS_ENABLED = val(Config::$data, 'telegramNotificationsEnabled');
    $TELEGRAM_NOTIFICATIONS_ENABLED = boolval(Config::filterOptionValueBoolean($TELEGRAM_NOTIFICATIONS_ENABLED));
    if ($TELEGRAM_NOTIFICATIONS_ENABLED != Config::$dataDefault['telegramNotificationsEnabled']) {
        $addToLog[] = "Send Telegram bot notifications: " . ($TELEGRAM_NOTIFICATIONS_ENABLED ? 'true' : 'false');
    }

    //-------

    $TELEGRAM_NOTIFICATIONS_TO_USER_ID = val(Config::$data, 'telegramNotificationsToUserId');
    $TELEGRAM_NOTIFICATIONS_TO_USER_ID = Config::filterOptionValueInt($TELEGRAM_NOTIFICATIONS_TO_USER_ID, 1, PHP_INT_MAX);
    if (!$TELEGRAM_NOTIFICATIONS_TO_USER_ID  &&  $IT_ARMY_USER_ID) {
        $TELEGRAM_NOTIFICATIONS_TO_USER_ID = $IT_ARMY_USER_ID;
    }

    if ($TELEGRAM_NOTIFICATIONS_TO_USER_ID) {
        $addToLog[] = "Send Telegram notifications to user with ID: $TELEGRAM_NOTIFICATIONS_TO_USER_ID";
    }

    //-------

    $TELEGRAM_NOTIFICATIONS_AT_HOURS = [];
    $atHours = explode(',', trim(val(Config::$data, 'telegramNotificationsAtHours')));
    foreach ($atHours as $hour) {
        $TELEGRAM_NOTIFICATIONS_AT_HOURS[] = intval($hour);
    }
    $TELEGRAM_NOTIFICATIONS_AT_HOURS = array_unique($TELEGRAM_NOTIFICATIONS_AT_HOURS);

    if (implode(',', $TELEGRAM_NOTIFICATIONS_AT_HOURS) !== Config::$dataDefault['telegramNotificationsAtHours']) {
        $addToLog[] = "Telegram notifications at hours: " . implode(',', $TELEGRAM_NOTIFICATIONS_AT_HOURS);
    }

    //------

    $TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES = val(Config::$data, 'telegramNotificationsPlainMessages');
    $TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES = boolval(Config::filterOptionValueBoolean($TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES));
    if ($TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES != Config::$dataDefault['telegramNotificationsPlainMessages']) {
        $addToLog[] = "Send plain Telegram notifications: " . ($TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES ? 'true' : 'false');
    }

    //------

    $TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES = val(Config::$data, 'telegramNotificationsAttachmentMessages');
    $TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES = boolval(Config::filterOptionValueBoolean($TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES));
    if ($TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES != Config::$dataDefault['telegramNotificationsAttachmentMessages']) {
        $addToLog[] = "Send plain Telegram notifications: " . ($TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES ? 'true' : 'false');
    }

    //------

    $X100_INSTANCE_TITLE = trim(val(Config::$data, 'X100InstanceTitle'));
    if ($X100_INSTANCE_TITLE !== Config::$dataDefault['X100InstanceTitle']) {
        $addToLog[] = "X100 instance title: " . $X100_INSTANCE_TITLE;
    }

    //------

    if (count($addToLog)) {
        MainLog::log("User defined settings:\n    " . implode("\n    ", $addToLog), 2);
    }

    //---

    $x100RunBashContent = file_get_contents(__DIR__ . '/x100-run.bash');
    preg_match('#expirationDate="([^\n]+)"#', $x100RunBashContent, $matches);
    if (isset($matches[1])) {
        $dt = DateTime::createFromFormat('Ymd', $matches[1]);
        $dt->setTime(0, 0);
        $SOURCE_GUARDIAN_EXPIRATION_DATE = $dt->getTimestamp();
    } else {
        _die("Can't determine Source Guardian trial expiration date");
    }

    //---

    if ($FIXED_VPN_QUANTITY) {
        $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL = $FIXED_VPN_QUANTITY;
    } else {
        $connectionsLimitByCpu = intRound($CPU_CORES_QUANTITY * (intval($CPU_USAGE_GOAL) / 100) * $VPN_CONNECTIONS_QUANTITY_PER_CPU);
        MainLog::log("Allowed to use $CPU_USAGE_GOAL of $CPU_CORES_QUANTITY installed CPU core(s). This grants $connectionsLimitByCpu parallel VPN connections");

        $maxRamUsage = roundLarge(intval($RAM_USAGE_GOAL) / 100 * $OS_RAM_CAPACITY);
        $connectionsLimitByRam = round(($maxRamUsage - ($IS_IN_DOCKER  ?  0.25 : 0.75)) * $VPN_CONNECTIONS_QUANTITY_PER_1GIB_RAM);
        $connectionsLimitByRam = $connectionsLimitByRam < 1  ?  0 : $connectionsLimitByRam;
        MainLog::log("Allowed to use $maxRamUsage of $OS_RAM_CAPACITY GiB installed RAM. This grants $connectionsLimitByRam parallel VPN connections");

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
           $CURRENT_SESSION_DURATION_LIMIT,
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
    MainLog::log("Starting $SESSIONS_COUNT session at " . date('Y-m-d H:i:s'));
    $VPN_SESSION_STARTED_AT = time();
    $MAIN_OUTPUT_LOOP_ITERATIONS_COUNT = 0;

    Actions::doAction('BeforeInitSession');

    //-----------------------------------------------------------
    $PARALLEL_VPN_CONNECTIONS_QUANTITY = $PARALLEL_VPN_CONNECTIONS_QUANTITY_INITIAL;
    $MAX_FAILED_VPN_CONNECTIONS_QUANTITY = fitBetweenMinMax(10, false, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 4));
    $CONNECT_PORTION_SIZE = fitBetweenMinMax(20, false, round($PARALLEL_VPN_CONNECTIONS_QUANTITY / 2));

    if ($SESSIONS_COUNT === 1) {
        Actions::doFilter('InitSessionResourcesCorrection', []);
    } else {
        NetworkConsumption::calculateNetworkBandwidthLimit();
        $usageValues = ResourcesConsumption::$pastSessionUsageValues;

        MainLog::log('System      average  CPU   usage during previous session was ' . padPercent($usageValues['systemAverageCpuUsage']['current']) . " of {$CPU_CORES_QUANTITY} core(s) installed", 1, 1);
        MainLog::log('System      peak     CPU   usage during previous session was ' . padPercent($usageValues['systemPeakCpuUsage']['current']));
        MainLog::log('System      average  RAM   usage during previous session was ' . padPercent($usageValues['systemAverageRamUsage']['current']) . " of {$OS_RAM_CAPACITY}GiB installed");
        MainLog::log('System      peak     RAM   usage during previous session was ' . padPercent($usageValues['systemPeakRamUsage']['current']));
        MainLog::log('System      average  SWAP  usage during previous session was ' . padPercent($usageValues['systemAverageSwapUsage']['current']) . " of " . humanBytes(LinuxResources::getSystemSwapCapacity()) . " available");
        MainLog::log('System      peak     SWAP  usage during previous session was ' . padPercent($usageValues['systemPeakSwapUsage']['current']));
        MainLog::log('System      average  TMP   usage during previous session was ' . padPercent($usageValues['systemAverageTmpUsage']['current']) . " of " . humanBytes(LinuxResources::getSystemTmpCapacity()) . " available");
        MainLog::log('System      peak     TMP   usage during previous session was ' . padPercent($usageValues['systemPeakTmpUsage']['current']));

        if (isset($usageValues['systemAverageNetworkUsageReceive'])) {
            $netUsageMessageTitle = 'System   average  Network  usage during previous session was: ';
            $padBeforeLength = strlen($netUsageMessageTitle) - 9;
            $netUsageMessage = $netUsageMessageTitle
                . pad6(humanBytes(NetworkConsumption::$trackingPeriodTransmitSpeed + NetworkConsumption::$trackingPeriodReceiveSpeed, HUMAN_BYTES_BITS)) . "\n"
                . str_repeat(' ', $padBeforeLength)
                . 'upload   ' . pad6(humanBytes(NetworkConsumption::$trackingPeriodTransmitSpeed, HUMAN_BYTES_BITS)) . ' of ' . pad6(humanBytes(NetworkConsumption::$transmitSpeedLimitBits, HUMAN_BYTES_BITS)) . " allowed (" . $usageValues['systemAverageNetworkUsageTransmit']['current'] . "%),\n"
                . str_repeat(' ', $padBeforeLength)
                . 'download ' . pad6(humanBytes(NetworkConsumption::$trackingPeriodReceiveSpeed, HUMAN_BYTES_BITS)) . ' of ' . pad6(humanBytes(NetworkConsumption::$receiveSpeedLimitBits, HUMAN_BYTES_BITS)) . ' allowed (' . $usageValues['systemAverageNetworkUsageReceive']['current'] . '%)';
            MainLog::log($netUsageMessage);
        }

        MainLog::log('X100        average  CPU   usage during previous session was ' . padPercent($usageValues['x100ProcessesAverageCpuUsage']['current']), 1, 1);
        MainLog::log('X100        average  RAM   usage during previous session was ' . padPercent($usageValues['x100ProcessesAverageMemUsage']['current']));
        MainLog::log('X100        peak     RAM   usage during previous session was ' . padPercent($usageValues['x100ProcessesPeakMemUsage']['current']), 2);

        MainLog::log('MainCliPhp  average  CPU   usage during previous session was ' . padPercent($usageValues['x100MainCliPhpCpuUsage']['current']));
        MainLog::log('MainCliPhp  average  RAM   usage during previous session was ' . padPercent($usageValues['x100MainCliPhpMemUsage']['current']));

        $usageValues = Actions::doFilter('InitSessionResourcesCorrection', $usageValues);
    }

    //-----------------------------------------------------------

    Actions::doAction('AfterInitSession');

    if ($SESSIONS_COUNT === 1) {
        NetworkConsumption::calculateNetworkBandwidthLimit();
    }

    $CURRENT_SESSION_DURATION_LIMIT = rand($ONE_SESSION_MIN_DURATION, $ONE_SESSION_MAX_DURATION);
    $STATISTICS_BLOCK_INTERVAL = intRound($CURRENT_SESSION_DURATION_LIMIT / 2);
    $DELAY_AFTER_SESSION_DURATION = rand($DELAY_AFTER_SESSION_MIN_DURATION, $DELAY_AFTER_SESSION_MAX_DURATION);
    MainLog::log('This session will last ' . humanDuration($CURRENT_SESSION_DURATION_LIMIT) . ', and after will be ' . humanDuration($DELAY_AFTER_SESSION_DURATION) . ' idle delay', 2, 1);

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

function pad($val, $size) : string
{
    return str_pad($val, $size, ' ', STR_PAD_LEFT);
}

function padPercent($val) {
    return pad($val, 3) . '%';
}

function pad6($val) {
    return pad($val, 6);
}

//xfce4-terminal  --maximize  --execute    /bin/bash -c "super x100-run.bash ;   read -p \"Program was terminated\""