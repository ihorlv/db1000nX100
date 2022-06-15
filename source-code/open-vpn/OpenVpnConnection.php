<?php

class OpenVpnConnection
{
    const VPN_CONNECT_TIMEOUT = 60;

    private $connectionStartedAt,
            $openVpnConfig,
            $envFile,
            $upEnv,
            $vpnProcess,
            $vpnIndex,
            $applicationObject,
            $vpnProcessPGid,
            $pipes,
            $log,
            $instantLog,
            $vpnClientIp,
            $vpnNetmask,
            $vpnNetwork,
            $vpnGatewayIp,
            $vpnDnsServers,
            $netnsName,
            $netInterface,
            $resolveFileDir,
            $resolveFilePath,
            $wasConnected = false,
            $connectionFailed = false,
            $connectedAt,
            $credentialsFileTrimmed,
            $connectionQualityTestData,
            $connectionQualityIcmpPing,
            $connectionQualityHttpPing,
            $connectionQualityPublicIp;


    public function __construct($vpnIndex, $openVpnConfig)
    {
        $this->connectionStartedAt = time();
        $this->vpnIndex = $vpnIndex;
        $this->netnsName = 'netc' . $this->vpnIndex;
        $this->netInterface = 'tun' . $this->vpnIndex;
        _shell_exec("ip netns delete {$this->netnsName}");
        _shell_exec("ip link  delete {$this->netInterface}");
        $this->openVpnConfig = $openVpnConfig;
        $this->openVpnConfig->logUse();

        $this->clearLog();
        $this->log('Connecting VPN' . $this->vpnIndex . ' "' . $this->getTitle() . '"');

        $vpnCommand  = 'cd "' . mbDirname($this->openVpnConfig->getOvpnFile()) . '" ;   nice -n 5   '
                     . '/usr/sbin/openvpn  --config "' . $this->openVpnConfig->getOvpnFile() . '"  --ifconfig-noexec  --route-noexec  '
                     . '--script-security 2  --route-up "' . static::$UP_SCRIPT . '"  --dev-type tun --dev ' . $this->netInterface . '  '
                     . $this->getCredentialsArgs() . '  '
                     . '2>&1';

        $this->log($vpnCommand);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $this->vpnProcess = proc_open($vpnCommand, $descriptorSpec, $this->pipes);
        $this->vpnProcessPGid = procChangePGid($this->vpnProcess, $log);
        $this->log($log);
        if ($this->vpnProcessPGid === false) {
            $this->terminate(true);
            $this->connectionFailed = true;
            return -1;
        }
        stream_set_blocking($this->pipes[1], false);
    }

    public function processConnection()
    {
        if ($this->connectionFailed) {
            return -1;
        }

        if ($this->wasConnected) {
            return true;
        }

        $stdOutLines = streamReadLines($this->pipes[1], 0.1);
        if ($stdOutLines) {
            $this->log($stdOutLines, true);
        }

        if ($this->isAlive() !== true) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($stdOutLines,'SIGTERM') !== false) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($this->log, 'Initialization Sequence Completed') !== false) {

            $this->connectedAt = time();
            $testStatus = $this->processConnectionQualityTest();
            if ($testStatus === false) {
                // Waiting for the test results
                return false;
            } else if ($testStatus === true) {

                if ($this->connectionQualityIcmpPing) {
                    $this->log("VPN tunnel ICMP Ping OK");
                } else {
                    $this->log(Term::red . "VPN tunnel ICMP Ping failed!" . Term::clear);
                }

                if ($this->connectionQualityHttpPing) {
                    $this->log("Http connection test OK");
                } else {
                    $this->log(Term::red . "Http connection test failed!" . Term::clear);
                }

                if (!$this->connectionQualityIcmpPing  &&  !$this->connectionQualityHttpPing) {
                    $this->log(Term::red . "Can't send any traffic through this VPN connection". Term::clear);
                    $this->connectionFailed = true;
                    $this->terminate(true);
                    return -1;
                }

                if ($this->connectionQualityPublicIp) {
                    $this->log("Detected VPN public IP " . $this->connectionQualityPublicIp);
                } else {
                    $this->log(Term::red . "Can't detected VPN public IP" . Term::clear);
                }

                $this->wasConnected = true;
                $this->openVpnConfig->logConnectionSuccess($this->connectionQualityPublicIp);
                return true;
            } else if ($testStatus === -1) {
                $this->log(Term::red . "Connection Quality Test failed". Term::clear);
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            // $testStatus === null
            // The test was not started yet
            //-------------------------------------------------------------------

            $this->envFile = static::getEnvFilePath($this->netInterface);
            $envJson = @file_get_contents($this->envFile);
            $this->upEnv = json_decode($envJson, true);

            $this->vpnClientIp = $this->upEnv['ifconfig_local'] ?? '';
            $this->vpnGatewayIp = $this->upEnv['route_vpn_gateway'] ?? '';
            $this->vpnNetmask = $this->upEnv['ifconfig_netmask'] ?? '255.255.255.255';
            $this->vpnNetwork = long2ip(ip2long($this->vpnGatewayIp) & ip2long($this->vpnNetmask));


            $this->vpnDnsServers = [];
            $dnsRegExp = <<<PhpRegExp
                             #dhcp-option\s+DNS\s+([\d\.]+)#  
                             PhpRegExp;
            $i = 1;
            while ($foreignOption = $this->upEnv['foreign_option_' . $i] ?? false) {
                if (preg_match(trim($dnsRegExp), $foreignOption, $matches) === 1) {
                    $this->vpnDnsServers[] = trim($matches[1]);
                }
                $i++;
            }

            $this->log("\nnetInterface " . $this->netInterface);
            $this->log('vpnClientIp ' . $this->vpnClientIp);
            $this->log('vpnGatewayIp ' . $this->vpnGatewayIp);
            $this->log('vpnNetmask /' . $this->vpnNetmask);
            $this->log('vpnNetwork ' . $this->vpnNetwork);
            $this->log('vpnDnsServers ' . implode(', ', $this->vpnDnsServers));
            $this->log("netnsName " . $this->netnsName . "\n");

            if (!(
                    $this->netInterface
                &&  $this->vpnClientIp
                &&  $this->vpnNetmask
                &&  $this->vpnGatewayIp
                &&  $this->vpnDnsServers
                &&  $this->vpnNetwork
            )) {
                $this->log("Failed to get VPN config");
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            // https://developers.redhat.com/blog/2018/10/22/introduction-to-linux-interfaces-for-virtual-networking#ipvlan
            $commands = [
                "ip netns add {$this->netnsName}",
                "ip link set dev {$this->netInterface} up netns {$this->netnsName}",
                "ip netns exec {$this->netnsName}  ip -4 addr add {$this->vpnClientIp}/32 dev {$this->netInterface}",

                "ip netns exec {$this->netnsName}  ip link set dev lo up",

                "ip netns exec {$this->netnsName}  ip route add {$this->vpnNetwork}/{$this->vpnNetmask} dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add default dev {$this->netInterface} via {$this->vpnGatewayIp}",
                (static::$IFB_DEVICE_SUPPORT  ?
                "ip netns exec {$this->netnsName}  ip link add ifb0 type ifb" : ''
                ),
                "ip netns exec {$this->netnsName}  ip addr show",
                "ip netns exec {$this->netnsName}  ip route show",
            ];

            foreach ($commands as $command) {
                $r = _shell_exec($command);
                $this->log($r, !strlen($r));
            }

            //------------

            $this->resolveFileDir = "/etc/netns/{$this->netnsName}";
            $this->resolveFilePath = $this->resolveFileDir . "/resolv.conf";
            if (! is_dir($this->resolveFileDir)) {
                mkdir($this->resolveFileDir, 0775, true);
            }

            $this->vpnDnsServers[] = '8.8.8.8';
            $this->vpnDnsServers = array_unique($this->vpnDnsServers);
            $nameServersList  = array_map(
                function ($ip) {
                    return "nameserver $ip";
                },
                $this->vpnDnsServers
            );
            $nameServersListStr = implode("\n", $nameServersList);
            file_put_contents($this->resolveFilePath, $nameServersListStr);

            $this->log(_shell_exec("ip netns exec {$this->netnsName}  cat /etc/resolv.conf") . "\n");

            //-------------------------------------------------------------------

            $this->startConnectionQualityTest();
            return false;
        }

        // Check timeout
        $timeElapsed = time() - $this->connectionStartedAt;
        if ($timeElapsed > static::VPN_CONNECT_TIMEOUT) {
            $this->log("VPN Timeout");
            $this->terminate(true);
            return -1;
        }

        return false;
    }

    private function log($message, $noLineEnd = false)
    {
        $message .= $noLineEnd  ?  '' : "\n";
        $this->log .= $message;
        if ($this->instantLog) {
            echo $message;
        }
    }

    public function clearLog()
    {
        $this->log = '';
    }

    public function getLog()
    {
        return mbRTrim($this->log);
    }

    public function getOpenVpnConfig() : OpenVpnConfig
    {
        return $this->openVpnConfig;
    }

    public function getIndex() : int
    {
        return $this->vpnIndex;
    }

    public function getTitle($singleLine = true) : string
    {
        return $this->openVpnConfig->getProvider()->getName() . ($singleLine ? ' ~ ' : "\n") . $this->openVpnConfig->getOvpnFileSubPath();
    }

    public function getNetnsName()
    {
        return $this->netnsName;
    }

    public function getVpnPublicIp()
    {
        return $this->connectionQualityPublicIp;
    }

    public function setApplicationObject($applicationObject)
    {
        $this->applicationObject = $applicationObject;
    }

    public function getApplicationObject()
    {
        return $this->applicationObject;
    }

    public function terminate($hasError = false)
    {
        $this->connectionQualityTestTerminate();

        if ($this->vpnProcessPGid) {
            $this->log("OpenVpnConnection SIGTERM PGID -{$this->vpnProcessPGid}");
            @posix_kill(0 - $this->vpnProcessPGid, SIGTERM);
        }

        @proc_terminate($this->vpnProcess);
        @proc_close($this->vpnProcess);
        if ($this->netnsName) {
            _shell_exec("ip netns delete {$this->netnsName}");
        }

        if ($hasError) {
            $this->openVpnConfig->logConnectionFail();
        }
        OpenVpnProvider::releaseOpenVpnConfig($this->openVpnConfig);

        @unlink($this->resolveFilePath);
        @rmdir($this->resolveFileDir);
        @unlink($this->credentialsFileTrimmed);
        @unlink($this->envFile);
        
    }

    public function isAlive()
    {
        $isProcAlive = isProcAlive($this->vpnProcess);
        return $isProcAlive;
    }

    private function getCredentialsArgs()
    {
        global $TEMP_DIR;

        $ret = '';
        $credentialsFile = $this->openVpnConfig->getCredentialsFile();
        $this->credentialsFileTrimmed = $TEMP_DIR . '/credentials-trimmed-' . $this->netInterface . '.txt';

        if (file_exists($credentialsFile)) {
            $credentialsFileContent = mbTrim(file_get_contents($credentialsFile));
            $credentialsFileLines = mbSplitLines($credentialsFileContent);

            $login = mbTrim($credentialsFileLines[0] ?? '');
            $password = mbTrim($credentialsFileLines[1] ?? '');
            if (!($login  &&  $password)) {
                _die("Credential file \"$credentialsFile\" has wrong content. It should contain two lines.\n"
                    . "First line - login, second line - password");
            }

            $trimmedContent = $login . "\n" . $password;
            file_put_contents_secure($this->credentialsFileTrimmed, $trimmedContent);
            $ret = "--auth-user-pass \"{$this->credentialsFileTrimmed}\"";
        }

        return $ret;
    }

    public function isConnected()
    {
        $netInterfaceInfo = _shell_exec("ip netns exec {$this->netnsName}   ip link show dev {$this->netInterface}");
        $netInterfaceExists = mb_strpos($netInterfaceInfo, $this->netInterface . ':') !== false;
        return $netInterfaceExists;
    }

    public function startConnectionQualityTest()
    {
        $this->log('Starting Connection Quality Test');
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $data = new stdClass();

        $data->startedAt = time();
        $data->processes = new stdClass();
        $data->pipes = new stdClass();
        $data->processes->icmpPingProcess = proc_open("ip netns exec {$this->netnsName}   ping  -c 1              -w 10  8.8.8.8",                 $descriptorSpec, $data->pipes->icmpPing);
        $data->processes->httpPingProcess = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://google.com",       $descriptorSpec, $data->pipes->httpPing);
        $data->processes->ipechoProcess   = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://ipecho.net/plain", $descriptorSpec, $data->pipes->ipecho);
        $data->processes->ipify4Process   = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://api.ipify.org/",   $descriptorSpec, $data->pipes->ipify4);
        $data->processes->ipify64Process  = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://api64.ipify.org/", $descriptorSpec, $data->pipes->ipify64);

        $this->connectionQualityTestData = $data;
    }

    public function connectionQualityTestTerminate($exitCode = -1)
    {
        if (is_object($this->connectionQualityTestData)) {
            $data = $this->connectionQualityTestData;

            foreach ($data->processes as $process) {
                @proc_terminate($process);
                @proc_close($process);
            }

            $this->connectionQualityTestData = $exitCode;
            return $exitCode;
        }
    }

    /**
     * @return bool|int   -1      on error
     *                    null    if test was not started yet
     *                    false   if the test was started, but is not finished yet
     *                    true    the test was finished
     */

    public function processConnectionQualityTest()
    {
        if (is_object($this->connectionQualityTestData)) {
            $data = $this->connectionQualityTestData;

            $unfinishedProcessesNames = [];
            foreach ($data->processes as $processName => $process) {
                if (! is_resource($process)) {
                    return $this->connectionQualityTestTerminate(-1);  // Error. Something went wrong
                }
                $processStatus = proc_get_status($process);
                if ($processStatus['running']) {
                    $unfinishedProcessesNames[] = $processName;
                }
            }

            if (count($unfinishedProcessesNames)) {
                $testDuration = time() - $data->startedAt;
                if ($testDuration > 15) {
                    // Timeout 15 seconds
                    $unfinishedProcessesNamesStr = implode(', ', $unfinishedProcessesNames);
                    $this->log(Term::red . "Connection Quality Test timeout ($unfinishedProcessesNamesStr)" . Term::clear);
                } else {
                    return false;  // Pending results
                }
            }

            $icmpPingStdOut = streamReadLines($data->pipes->icmpPing[1], 0);
            $httpPingStdOut = streamReadLines($data->pipes->httpPing[1], 0);
            $ipechoStdOut   = streamReadLines($data->pipes->ipecho[1],   0);
            $ipify4StdOut   = streamReadLines($data->pipes->ipify4[1],   0);
            $ipify64StdOut  = streamReadLines($data->pipes->ipify64[1],  0);

            $this->connectionQualityIcmpPing = mb_strpos($icmpPingStdOut, 'bytes from 8.8.8.8') !== false;
            $this->connectionQualityHttpPing = (boolean) strlen(trim($httpPingStdOut));

            $ipechoIp  = filter_var($ipechoStdOut, FILTER_VALIDATE_IP);
            $ipify4Ip  =  filter_var($ipify4StdOut, FILTER_VALIDATE_IP);
            $ipify64Ip = filter_var($ipify64StdOut, FILTER_VALIDATE_IP);
            $vpnEnvIp  = $this->upEnv['trusted_ip']  ??  '';

            $ipsList  = array_filter([
                'ipify4Ip'  => $ipify4Ip,
                'ipechoIp'  => $ipechoIp,
                'ipify64Ip' => $ipify64Ip,
                'vpnEnvIp'  => $vpnEnvIp
            ]);

            MainLog::log('$ipsList' . print_r($ipsList, true), 1, 0, MainLog::LOG_NONE);
            $this->connectionQualityPublicIp = getArrayFirstValue($ipsList);

            return $this->connectionQualityTestTerminate(true);  // The test is finished
        }

        return $this->connectionQualityTestData;
    }

    public function calculateNetworkTrafficStat()
    {
        $stats = getNetworkInterfaceStats($this->netInterface, $this->netnsName);
        if ($stats) {
            static::$devicesReceived[$this->netInterface]    = $stats->received;
            static::$devicesTransmitted[$this->netInterface] = $stats->transmitted;

            $duration = time() - $this->connectedAt;
            if ($duration) {
                $rSpeed = $stats->received / $duration;
                $rSpeed = intRound($rSpeed * 8);
                $stats->receiveSpeed = $rSpeed;

                $tSpeed = $stats->transmitted / $duration;
                $tSpeed = intRound($tSpeed * 8);
                $stats->transmitSpeed = $tSpeed;
            } else {
                $stats->receiveSpeed = 0;
                $stats->transmitSpeed = 0;
            }

            $stats->connected = true;
            $stats->connectedAt = $this->connectedAt;
            $stats->sumTraffic = $stats->received + $stats->transmitted;
            $stats->sumSpeed = $stats->receiveSpeed + $stats->transmitSpeed;

            return $stats;
        } else {
            $ret = new stdClass();
            $ret->received    = 0;
            $ret->transmitted = 0;
            $ret->sumTraffic  = 0;
            $ret->sumSpeed    = 0;
            $ret->connected   = false;
            return $ret;
        }
    }

    public function setBandwidthLimit($receiveSpeedBits, $transmitSpeedBits)
    {
        global $HOME_DIR;
        $transmitSpeedKbps = intRound($transmitSpeedBits / 1000);
        $receiveSpeedKbps  = intRound($receiveSpeedBits  / 1000);
        if ($transmitSpeedKbps  &&  $receiveSpeedKbps) {
            MainLog::log("Set bandwidth limit: up $transmitSpeedBits, down $receiveSpeedBits (bits/sec)", 1, 0, MainLog::LOG_DEBUG);
            if (static::$IFB_DEVICE_SUPPORT) {
                $wondershaper = $HOME_DIR . '/open-vpn/wondershaper-1.4.1.bash';
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -c")), 1, 0, MainLog::LOG_DEBUG);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -d $receiveSpeedKbps  -u $transmitSpeedKbps")), 1, 0, MainLog::LOG_DEBUG);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -s")), 1, 0, MainLog::LOG_NONE);
            } else {
                $wondershaper = $HOME_DIR . '/open-vpn/wondershaper-1.1.sh';
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  clear {$this->netInterface}")), 1, 0, MainLog::LOG_DEBUG);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper        {$this->netInterface}  $receiveSpeedKbps  $transmitSpeedKbps")), 1, 0, MainLog::LOG_DEBUG);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper        {$this->netInterface}")), 1, 0, MainLog::LOG_NONE);
            }
        }
    }

    public function calculateAndSetBandwidthLimit($vpnConnectionsCount)
    {
        global $NETWORK_USAGE_LIMIT;

        if (
                $NETWORK_USAGE_LIMIT === '100%'
            || !ResourcesConsumption::$receiveSpeedLimit
            || !ResourcesConsumption::$transmitSpeedLimit
        ) {
            return;
        }

        $thisConnectionTransmitSpeedBits = intRound(ResourcesConsumption::$transmitSpeedLimit / $vpnConnectionsCount);
        $thisConnectionReceiveSpeedBits  = intRound(ResourcesConsumption::$receiveSpeedLimit  / $vpnConnectionsCount);

        $this->setBandwidthLimit($thisConnectionReceiveSpeedBits, $thisConnectionTransmitSpeedBits);
    }

    public function getScoreBlock()
    {
        if (!is_object($this->applicationObject)) {
            return false;
        }

        $efficiencyLevel = $this->applicationObject->getEfficiencyLevel();
        $trafficStat = $this->calculateNetworkTrafficStat();
        if (!$trafficStat->connected) {
            return false;
        }

        $score = intRound($efficiencyLevel / 10  *  roundLarge($trafficStat->receiveSpeed / 1024));
        if ($score) {
            $this->openVpnConfig->setCurrentSessionScorePoints($score);
        }

        $ret = new stdClass();
        $ret->efficiencyLevel = $efficiencyLevel;
        $ret->score           = $score;
        $ret->trafficStat     = $trafficStat;

        return $ret;
    }

    // ----------------------  Static part of the class ----------------------

    private static string $UP_SCRIPT;

    private static bool   $IFB_DEVICE_SUPPORT;

    public static int   $previousSessionsTransmitted,
                        $previousSessionsReceived;
    public static array $devicesTransmitted,
                        $devicesReceived;

    public static function constructStatic()
    {
        static::$UP_SCRIPT = __DIR__ . '/on-open-vpn-up.cli.php';

        static::$previousSessionsReceived = 0;
        static::$previousSessionsTransmitted = 0;
        static::$devicesReceived = [];
        static::$devicesTransmitted = [];
        static::checkIfbDevice();
    }

    public static function recalculateSessionTraffic()
    {
        global $VPN_CONNECTIONS;
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $vpnConnection->calculateNetworkTrafficStat();
        }
    }

    public static function newIteration()
    {
        static::$previousSessionsReceived    += array_sum(static::$devicesReceived);
        static::$previousSessionsTransmitted += array_sum(static::$devicesTransmitted);
        static::$devicesReceived    = [];
        static::$devicesTransmitted = [];
    }

    public static function getEnvFilePath($netInterface)
    {
        global $TEMP_DIR;
        return $TEMP_DIR . "/open-vpn-env-{$netInterface}.txt";
    }

    private static function checkIfbDevice()
    {
                  _shell_exec('ip link delete ifb987654');
        $stdOut = _shell_exec('ip link add ifb987654 type ifb');
        if (strlen($stdOut)) {
            MainLog::log('"Intermediate Functional Block" devices (ifb) not supported by this Linux kernel. The script will use old version of Wondershaper', 2, 0, MainLog::LOG_PROXY);
            static::$IFB_DEVICE_SUPPORT = false;
        } else {
            _shell_exec('ip link delete ifb987654');
            static::$IFB_DEVICE_SUPPORT = true;
        }
    }
}

OpenVpnConnection::constructStatic();