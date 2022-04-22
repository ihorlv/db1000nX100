<?php

class OpenVpnConnection
{
    const VPN_CONNECT_TIMEOUT = 90;

    private $connectionStartedAt,
            $openVpnConfig,
            $envFile,
            $vpnProcess,
            $vpnIndex,
            $tunDeviceIndex,
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
            $vpnPublicIp,
            $netnsName,
            $netInterface,
            $resolveFileDir,
            $resolveFilePath,
            $wasConnected = false,
            $connectionFailed = false,
            $credentialsFileTrimmed,

                                                                  $test;
    public function __construct($vpnIndex, $tunDeviceIndex, $openVpnConfig)
    {
        $this->connectionStartedAt = time();
        $this->vpnIndex = $vpnIndex;
        $this->tunDeviceIndex = $tunDeviceIndex;
        $this->netInterface = 'tun' . $this->tunDeviceIndex;
        $this->openVpnConfig = $openVpnConfig;
        $this->openVpnConfig->logUse();

        $this->log('Connecting VPN' . $this->vpnIndex . ' "' . $this->openVpnConfig->getProvider()->getName() . ' - ' . $openVpnConfig->getOvpnFileBasename() . '"');

        $vpnCommand  = 'sleep 1 ;   cd "' . mbDirname($this->openVpnConfig->getOvpnFile()) . '" ;   '
                     . '/usr/sbin/openvpn  --config "' . $this->openVpnConfig->getOvpnFile() . '"  --ifconfig-noexec  --route-noexec  '
                     . '--script-security 2  --route-up "' . static::$UP_SCRIPT . '"  --dev-type tun --dev ' . $this->netInterface . '  '
                     . $this->getCredentialsArgs() . '  ' . $this->getEncryptionArgs() . '  '
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

            $this->envFile = static::getEnvFilePath($this->netInterface);
            $envJson = @file_get_contents($this->envFile);
            //$this->log($envJson);
            $env = json_decode($envJson, true);

            $this->vpnClientIp = $env['ifconfig_local'] ?? '';
            $this->vpnGatewayIp = $env['route_vpn_gateway'] ?? '';
            $this->vpnNetmask = $env['ifconfig_netmask'] ?? '255.255.255.255';
            $this->vpnNetwork = long2ip(ip2long($this->vpnGatewayIp) & ip2long($this->vpnNetmask));
            $this->netnsName = 'netc' . $this->tunDeviceIndex;

            $this->vpnDnsServers = [];
            $dnsRegExp = <<<PhpRegExp
                             #dhcp-option\s+DNS\s+([\d\.]+)#  
                             PhpRegExp;
            $i = 1;
            while ($foreignOption = $env['foreign_option_' . $i] ?? false) {
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

            shell_exec("ip netns delete {$this->netnsName}  2>&1");
            $commands = [
                "ip netns add {$this->netnsName}",
                "ip link set dev {$this->netInterface} up netns {$this->netnsName}",
                "ip netns exec {$this->netnsName}  ip addr add {$this->vpnClientIp}/32 dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add {$this->vpnNetwork}/{$this->vpnNetmask} dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add default dev {$this->netInterface} via {$this->vpnGatewayIp}",
                "ip netns exec {$this->netnsName}  ip addr show",
                "ip netns exec {$this->netnsName}  ip route show"
            ];

            foreach ($commands as $command) {
                $r = shell_exec("$command 2>&1");
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

            $this->log(shell_exec("ip netns exec {$this->netnsName}  cat /etc/resolv.conf   2>&1") . "\n");

            //------------

            $pingStatus = $this->checkPing();
            if ($pingStatus) {
                $this->log("VPN tunnel Ping OK");
            } else {
                $this->log(Term::red . "VPN tunnel Ping failed!" . Term::clear);
            }

            $this->vpnPublicIp = trim(shell_exec("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://ipecho.net/plain"));
            if (preg_match('#[^\d\.]#', $this->vpnPublicIp, $matches) > 0) {
                $this->log("\"http://ipecho.net/plain\" returned non IP address.\n"
                    . "Possibly your VPN is returning it's own HTML in any HTTP request\n"
                    . "This sometimes happens, if something is wrong with your subscription/credentials");
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            $httpCheckStatus = (boolean) ip2long($this->vpnPublicIp);
            if ($httpCheckStatus) {
                $this->log("Detected VPN public IP " . $this->vpnPublicIp);
            } else {
                $this->log(Term::red . "Can't detected VPN public IP" . Term::clear);
                $googleHtml = shell_exec("ip netns exec {$this->netnsName}   curl  --silent  --max-time 10  http://google.com");
                $httpCheckStatus = (boolean) strlen(trim($googleHtml));
            }

            if (! $httpCheckStatus) {
                $this->log(Term::red . "Http connection test failed!" . Term::clear);
            }

            if (!$pingStatus  &&  !$httpCheckStatus) {
                $this->log(Term::red . "Can't send any traffic through this VPN connection". Term::clear);
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            $this->wasConnected = true;
            return true;
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
        return $this->log;
    }

    public function getOpenVpnConfig()
    {
        return $this->openVpnConfig;
    }

    public function getNetnsName()
    {
        return $this->netnsName;
    }

    public function getVpnPublicIp()
    {
        return $this->vpnPublicIp;
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
        global $LOG_BADGE_WIDTH;

        if ($this->vpnProcessPGid) {
            $this->log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "OpenVpnConnection SIGTERM PGID -{$this->vpnProcessPGid}");
            @posix_kill(0 - $this->vpnProcessPGid, SIGTERM);
        }

        @proc_terminate($this->vpnProcess);
        if ($this->netnsName) {
            shell_exec("ip netns delete {$this->netnsName}  2>&1");
        }

        $this->openVpnConfig->logConnectionFinish(!$hasError, $this->vpnPublicIp);
        OpenVpnProvider::releaseOpenVpnConfig($this->openVpnConfig);
        @unlink($this->resolveFilePath);
        @rmdir($this->resolveFileDir);
        @unlink($this->credentialsFileTrimmed);
        @unlink($this->envFile);
        
    }

    public function isAlive()
    {
        return isProcAlive($this->vpnProcess);
    }

    public function checkPing()
    {
        $r = shell_exec("ip netns exec {$this->netnsName} ping  -c 1  -w 10  8.8.8.8   2>&1");
        return mb_strpos($r, 'bytes from 8.8.8.8') !== false;
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

    private function getEncryptionArgs()
    {
        return '';

        //-------------------------
        /*
        $noEncryption = $this->openVpnConfig->getProvider()->getSetting('no_encryption');
        $noEncryption = filter_var($noEncryption, FILTER_VALIDATE_BOOLEAN);
        if ($noEncryption) {
            return ' --tls-crypt --data-ciphers-fallback none  --cipher none';
        }
        return '';*/
    }

    // ----------------------  Static part of the class ----------------------

    private static $UP_SCRIPT = __DIR__ . '/on-open-vpn-up.cli.php';

    public static function getEnvFilePath($netInterface)
    {
        global $TEMP_DIR;
        return $TEMP_DIR . "/open-vpn-env-{$netInterface}.txt";
    }

    /*private static function getNewNetnsName($prefix = 'netc')
    {
        $list = shell_exec("ip netns show   2>&1");
        $regex = "#" . preg_quote($prefix) . "(\d+)#u";
        $count = preg_match_all($regex, $list, $matches);
        $maxId = -1;
        for ($i = 0; $i < $count; $i++) {
            $id = $matches[1][$i];
            $maxId = max($maxId, $id);
        }
        return $prefix . ($maxId + 1);
    }*/

    public static function getNextTunDeviceIndex($curDeviceIndex)
    {
        $ipLinks = shell_exec('ip link show   2>&1');
        $i = $curDeviceIndex;
        do {
            $i++;
        } while (strpos($ipLinks, 'tun' . $i . ':') !== false);

        return $i;
    }

}