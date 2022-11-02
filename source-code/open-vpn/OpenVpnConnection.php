<?php

class OpenVpnConnection extends OpenVpnConnectionStatic
{
    const VPN_CONNECT_TIMEOUT = 60;

    protected $connectionStartedAt,
            $openVpnConfig,
            $envFile,
            $upEnv,
            $vpnProcess,
            $connectionIndex,
            $applicationObject = null,
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
            $terminated = false,
            $credentialsFileTrimmed,
            $connectionQualityTestData,
            $connectionQualityIcmpPing,
            $connectionQualityHttpPing,
            $connectionQualityPublicIp,
            $currentCountry = false;


    public function __construct($connectionIndex, $openVpnConfig)
    {
        $this->connectionStartedAt = time();
        $this->connectionIndex = $connectionIndex;
        $this->netnsName = static::calculateNetnsName($this->connectionIndex);
        $this->netInterface = static::calculateInterfaceName($this->connectionIndex);
        _shell_exec("ip netns delete {$this->netnsName}");
        _shell_exec("ip link  delete {$this->netInterface}");
        $this->openVpnConfig = $openVpnConfig;
        $this->openVpnConfig->logUse();

        $this->clearLog();
        $this->log('Connecting VPN' . $this->connectionIndex . ' "' . $this->getTitle() . '"');

        $vpnCommand  = 'cd "' . mbDirname($this->openVpnConfig->getOvpnFile()) . '" ;   nice -n 2   '
                     . static::$OPEN_VPN_CLI_PATH . '  --config "' . $this->openVpnConfig->getOvpnFile() . '"  --ifconfig-noexec  --route-noexec  '
                     . '--script-security 2  --route-up "' . static::$UP_SCRIPT . '"  --dev-type tun --dev ' . $this->netInterface . '  '
                     . $this->getCredentialsArgs() . '  '
                     . '  --tun-mtu 15000   2>&1';

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
            $this->terminateAndKill(true);
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

        $stdOutLines = streamReadLines($this->pipes[1], 0);
        if ($stdOutLines) {
            $this->log($stdOutLines, true);
        }

        if ($this->isAlive() !== true) {
            $this->connectionFailed = true;
            $this->terminateAndKill(true);
            return -1;
        }

        if (strpos($stdOutLines,'SIGTERM') !== false) {
            $this->connectionFailed = true;
            $this->terminateAndKill(true);
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
                    $this->log(Term::red . "Can't send any traffic through this VPN connection\n". Term::clear);
                    $this->connectionFailed = true;
                    $this->terminateAndKill(true);
                    return -1;
                }

                if ($this->connectionQualityPublicIp) {
                    $this->log("Detected VPN public IP " . $this->connectionQualityPublicIp);
                } else {
                    $this->log(Term::red . "Can't detected VPN public IP" . Term::clear);
                }

                $this->wasConnected = true;
                Actions::doFilter('OpenVpnSuccessfullyConnected', $this);
                $this->openVpnConfig->logConnectionSuccess($this->connectionQualityPublicIp);
                return true;
            } else if ($testStatus === -1) {
                $this->log(Term::red . "Connection Quality Test failed\n". Term::clear);
                $this->connectionFailed = true;
                $this->terminateAndKill(true);
                return -1;
            }
            // if $testStatus === null  The test was not started yet
            // Process connection setup

            //-------------------------------------------------------------------

            $this->envFile = OpenVpnCommon::getEnvFilePath($this->netInterface);
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
                $this->log("Failed to get VPN config\n");
                $this->connectionFailed = true;
                $this->terminateAndKill(true);
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
            $this->log("VPN Timeout\n");
            $this->terminateAndKill(true);
            return -1;
        }

        return false;
    }

    protected function log($message, $noLineEnd = false)
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
        return $this->connectionIndex;
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

    public function terminate($hasError)
    {
        if ($hasError) {
            Actions::doFilter('OpenVpnBeforeTerminateWithError', $this);
            $this->openVpnConfig->logConnectionFail();
        } else {
            Actions::doFilter('OpenVpnBeforeTerminate', $this);
        }

        $this->terminated = true;

        if ($this->vpnProcessPGid) {
            $this->log("OpenVpnConnection terminate PGID -{$this->vpnProcessPGid}");
            @posix_kill(0 - $this->vpnProcessPGid, SIGTERM);
        }
    }

    public function isTerminated() : bool
    {
        return $this->terminated;
    }

    public function kill()
    {
        $this->connectionQualityTestTerminate();

        if ($this->vpnProcessPGid) {
            $this->log("OpenVpnConnection kill PGID -{$this->vpnProcessPGid}");
            @posix_kill(0 - $this->vpnProcessPGid, SIGKILL);
         }
        @proc_terminate($this->vpnProcess, SIGKILL);
        @proc_close($this->vpnProcess);

        // ---

        if ($this->netnsName) {
            _shell_exec("ip netns delete {$this->netnsName}");
        }

        OpenVpnProvider::releaseOpenVpnConfig($this->openVpnConfig);

        @unlink($this->resolveFilePath);
        @rmdir($this->resolveFileDir);
        @unlink($this->credentialsFileTrimmed);
        @unlink($this->envFile);
        
    }

    public function terminateAndKill($hasError = false)
    {
        global $WAIT_SECONDS_BEFORE_PROCESS_KILL;
        $this->terminate($hasError);
        sayAndWait($WAIT_SECONDS_BEFORE_PROCESS_KILL);
        $this->kill();
    }

    public function isAlive()
    {
        $isProcAlive = isProcAlive($this->vpnProcess);
        return $isProcAlive;
    }

    protected function getCredentialsArgs()
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
        } else {
            $this->log(Term::red . 'File credentials.txt not found' . Term::clear);
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
                @proc_terminate($process, SIGKILL);
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

            $this->connectionQualityPublicIp = getArrayFirstValue($ipsList);

            return $this->connectionQualityTestTerminate(true);  // The test is finished
        }

        return $this->connectionQualityTestData;
    }

    public function setBandwidthLimit($receiveSpeedBits, $transmitSpeedBits)
    {
        global $HOME_DIR;
        $transmitSpeedKbps = intRound($transmitSpeedBits / 1000);
        $receiveSpeedKbps  = intRound($receiveSpeedBits  / 1000);
        if ($transmitSpeedKbps  &&  $receiveSpeedKbps) {
            MainLog::log("Set bandwidth limit: up $transmitSpeedBits, down $receiveSpeedBits (bits/sec)", 1, 0, MainLog::LOG_PROXY + MainLog::LOG_DEBUG);
            if (static::$IFB_DEVICE_SUPPORT) {
                $wondershaper = $HOME_DIR . '/open-vpn/wondershaper-1.4.1.bash';
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -c")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -d $receiveSpeedKbps  -u $transmitSpeedKbps")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  -a {$this->netInterface}  -s")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
            } else {
                $wondershaper = $HOME_DIR . '/open-vpn/wondershaper-1.1.sh';
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper  clear {$this->netInterface}")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper        {$this->netInterface}  $receiveSpeedKbps  $transmitSpeedKbps")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
                MainLog::log(trim(_shell_exec("ip netns exec {$this->netnsName}   $wondershaper        {$this->netInterface}")), 1, 0, MainLog::LOG_PROXY + MainLog::LOG_NONE);
            }
        }
    }

    public function calculateAndSetBandwidthLimit($vpnConnectionsCount)
    {
        global $NETWORK_USAGE_LIMIT, $EACH_VPN_BANDWIDTH_MAX_BURST;

        if (
               !$NETWORK_USAGE_LIMIT
            || !$EACH_VPN_BANDWIDTH_MAX_BURST
            || !ResourcesConsumption::$receiveSpeedLimitBits
            || !ResourcesConsumption::$transmitSpeedLimitBits
        ) {
            return;
        }

        $thisConnectionTransmitSpeedBits = intRound(ResourcesConsumption::$transmitSpeedLimitBits / $vpnConnectionsCount * $EACH_VPN_BANDWIDTH_MAX_BURST);
        $thisConnectionReceiveSpeedBits  = intRound(ResourcesConsumption::$receiveSpeedLimitBits  / $vpnConnectionsCount * $EACH_VPN_BANDWIDTH_MAX_BURST);

        $this->setBandwidthLimit($thisConnectionReceiveSpeedBits, $thisConnectionTransmitSpeedBits);
    }

    public function getCurrentCountry()
    {
        if ($this->currentCountry) {
            return $this->currentCountry;
        }

        try {
            $record = static::$maxMindGeoLite2->country($this->getVpnPublicIp());
            $this->currentCountry = $record->country->name;
        } catch (\Exception $e) {
            $this->currentCountry = '';
        }

        return $this->currentCountry;
    }

}