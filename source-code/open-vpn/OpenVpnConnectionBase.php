<?php

abstract class OpenVpnConnectionBase
{
    private $networkStats = null;

    private int $connectedAt,
                $sessionStartedAt,
                $ITArmyAppsVerification = 0;

    private function collectNetworkStatsAfterConnect()
    {
        $this->connectedAt = $this->sessionStartedAt = time();
        $interfaceStats = static::getNetworkInterfaceStats($this->netInterface, $this->netnsName);
        $this->networkStats = (object) [
            'atConnect'    => $interfaceStats,
            'sessionStart' => $interfaceStats,
            'last'         => $interfaceStats
        ];
    }

    private function collectNetworkStatsAfterInitSession()
    {
        $this->sessionStartedAt = time();
        $interfaceStats = static::getNetworkInterfaceStats($this->netInterface, $this->netnsName);
        if ($interfaceStats  &&  is_object($this->networkStats)) {
            $this->networkStats->sessionStart = $interfaceStats;
            $this->networkStats->last         = $interfaceStats;
        } else {
            $this->networkStats->sessionStart = $this->networkStats->last;
        }
    }

    private function collectNetworkStatsLast()
    {
        $interfaceStats = static::getNetworkInterfaceStats($this->netInterface, $this->netnsName);
        if ($interfaceStats  &&  is_object($this->networkStats)) {
            $this->networkStats->last = $interfaceStats;
        }
    }

    private function verifyITArmyApps(&$passedNetns)
    {
        global $HOME_DIR;

        // -----------------------------------------------

        if ($this->ITArmyAppsVerification < 0) {
            // Verification failed during one of the previous checks
            return;
        }

        // -----------------------------------------------

        $hackApplication = $this->applicationObject;
        if (
                !is_object($hackApplication)
            ||  !$hackApplication->wasLaunched()
        ) {
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['appNotLaunched'];
            return;
        } else if ($hackApplication->isTerminated()) {
            // HackApplication was terminated. Using previous check result
            return;
        }
        $hackApplicationClass = get_class($hackApplication);

        // Get list of PIDs running in network container
        $netnsPidsStdout = trim(shell_exec("ip netns pids {$this->netnsName}   2>&1"));
        if (!$netnsPidsStdout) {
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['netnsPIDsParseFailed'];
            return;
        }

        if (preg_match('#[^\d\s]#u', $netnsPidsStdout)) {
            // Text in command results means error
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['netnsPIDsParseFailed'];
            return;
        }

        $netnsPidsArray = explode("\n", $netnsPidsStdout);
        if (!$netnsPidsArray  ||  !count($netnsPidsArray)) {
            // No PIDs parsed
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['netnsPIDsParseFailed'];
            return;
        }

        // -----------------------------------------------

        if (strpos($this->netInterface, 'tun') !== 0) {
            // Statistics is calculated not from tun network interface
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['notTunDevice'];
            return;
        }

        // ---

        // Valid X100 network container should have only following network interfaces UP: "lo", "tun##" and "ifb0" (optional)
        // Another network interfaces configuration is definitely an intention to cheat ITAStats

        $interfacesNames = static::getNetworkInterfacesNames($this->netnsName);
        $interfacesByType = [];
        foreach ($interfacesNames as $interfaceName) {

            $interfaceState = static::getNetworkInterfaceState($interfaceName, $this->netnsName);
            //echo "$interfaceName $interfaceState\n";
            if ($interfaceState === 'DOWN') {
                continue;  // Ignore down interfaces
            }

            if (preg_match('#^(\D+)#u', $interfaceName, $matches) === 1) {
                $interfaceType = $matches[1];
                $count = $interfacesByType[$interfaceType]  ??  0;
                $interfacesByType[$interfaceType] = $count + 1;
            } else {
                $count = $interfacesByType['unknown']  ??  0;
                $interfacesByType['unknown'] = $count + 1;
            }
        }

        if (!(
                val($interfacesByType, 'ifb')  <= 1
            &&  val($interfacesByType, 'lo')  === 1
            &&  val($interfacesByType, 'tun') === 1
            &&  count($interfacesByType) - val($interfacesByType, 'ifb') === 2
        )) {
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['wrongNetDevices'];
            return;
        }

        // -----------------------------------------------

        if (in_array($this->netnsName, $passedNetns)) {
            // The hacker trys to manipulate X100 classes to send ITArmy stats many times for the same network namespace
            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['netnsPassedTwice'];
            return;
        } else {
            $passedNetns[] = $this->netnsName;
        }

        // -----------------------------------------------

        foreach ($netnsPidsArray as $netnsPid) {

            $command = shell_exec("ps   --pid=$netnsPid  --no-headers  --format=cmd   2>&1");
            preg_match('#^(\S+)#u', $command, $matches);
            $executable = $matches[1] ?? '';
            //echo "$command\n$executable\n\n";

            if ($hackApplicationClass === 'db1000nApplication') {

                if ($executable === $HOME_DIR . static::$db1000nLocation) {

                    if (!static::$db1000nReal) {
                        // The running db1000n is fake
                        $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['fakeApp'];
                        return;
                    }

                    // ---

                    if (
                        /*    strpos($command, '--user-id=0') === false
                           || substr_count($command, 'user-id') !== 1
                        */

                        strpos($command, 'user-id') !== false
                    ) {
                        // The user is trying to send stats twice, by db1000n and by X100
                        $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['userIdArgument'];
                        return;
                    }

                    // ---

                    $caTargetsFilePath = static::simpleGetCommandLineArgument($command, 'c');
                    if ($caTargetsFilePath) {
                        // The user has provided targets config for db1000n.
                        // Let's check is it the official ITArmy targets config

                        if (!file_exists($caTargetsFilePath)) {
                            // Targets file was provided, but is not on disk
                            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['targetsFileNotOnDisk'];
                            return;
                        } else {
                            $targetsConfigContent = file_get_contents($caTargetsFilePath);
                            $targetsConfigObject  = json_decode($targetsConfigContent);
                            if (!is_object($targetsConfigObject)) {
                                // Can't parse targets config json
                                $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['targetsFileParseFailed'];
                                return;
                            } else {
                                // ITArmy official config always contains "type": "encrypted" block. Let's find it
                                $encryptedBlockFound = false;
                                $jobs = $targetsConfigObject->jobs ?? [];
                                foreach ($jobs as $job) {
                                    if ($job->type === 'encrypted') {
                                        $encryptedBlockFound = true;
                                        break;
                                    }
                                }

                                if (!$encryptedBlockFound) {
                                    // Provided config is not the ITArmy config
                                    $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['targetsFileNotOfficial'];
                                    return;
                                }
                            }
                        }

                    }

                    // ---

                    $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['verified'];

                } else if ($executable === '/sbin/runuser') {
                    continue;
                } else {
                   // This $executable is foreign for db1000nApplication
                   $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['foreignApp'];
                   return;
                }

            // -----------------------------------------------

            } else if ($hackApplicationClass === 'DistressApplication') {

                if ($executable === $HOME_DIR . static::$distressLocation) {

                    if (!static::$distressReal) {
                        // The running Distress is fake
                        $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['fakeApp'];
                        return;
                    }

                    // ---

                    if (
                                                                         /*   strpos($command, '--user-id=0') === false
                                                                           || substr_count($command, 'user-id') !== 1    */
                        strpos($command, 'user-id') !== false
                    ) {
                        // The user is trying to send stats twice, by Distress and by X100
                        $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['userIdArgument'];
                        return;
                    }

                    $caTargetsFilePath = static::simpleGetCommandLineArgument($command, 'targets-path');
                    if (!$caTargetsFilePath) {
                        $caTargetsFilePath = static::simpleGetCommandLineArgument($command, 't');
                    }

                    if (
                        $caTargetsFilePath
                        &&  file_exists($caTargetsFilePath)
                    ) {
                        $lastGetState = DistressGetTargetsFile::lastGetStateOfDistressTargetsFile();

                        if (
                              !$lastGetState->success
                            || $caTargetsFilePath !== DistressApplicationStatic::$localTargetsFilePath
                            || $caTargetsFilePath !== $lastGetState->path
                            || $lastGetState->md5Current !== static::$distressTargetsFileHash
                        ) {
                            $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['targetsFileNotOfficial'];
                            return;
                        }
                    }

                    // ---

                    $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['verified'];

                } else if ($executable === '/sbin/runuser') {
                    continue;
                } else {
                    // This $executable is foreign for DistressApplication
                    $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['foreignApp'];
                    return;
                }

            // -----------------------------------------------

            } else {
                // The ITArmy stats is not supported for this attacker
                $this->ITArmyAppsVerification = static::$ITArmyAppsVerificationStates['unsupportedAttacker'];
                return;
            }

        }
    }

    public function calculateNetworkStats() : stdClass
    {
        $this->collectNetworkStatsLast();

        $ret = new \stdClass();
        $ret->session = new \stdClass();
        $ret->session->startedAt     = $this->sessionStartedAt;
        $ret->session->received      = val($this->networkStats, 'last', 'received')    - val($this->networkStats, 'sessionStart', 'received');
        $ret->session->transmitted   = val($this->networkStats, 'last', 'transmitted') - val($this->networkStats, 'sessionStart', 'transmitted');
        $ret->session->sumTraffic    = $ret->session->received + $ret->session->transmitted;
        $ret->session->receiveSpeed  = 0;
        $ret->session->transmitSpeed = 0;
        $ret->session->sumSpeed      = 0;
        $ret->session->duration      = time() - $ret->session->startedAt;

        if ($ret->session->duration) {
            $ret->session->receiveSpeed  = intRound($ret->session->received    /  $ret->session->duration * 8);
            $ret->session->transmitSpeed = intRound($ret->session->transmitted /  $ret->session->duration * 8);
            $ret->session->sumSpeed      = $ret->session->receiveSpeed + $ret->session->transmitSpeed;
        }

        $ret->total = new \stdClass();
        $ret->total->connectedAt   = $this->connectedAt;
        $ret->total->received      = val($this->networkStats, 'last', 'received')    - val($this->networkStats, 'atConnect', 'received');
        $ret->total->transmitted   = val($this->networkStats, 'last', 'transmitted') - val($this->networkStats, 'atConnect', 'transmitted');
        $ret->total->sumTraffic    = $ret->total->received + $ret->total->transmitted;
        $ret->total->receiveSpeed  = 0;
        $ret->total->transmitSpeed = 0;
        $ret->total->sumSpeed      = 0;
        $ret->total->duration      = time() - $ret->total->connectedAt;

        if ($ret->total->duration) {
            $ret->total->receiveSpeed  = intRound($ret->total->received    /  $ret->total->duration * 8);
            $ret->total->transmitSpeed = intRound($ret->total->transmitted /  $ret->total->duration * 8);
            $ret->total->sumSpeed      = $ret->total->receiveSpeed + $ret->total->transmitSpeed;
        }

        return $ret;
    }

    // ----------------------  Static part of the class ----------------------

    private static array  $ITArmyAppsVerificationStates = [
        'notVerified'             =>  0,
        'verified'                =>  1,
        'targetsFileNotOnDisk'    => -1,
        'targetsFileParseFailed'  => -2,
        'targetsFileNotOfficial'  => -3,
        'targetsFileNotAllowed'   => -4,
        'unsupportedAttacker'     => -5,
        'fakeApp'                 => -6,
        'netnsPIDsParseFailed'    => -7,
        'foreignApp'              => -8,
        'userIdArgument'          => -9,
        'netnsPassedTwice'        => -10,
        'notTunDevice'            => -11,
        'wrongNetDevices'         => -12,
        'appNotLaunched'          => -13,
    ];

    private static string $db1000nLocation  = '/1000/app',
                          $distressLocation = '/DST/app',
                          $sessionBadge,
                          $totalBadge,
                          $ITAStatInstanceId,
                          $ITAStatSendQueueErrors,
                          $distressTargetsFileHash;

    private static int    $ITAStatFirstConnectionStartedAt,
                          $ITAStatSessionDuration,
                          $ITAStatSessionCount,
                          $ITAStatLastSessionCollected,
                          $ITAStatPacketId,
                          $ITAStatLastCollectSessionStatisticsAt,
                          $ITAStatSessionTrafficDb1000n,
                          $ITAStatSessionTrafficDistress,
                          $ITAStatTotalTrafficDb1000n,
                          $ITAStatTotalTrafficDistress;

    private static        $actionsBound = false,
                          $db1000nReal,
                          $distressReal;

    private static array  $ITAStatSendQueue;

    private static object $ITAStatEmptyPacket;

    public static function constructStatic()
    {
        Actions::addFilter('OpenVpnSuccessfullyConnected',     [static::class, 'filterOpenVpnSuccessfullyConnected']);
        Actions::addFilter('OpenVpnBeforeTerminateWithError',  [static::class, 'filterOpenVpnBeforeTerminate']);
        Actions::addFilter('OpenVpnBeforeTerminate',           [static::class, 'filterOpenVpnBeforeTerminate']);

        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession']);
        Actions::addAction('BeforeMainOutputLoop',           [static::class, 'actionBeforeMainOutputLoop']);
        Actions::addAction('MainOutputLongBrake',            [static::class, 'actionMainOutputLongBrake'], 0);

        Actions::addAction('AfterCalculateResources',        [static::class, 'actionAfterCalculateResources']);
    }

    public static function filterOpenVpnSuccessfullyConnected($vpnConnection)
    {
        $vpnConnection->collectNetworkStatsAfterConnect();
    }

    public static function filterOpenVpnBeforeTerminate($vpnConnection)
    {
        $vpnConnection->collectNetworkStatsLast();
    }

    public static function actionAfterInitSession()
    {
        foreach (OpenVpnConnectionStatic::getInstances() as $vpnConnection) {
            $vpnConnection->collectNetworkStatsAfterInitSession();
        }
    }

    public static function actionBeforeMainOutputLoop()
    {
        foreach (OpenVpnConnectionStatic::getInstances() as $vpnConnection) {
            $vpnConnection->collectNetworkStatsLast();
        }
    }

    public static function actionMainOutputLongBrake()
    {
        foreach (OpenVpnConnectionStatic::getInstances() as $vpnConnection) {
            $vpnConnection->collectNetworkStatsLast();
        }
    }

    // ------------------------ ITArmy stats -----------------------

    //+
    public static function actionAfterCalculateResources()
    {
        if (static::$actionsBound) {
            // Prevent actionAfterCalculateResources() multiple calls abuse
            return;
        } else {
            static::$actionsBound = true;
        }

        if (
               !method_exists(Actions::class, 'newSecretMethod')
            || !method_exists(DistressGetTargetsFile::class, 'httpGet')
        ) {
            return;
        }

        Actions::addAction('AfterInitSession',               [static::class, 'actionITAStatSessionInit']);

        Actions::addAction('BeforeMainOutputLoopIteration',  [static::class, 'actionITAStatConnectionAppsVerify'], 0);
        Actions::addAction('MainOutputLongBrake',            [static::class, 'actionITAStatConnectionAppsVerify'], 0);
        Actions::addAction('AfterMainOutputLoopIteration',   [static::class, 'actionITAStatConnectionAppsVerify'], 0);
        Actions::addAction('BeforeTerminateSession',         [static::class, 'actionITAStatConnectionAppsVerify'], 0);
        Actions::addAction('BeforeTerminateFinalSession',    [static::class, 'actionITAStatConnectionAppsVerify'],0);

        Actions::addAction('TerminateSession',               [static::class, 'actionITAStatCollectSessionStatistics']);
        Actions::addAction('TerminateFinalSession',          [static::class, 'actionITAStatCollectSessionStatistics']);

        Actions::addFilter('OpenVpnStatisticsSessionBadge',   [static::class, 'filterITAStatSessionBadge'], 11);
        Actions::addFilter('OpenVpnStatisticsBadge',          [static::class, 'filterITAStatBadge'], 11);

        // ---

        static::$ITAStatLastCollectSessionStatisticsAt = 0;
        static::$ITAStatInstanceId = bin2hex(random_bytes(8));
        static::$ITAStatSessionCount = 0;
        static::$ITAStatLastSessionCollected = 0;
        static::$ITAStatPacketId = 0;
        static::$ITAStatTotalTrafficDb1000n = static::$ITAStatTotalTrafficDistress = 0;
        static::$ITAStatSendQueue = [];

        // ---

        global $IT_ARMY_USER_ID, $DOCKER_HOST;

        $itArmyUserId = $IT_ARMY_USER_ID ?: 777222111;

        switch (strtolower($DOCKER_HOST)) {
            case 'darwin':
                $os = 'darwin';
                break;

            case 'windows':
                $os = 'windows';
                break;

            default:
                $os = 'linux';
        }

        static::$ITAStatEmptyPacket = new \stdClass();
        static::$ITAStatEmptyPacket->user_id   = (string) $itArmyUserId;
        static::$ITAStatEmptyPacket->packet_id = '';
        static::$ITAStatEmptyPacket->os        = $os;
        static::$ITAStatEmptyPacket->attacker  = '';
        static::$ITAStatEmptyPacket->traffic   = 0;
        static::$ITAStatEmptyPacket->duration  = 0;
        static::$ITAStatEmptyPacket->version   = SelfUpdate::getSelfVersion();
    }

    //+
    public static function actionITAStatSessionInit()
    {
        static::$db1000nReal = static::$distressReal = null;  // Not checked in this session
        static::$totalBadge = static::$sessionBadge = '';
        static::$ITAStatSessionTrafficDb1000n = static::$ITAStatSessionTrafficDistress = 0;
        static::$ITAStatFirstConnectionStartedAt = PHP_INT_MAX;
        static::$ITAStatSessionCount++;
        static::$ITAStatSessionDuration = 0;
        static::$ITAStatSendQueueErrors = '';
    }

    //+
    public static function actionITAStatConnectionAppsVerify()
    {
        global $HOME_DIR;

        // Let's check whether our db1000n is not a fake.
        // Currently, I have implemented simple way to do it:
        // Run db1000n with --help argument, and see if the output contains typical text.
        // In the future, this can be replaced with precise hash check

        $db1000nHelp = shell_exec($HOME_DIR . static::$db1000nLocation . '  --help  2>&1');
        static::$db1000nReal = strpos($db1000nHelp, 'raw backup config in case the primary one is unavailable') !== false;

        // Same for Distress
        $distressHelp = shell_exec($HOME_DIR . static::$distressLocation . '  --help  2>&1');
        static::$distressReal = strpos($distressHelp, 'hint to use your ip in % of requests from 0 to 100 inclusive works amazing with VPN') !== false;

        static::$distressTargetsFileHash = '';
        if (file_exists(DistressApplicationStatic::$localTargetsFilePath)) {
            static::$distressTargetsFileHash = md5_file(DistressApplicationStatic::$localTargetsFilePath);
        }

        // -----------------------------------------------

        $passedNetns = [];
        foreach (OpenVpnConnectionStatic::getInstances() as $connectionIndex => $vpnConnection) {
            $vpnConnection->verifyITArmyApps($passedNetns);
            //MainLog::log($connectionIndex . '  ' . $vpnConnection->ITArmyAppsVerification);
        }
    }

    //+
    public static function actionITAStatCollectSessionStatistics()
    {
        global $SESSIONS_COUNT;

        if (
                !static::$ITAStatInstanceId                                               // ITAStatInit() wan not called
            ||  time() - static::$ITAStatLastCollectSessionStatisticsAt < 120             // Prevent ITAStatCollectSessionStatistics() often calls manipulation
            ||  $SESSIONS_COUNT !== static::$ITAStatSessionCount                          // Prevent ITAStatCollectSessionStatistics() calls manipulation
            ||  static::$ITAStatLastSessionCollected === static::$ITAStatSessionCount     // Prevent ITAStatCollectSessionStatistics() multiple calls manipulation
            ||  static::$sessionBadge
        ) {
            return;
        }

        static::$ITAStatLastCollectSessionStatisticsAt = time();
        static::$ITAStatLastSessionCollected = static::$ITAStatSessionCount;

        // ---

        $vpnConnections = OpenVpnConnectionStatic::getInstances();
        foreach ($vpnConnections as $vpnConnection) {

            if ($vpnConnection->ITArmyAppsVerification === static::$ITArmyAppsVerificationStates['verified']) {

                $hackApplication = $vpnConnection->applicationObject;
                if (
                        !is_object($hackApplication)
                    ||  !$hackApplication->wasLaunched()
                ) {
                    continue;
                }
                $hackApplicationClass = get_class($hackApplication);

                // ---

                $networkStats = $vpnConnection->calculateNetworkStats();
                static::$ITAStatFirstConnectionStartedAt = min(static::$ITAStatFirstConnectionStartedAt, $networkStats->session->startedAt);

                if ($hackApplicationClass === 'db1000nApplication') {
                    static::$ITAStatSessionTrafficDb1000n += $networkStats->session->sumTraffic;
                    static::$ITAStatTotalTrafficDb1000n   += $networkStats->session->sumTraffic;
                } else if ($hackApplicationClass === 'DistressApplication') {
                    static::$ITAStatSessionTrafficDistress += $networkStats->session->sumTraffic;
                    static::$ITAStatTotalTrafficDistress   += $networkStats->session->sumTraffic;
                }
            }

        }

        static::$ITAStatSessionDuration = time() - static::$ITAStatFirstConnectionStartedAt;

        // ---

        if (static::$ITAStatSessionTrafficDb1000n) {
            static::putToITAStatQueue('db1000n', static::$ITAStatSessionTrafficDb1000n, static::$ITAStatSessionDuration);
        }

        if (static::$ITAStatSessionTrafficDistress) {
            static::putToITAStatQueue('distress', static::$ITAStatSessionTrafficDistress, static::$ITAStatSessionDuration);
        }

        // ---

        static::ITAStatSendQueue();
        static::ITAStatGenerateLogBadges();
    }

    private static function putToITAStatQueue($attacker, $currentTraffic, $currentDuration)
    {
        if (!isset(static::$ITAStatSendQueue[$attacker])) {
            static::$ITAStatSendQueue[$attacker] = clone static::$ITAStatEmptyPacket;
        }
        $attackerPacketObject = static::$ITAStatSendQueue[$attacker];
        $attackerPacketObject->packet_id = static::$ITAStatInstanceId . '-' . static::$ITAStatPacketId++;
        $attackerPacketObject->attacker  = $attacker;

        $previousTraffic = $attackerPacketObject->traffic * $attackerPacketObject->duration;
        $allTraffic = $previousTraffic + $currentTraffic;
        $allDuration = $attackerPacketObject->duration + $currentDuration;

        $attackerPacketObject->traffic  = (int) round($allTraffic / $allDuration);
        $attackerPacketObject->duration = $allDuration;
    }

    private static function ITAStatSendQueue()
    {
        foreach (static::$ITAStatSendQueue as $attacker => $statPacket) {

            $statPacketJson = json_encode($statPacket, JSON_PRETTY_PRINT);
            static::httpPostJson('https://api.all-service.in.ua/api/x100/set-statistics', $statPacketJson, $httpCode, $body);

            //MainLog::log(print_r([$statPacketJson, $httpCode, $body, 'ITAStata'], true));

            $responseBodyJson = @json_decode($body);
            $responseSuccess = val($responseBodyJson, 'success');
            $responseMessage = val($responseBodyJson, 'message');

            if ($responseSuccess) {
                unset(static::$ITAStatSendQueue[$attacker]);
            } else {
                static::$ITAStatSendQueueErrors .= count(static::$ITAStatSendQueue) . " statistics packet(s) not sent. ";

                if ($responseMessage) {
                    static::$ITAStatSendQueueErrors .= $responseMessage . "\n";
                } else {
                    static::$ITAStatSendQueueErrors .= "Network error\n";
                }

                break;
            }
        }
    }

    private static function ITAStatGenerateLogBadges()
    {
        $vpnConnections = OpenVpnConnectionStatic::getInstances();
        foreach ($vpnConnections as $connectionIndex => $vpnConnection) {

            if (
                $vpnConnection->ITArmyAppsVerification <= -1
                && $vpnConnection->ITArmyAppsVerification >= -5
            ) {
                $verificationCodeAsString = 'Not collected. Only official ITArmy targets may be accounted';
            } else if ($vpnConnection->ITArmyAppsVerification === static::$ITArmyAppsVerificationStates['netnsPIDsParseFailed']) {
                $verificationCodeAsString = 'Error ' . static::$ITArmyAppsVerificationStates['netnsPIDsParseFailed'] . ', maybe the HackApplication has died or VPN connection was lost';
            } else if ($vpnConnection->ITArmyAppsVerification < static::$ITArmyAppsVerificationStates['verified']) {
                $verificationCodeAsString = 'Error ' . $vpnConnection->ITArmyAppsVerification;
            } else {
                $verificationCodeAsString = '';
            }

            if ($verificationCodeAsString) {
                static::$sessionBadge .= "    VPN$connectionIndex: $verificationCodeAsString\n";
            }
        }

        if (static::$ITAStatSessionTrafficDb1000n) {
            $tmp = static::$ITAStatSessionTrafficDb1000n;
            static::$sessionBadge .= '    Db1000n traffic: ' . humanBytes($tmp) . "\n";
        }

        if (static::$ITAStatSessionTrafficDistress) {
            $tmp = static::$ITAStatSessionTrafficDistress;
            static::$sessionBadge .= '    Distress traffic: ' . humanBytes($tmp) . "\n";
        }

        if (static::$sessionBadge) {
            static::$sessionBadge = "ITArmy session statistics:\n" . static::$sessionBadge;

            if (static::$ITAStatSendQueueErrors) {
                static::$sessionBadge .= "Can't send statistics to the ITArmy server:\n" . static::$ITAStatSendQueueErrors;
            }
        }

        // ---

        if (static::$ITAStatTotalTrafficDb1000n) {
            $tmp = static::$ITAStatTotalTrafficDb1000n;
            static::$totalBadge .= '    Db1000n traffic: ' . humanBytes($tmp) . "\n";
        }

        if (static::$ITAStatTotalTrafficDistress) {
            $tmp = static::$ITAStatTotalTrafficDistress;
            static::$totalBadge .= '    Distress traffic: ' . humanBytes($tmp) . "\n";
        }

        if (static::$totalBadge) {
            static::$totalBadge = "ITArmy total statistics:\n" . static::$totalBadge;
        }
    }

    //+
    public static function filterITAStatSessionBadge($value)
    {
        global $IT_ARMY_USER_ID;

        if ($IT_ARMY_USER_ID  &&  static::$sessionBadge) {
            $value .= "\n\n" . static::$sessionBadge;
        }

        return $value;
    }

    //+
    public static function filterITAStatBadge($value)
    {
        global $IT_ARMY_USER_ID;

        if ($IT_ARMY_USER_ID  &&  static::$totalBadge) {
            $value .= "\n\n" . static::$totalBadge;
        }

        return $value;
    }

    // ------------------- Common functions -------------------

    public static function calculateNetnsName($connectionIndex)
    {
        return 'netnsVpn' . $connectionIndex;
    }

    public static function calculateInterfaceName($connectionIndex)
    {
        return 'tun' . $connectionIndex;
    }

    public static function getInstancesNetworkTotals() : stdClass
    {
        $ret = new \stdClass();
        $ret->session = new \stdClass();
        $ret->total   = new \stdClass();

        $ret->session->received      = 0;
        $ret->session->transmitted   = 0;
        $ret->session->sumTraffic    = 0;
        $ret->session->receiveSpeed  = 0;
        $ret->session->transmitSpeed = 0;
        $ret->session->sumSpeed      = 0;

        $ret->total->received      = 0;
        $ret->total->transmitted   = 0;
        $ret->total->sumTraffic    = 0;
        $ret->total->receiveSpeed  = 0;
        $ret->total->transmitSpeed = 0;
        $ret->total->sumSpeed      = 0;

        $openVpnConnections = OpenVpnConnectionStatic::getInstances();
        foreach ($openVpnConnections as $vpnConnection) {
            if (!$vpnConnection->wasConnected) {
                continue;
            }

            $networkStats = $vpnConnection->calculateNetworkStats();

            $ret->session->received      += $networkStats->session->received;
            $ret->session->transmitted   += $networkStats->session->transmitted;
            $ret->session->sumTraffic    += $networkStats->session->sumTraffic;
            $ret->session->receiveSpeed  += $networkStats->session->receiveSpeed;
            $ret->session->transmitSpeed += $networkStats->session->transmitSpeed;
            $ret->session->sumSpeed      += $networkStats->session->sumSpeed;

            $ret->total->received      += $networkStats->total->received;
            $ret->total->transmitted   += $networkStats->total->transmitted;
            $ret->total->sumTraffic    += $networkStats->total->sumTraffic;
            $ret->total->receiveSpeed  += $networkStats->total->receiveSpeed;
            $ret->total->transmitSpeed += $networkStats->total->transmitSpeed;
            $ret->total->sumSpeed      += $networkStats->total->sumSpeed;
        }

        return $ret;
    }

    private static function getNetworkInterfaceStats(string $interfaceName, string $networkNamespaceName = '')
    {
        $command = 'ip -s link';
        if ($networkNamespaceName) {
            $command = "ip netns exec $networkNamespaceName   " . $command;
        }
        $command .= '   2>&1';

        $netStat = shell_exec($command);
        $regExp = <<<PhpRegExp
                  #\d+:([^:@]+).*?\n.*?\n.*?\n\s+(\d+).*?\n.*?\n\s+(\d+)#u
                  PhpRegExp;
        if (preg_match_all(trim($regExp), $netStat, $matches) > 0) {
            for ($i = 0 ; $i < count($matches[0]) ; $i++) {
                $interface        = trim($matches[1][$i]);
                $rx               = (int) trim($matches[2][$i]);
                $tx               = (int) trim($matches[3][$i]);
                $obj              = new stdClass();
                $obj->received    = $rx;
                $obj->transmitted = $tx;
                $interfacesArray[$interface] = $obj;
            }
        }

        if (isset($interfacesArray[$interfaceName])) {
            return $interfacesArray[$interfaceName];
        } else {
            return false;
        }
    }

    private static function getNetworkInterfaceState(string $interfaceName, string $networkNamespaceName = '')
    {
        $command = "ip link show  dev $interfaceName";
        if ($networkNamespaceName) {
            $command = "ip netns exec $networkNamespaceName   " . $command;
        }
        $command .= '   2>&1';

        $linkStateStdout = shell_exec($command);
        $regExp = <<<PhpRegExp
                  #state\s+(\w+)\s+mode#u
                  PhpRegExp;
        if (preg_match(trim($regExp), $linkStateStdout, $matches) > 0) {
            return strtoupper($matches[1]);
        } else {
            return false;
        }
    }

    private static function getNetworkInterfacesNames($networkNamespaceName = '') : array
    {
        $command = "ip link show";
        if ($networkNamespaceName) {
            $command = "ip netns exec $networkNamespaceName   " . $command;
        }
        $command .= '   2>&1';

        $netStat = shell_exec($command);
        $regExp = <<<PhpRegExp
                  #^\d+:([^:@]+)#mu
                  PhpRegExp;

        $ret = [];
        if (preg_match_all(trim($regExp), $netStat, $matches) > 0) {
            for ($i = 0 ; $i < count($matches[0]) ; $i++) {
                $ret[] = trim($matches[1][$i]);
            }
        }

        return $ret;
    }

    private static function simpleGetCommandLineArgument($commandLine, $attributeName) : string
    {
        $regExp = '# -?-' . $attributeName . '(=| +)(\S+)#';
        preg_match($regExp, $commandLine, $matches);
        $ret = $matches[2]  ??  '';
        return trim($ret, "'\"");
    }

    private static function httpPostJson(string $url, $data, &$httpCode, &$body)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));

        $body = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode === 200) {
            return true;
        } else {
            return false;
        }
    }

    private static function getDefaultNetworkInterface()
    {
        $out = _shell_exec('ip route show');
        $regExp = '#^default.* dev (\S+)#mu';
        if (preg_match($regExp, $out, $matches) !== 1) {
            return false;
        }
        return trim($matches[1]);
    }

    public static function getDefaultNetworkInterfaceStats()
    {
        $defaultInterfaceName = static::getDefaultNetworkInterface();
        return static::getNetworkInterfaceStats($defaultInterfaceName);
    }
}

OpenVpnConnectionBase::constructStatic();