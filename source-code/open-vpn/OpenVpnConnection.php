<?php

class OpenVpnConnection extends OpenVpnConnectionStatic
{
    const VPN_CONNECT_TIMEOUT = 60;

    protected $connectionStartedAt,
            $openVpnConfig,
            $envFile,
            $upEnv,
            $process,
            $exitCode,
            $processChildrenPGid,
            $connectionIndex,
            $applicationObject = null,
            $pipes,
            $log = '',
            $instantLog,
            $vpnClientIp,
            $vpnNetmask,
            $vpnNetwork,
            $vpnGatewayIp,
            $vpnDnsServers,
            $netnsName,
            $resolveFileDir,
            $resolveFilePath,
            $wasConnected = false,
            $connectionFailed = false,
            $terminated = false,
            $credentialsFileTrimmed,
            $connectionQualityTest = false,
            $publicIp,
            $currentCountry = false;

    public  $netInterface;
    public function __construct($connectionIndex, $openVpnConfig)
    {
        $this->connectionStartedAt = time();
        $this->connectionIndex = $connectionIndex;
        $this->netnsName = static::calculateNetnsName($this->connectionIndex);
        $this->netInterface = static::calculateInterfaceName($this->connectionIndex);
        _shell_exec("ip netns delete {$this->netnsName}");
        _shell_exec("ip link  delete {$this->netInterface}");
        $this->openVpnConfig = $openVpnConfig;
        $this->openVpnConfig->lock();

        $this->clearLog();
        $this->log('Connecting VPN' . $this->connectionIndex . ' "' . $this->getTitle() . '"');

        $caDataCiphers = '';
        {
            $dataCiphers = $this->getOpenVpnConfig()->getProvider()->getSetting('data_ciphers');
            if ($dataCiphers) {
                $caDataCiphers = "--cipher $dataCiphers --data-ciphers $dataCiphers";
            }
        }

        $vpnCommand  = 'cd "' . mbDirname($this->openVpnConfig->getOvpnFile()) . '"  ;'
                     . '  setsid   nice -n 9'
                     . '  ' . static::$OPEN_VPN_CLI_PATH
                     . '  ' . $caDataCiphers
                     . '  --config "' . $this->openVpnConfig->getOvpnFile() . '"'
                     . '  --ifconfig-noexec  --route-noexec'
                     . '  --script-security 2'
                     . '  --route-up "' . static::$UP_SCRIPT . '"'
                     . '  --dev-type tun --dev ' . $this->netInterface
                     . '  ' . $this->getCredentialsArgs()
                     . '  2>&1';

        // --tcp-nodelay

        $this->log($vpnCommand);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );

        $this->process = proc_open($vpnCommand, $descriptorSpec, $this->pipes);
        usleep(50 * 1000);

        // ---

        $processShellPid = $this->isAlive();
        if ($processShellPid === false) {
            $this->log('Command failed');
            $this->terminateAndKill(true);
            $this->connectionFailed = true;
            return;
        }

        // ---

        //passthru("pstree -g -p $processShellPid");
        $childrenPids = [];
        getProcessPidWithChildrenPids($processShellPid, false, $childrenPids);
        $processFirstChildPid = $childrenPids[1] ?? false;

        if (   !$processFirstChildPid
            ||  posix_getpgid($processFirstChildPid) !== $processFirstChildPid
        ) {
            $this->log('Setsid failed');
            $this->terminateAndKill(true);
            $this->connectionFailed = true;
            return;
        }

        $this->processChildrenPGid = $processFirstChildPid;

        // ---

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

        if ($this->isAlive() === false) {
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

            if (is_object($this->connectionQualityTest)) {

                $testStatus = $this->connectionQualityTest->process();
                if ($testStatus === false) {
                    // Waiting for the test results
                    return false;
                } else if ($testStatus === true) {

                    $this->log($this->connectionQualityTest->getLog());
                    $this->publicIp = $this->connectionQualityTest->getPublicIp();

                    if (!$this->connectionQualityTest->wasHttpPingOk()) {
                        $this->log(Term::red . "Can't send traffic through this VPN connection\n". Term::clear);
                        $this->connectionFailed = true;
                        $this->terminateAndKill(true);
                        return -1;
                    }

                    $this->wasConnected = true;
                    Actions::doFilter('OpenVpnSuccessfullyConnected', $this);
                    return true;
                }

            } else {

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
                    && $this->vpnClientIp
                    && $this->vpnNetmask
                    && $this->vpnGatewayIp
                    && $this->vpnDnsServers
                    && $this->vpnNetwork
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
                    (static::$IFB_DEVICE_SUPPORT ?
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
                if (!is_dir($this->resolveFileDir)) {
                    mkdir($this->resolveFileDir, 0775, true);
                }

                $this->vpnDnsServers[] = '8.8.8.8';
                $this->vpnDnsServers = array_unique($this->vpnDnsServers);
                $nameServersList = array_map(
                    function ($ip) {
                        return "nameserver $ip";
                    },
                    $this->vpnDnsServers
                );
                $nameServersListStr = implode("\n", $nameServersList);
                file_put_contents($this->resolveFilePath, $nameServersListStr);

                $this->log(_shell_exec("ip netns exec {$this->netnsName}  cat /etc/resolv.conf") . "\n");

                // ---

                $this->connectionQualityTest = new ConnectionQualityTest($this->netnsName);
                return false;
            }
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
        return $this->publicIp;
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
            $this->openVpnConfig->logFail();
        } else {
            Actions::doFilter('OpenVpnBeforeTerminate', $this);
            $this->openVpnConfig->logSuccess($this->publicIp);
        }

        $this->terminated = true;

        if ($this->processChildrenPGid) {
            $this->log("OpenVpnConnection terminate PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGTERM);
        }
    }

    public function isTerminated() : bool
    {
        return $this->terminated;
    }

    public function kill()
    {
        if (is_object($this->connectionQualityTest)) {
            $this->connectionQualityTest->abort();
        }

        if ($this->processChildrenPGid) {
            $this->log("OpenVpnConnection kill PGID -{$this->processChildrenPGid}");
            @posix_kill(0 - $this->processChildrenPGid, SIGKILL);
         }
        @proc_terminate($this->process, SIGKILL);
        @proc_close($this->process);

        // ---

        if ($this->netnsName) {
            _shell_exec("ip netns delete {$this->netnsName}");
        }

        // ---

        if ($this->resolveFilePath  &&  file_exists($this->resolveFilePath)) {
            unlink($this->resolveFilePath);
        }

        if ($this->resolveFileDir  &&  is_dir($this->resolveFileDir)) {
            rmdir($this->resolveFileDir);
        }

        if ($this->credentialsFileTrimmed  &&  file_exists($this->credentialsFileTrimmed)) {
            unlink($this->credentialsFileTrimmed);
        }

        if ($this->envFile  &&  file_exists($this->envFile)) {
            unlink($this->envFile);
        }

        $this->openVpnConfig->unlock();
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
        if (!is_resource($this->process)) {
            return false;
        }

        $this->getExitCode();

        $processStatus = proc_get_status($this->process);
        if ($processStatus['running']) {
            return $processStatus['pid'];
        }

        return false;
    }

    public function getExitCode()
    {
        $processStatus = proc_get_status($this->process);  // Only first call of this function return real value, next calls return -1.

        if ($processStatus  &&  $processStatus['exitcode'] !== -1) {
            $this->exitCode = $processStatus['exitcode'];
        }
        return $this->exitCode;
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
        global $NETWORK_USAGE_GOAL, $EACH_VPN_BANDWIDTH_MAX_BURST;

        if (
               !$NETWORK_USAGE_GOAL
            || !$EACH_VPN_BANDWIDTH_MAX_BURST
            || !NetworkConsumption::$receiveSpeedLimitBits
            || !NetworkConsumption::$transmitSpeedLimitBits
        ) {
            return;
        }

        $thisConnectionTransmitSpeedBits = intRound(NetworkConsumption::$transmitSpeedLimitBits / $vpnConnectionsCount * $EACH_VPN_BANDWIDTH_MAX_BURST);
        $thisConnectionReceiveSpeedBits  = intRound(NetworkConsumption::$receiveSpeedLimitBits  / $vpnConnectionsCount * $EACH_VPN_BANDWIDTH_MAX_BURST);

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