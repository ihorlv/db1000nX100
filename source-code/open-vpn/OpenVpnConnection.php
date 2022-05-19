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
            $credentialsFileTrimmed,

            $connectionQualityTestData,
            $connectionQualityIcmpPing,
            $connectionQualityHttpPing,
            $connectionQualityPublicIp,

                                                                  $test;
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
                "ip netns exec {$this->netnsName}  ip route add {$this->vpnNetwork}/{$this->vpnNetmask} dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add default dev {$this->netInterface} via {$this->vpnGatewayIp}",
                "ip netns exec {$this->netnsName}  ip addr show",
                "ip netns exec {$this->netnsName}  ip route show"
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

    public function isConnected()
    {
        $netInterfaceInfo = _shell_exec("ip netns exec {$this->netnsName}   ip link show dev {$this->netInterface}");
        $netInterfaceExists = mb_strpos($netInterfaceInfo, $this->netInterface . ':') !== false;
        return $netInterfaceExists;
    }

    public function startConnectionQualityTest()
    {
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $data = new stdClass();

        $data->icmpPingProcess = proc_open("ip netns exec {$this->netnsName}   ping  -c 1  -w 10  8.8.8.8",                             $descriptorSpec, $data->icmpPingPipes);
        $data->httpPingProcess = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://google.com",       $descriptorSpec, $data->httpPingPipes);
        $data->ipechoProcess   = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://ipecho.net/plain", $descriptorSpec, $data->ipechoPipes);
        $data->ipify4Process   = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://api.ipify.org/",   $descriptorSpec, $data->ipify4Pipes);
        $data->ipify64Process  = proc_open("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://api64.ipify.org/", $descriptorSpec, $data->ipify64Pipes);

        $this->connectionQualityTestData = $data;
    }

    public function connectionQualityTestTerminate($exitCode = -1)
    {
        if (is_object($this->connectionQualityTestData)) {
            $data = $this->connectionQualityTestData;
            @proc_close($data->icmpPingProcess);
            @proc_close($data->httpPingProcess);
            @proc_close($data->ipechoProcess);
            @proc_close($data->ipify4Process);
            @proc_close($data->ipify64Process);
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
            $processes = [
                $data->icmpPingProcess,
                $data->httpPingProcess,
                $data->ipify4Process,
                $data->ipify64Process,
                $data->ipechoProcess
            ];

            foreach ($processes as $process) {
                if (! is_resource($process)) {
                    return $this->connectionQualityTestTerminate(-1);  // Error. Something went wrong
                }

                $processStatus = proc_get_status($process);
                if ($processStatus['running']) {
                    return false; // Pending results
                }
            }

            $icmpPingStdOut = streamReadLines($data->icmpPingPipes[1], 0);
            $httpPingStdOut = streamReadLines($data->httpPingPipes[1], 0);
            $ipechoStdOut   = streamReadLines($data->ipechoPipes[1],   0);
            $ipify4StdOut   = streamReadLines($data->ipify4Pipes[1],   0);
            $ipify64StdOut  = streamReadLines($data->ipify64Pipes[1],  0);

            //MainLog::log('stdout' . print_r([$icmpPingStdOut, $httpPingStdOut, $ipify4StdOut, $ipify6StdOut, $ipechoStdOut], true));

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

            MainLog::log('$ipsList' . print_r($ipsList, true), 1, 0, MainLog::LOG_DEBUG);
            $this->connectionQualityPublicIp = getArrayFirstValue($ipsList);

            return $this->connectionQualityTestTerminate(true);  // The test is finished
        }

        return $this->connectionQualityTestData;
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

    public function calculateNetworkTrafficStat()
    {
        $stats = calculateNetworkTrafficStat($this->netInterface, $this->netnsName);
        if ($stats) {
            static::$devicesReceived[$this->netInterface]    = $stats->received;
            static::$devicesTransmitted[$this->netInterface] = $stats->transmitted;
            $stats->connected = true;
            return $stats;
        } else {
            $ret = new stdClass();
            $ret->received    = 0;
            $ret->transmitted = 0;
            $ret->connected = false;
        }

        return $ret;
    }

    public function setBandwidthLimit($downloadSpeedBits, $uploadSpeedBits)
    {
        #ifb module missing
        global $HOME_DIR;
        $wondershaper = '/sbin/wondershaper';
        MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  clear {$this->netInterface}")), 1, 0, MainLog::LOG_DEBUG);
        $uploadSpeedKbps   = intRound($uploadSpeedBits / 1000);
        $downloadSpeedKbps = intRound($downloadSpeedBits / 1000);
        if ($uploadSpeedKbps  &&  $downloadSpeedKbps) {
            MainLog::log("Set bandwidth limit: up $uploadSpeedBits, down $downloadSpeedBits", 1, 0, MainLog::LOG_DEBUG);
            MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  {$this->netInterface}  $downloadSpeedKbps  $uploadSpeedKbps")), 1, 0, MainLog::LOG_DEBUG);
        }
    }

    public function calculateAndSetBandwidthLimit($vpnConnectionsCount)
    {
        global $NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS,
               $UPLOAD_SPEED_LIMIT,
               $DOWNLOAD_SPEED_LIMIT;

/*        $maxVpnNetworkSpeedBits = intRound($MAX_VPN_NETWORK_SPEED * 1024 * 1024);
        if ($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS === 100) {
            $thisConnectionDownloadSpeedBits = $thisConnectionUploadSpeedBits = $maxVpnNetworkSpeedBits;
        } else {
            $thisConnectionUploadSpeedBits = intRound($UPLOAD_SPEED_LIMIT * 1024 * 1024 / $vpnConnectionsCount);
            $thisConnectionUploadSpeedBits = min($thisConnectionUploadSpeedBits, $maxVpnNetworkSpeedBits);
            $thisConnectionDownloadSpeedBits = intRound($DOWNLOAD_SPEED_LIMIT * 1024 * 1024 / $vpnConnectionsCount);
            $thisConnectionDownloadSpeedBits = min($thisConnectionDownloadSpeedBits, $maxVpnNetworkSpeedBits);
        }*/

        if ($NETWORK_BANDWIDTH_LIMIT_IN_PERCENTS === 100) {
            return;
        }

        $thisConnectionUploadSpeedBits = intRound($UPLOAD_SPEED_LIMIT * 1024 * 1024 / $vpnConnectionsCount);
        $thisConnectionDownloadSpeedBits = intRound($DOWNLOAD_SPEED_LIMIT * 1024 * 1024 / $vpnConnectionsCount);

        $this->setBandwidthLimit($thisConnectionDownloadSpeedBits, $thisConnectionUploadSpeedBits);
    }

    public function getScoreBlock()
    {
        $efficiencyLevel = $this->applicationObject->getEfficiencyLevel();
        $trafficStat = $this->calculateNetworkTrafficStat();
        $score = (int) round($efficiencyLevel * roundLarge($trafficStat->received / 1024 / 1024));
        if ($score) {
            $this->openVpnConfig->setCurrentSessionScorePoints($score);
        }

        $ret = new stdClass();
        $ret->efficiencyLevel    = $efficiencyLevel;
        $ret->trafficReceived    = $trafficStat->received;
        $ret->trafficTransmitted = $trafficStat->transmitted;
        $ret->score = $score;

        return $ret;
    }

    // ----------------------  Static part of the class ----------------------

    private static string $UP_SCRIPT;

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
}

OpenVpnConnection::constructStatic();