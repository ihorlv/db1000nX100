<?php

class OpenVpnConnectionStatic
{
    // ----------------------  Static part of the class ----------------------

    protected static string $OPEN_VPN_CLI_PATH,
                            $UP_SCRIPT;

    protected static bool   $IFB_DEVICE_SUPPORT;

    protected static array  $networkInterfacesStatsCache;

    public static object    $maxMindGeoLite2;

    public static function constructStatic()
    {
        global $HOME_DIR;

        static::$OPEN_VPN_CLI_PATH = '/usr/sbin/openvpn';
        static::$UP_SCRIPT = __DIR__ . '/on-open-vpn-up.cli.php';
        static::$networkInterfacesStatsCache = [];
        static::$maxMindGeoLite2 = new GeoIp2\Database\Reader($HOME_DIR . '/composer/max-mind/GeoLite2-Country.mmdb');

        Actions::addFilter('KillZombieProcesses',             [static::class, 'filterKillZombieProcesses']);
        Actions::addAction('AfterInitSession',               [static::class, 'actionAfterInitSession']);
        Actions::addAction('BeforeMainOutputLoopIteration',  [static::class, 'actionBeforeMainOutputLoopIteration']);
        Actions::addAction('MainOutputLongBrake',            [static::class, 'actionMainOutputLongBrake'], 0);

        Actions::addAction('TerminateSession',               [static::class, 'actionTerminateInstances'], 11);
        Actions::addAction('TerminateFinalSession',          [static::class, 'actionTerminateInstances'], 11);
        Actions::addAction('AfterTerminateSession',          [static::class, 'actionKillInstances']);
        Actions::addAction('AfterTerminateFinalSession',     [static::class, 'actionKillInstances']);

        static::checkIfbDevice();
    }

    public static function getInstances() : array
    {
        $ret = [];
        global $VPN_CONNECTIONS;
        foreach ($VPN_CONNECTIONS as $connectionIndex => $vpnConnection) {
            if (is_object($vpnConnection)) {
                $ret[$connectionIndex] = $vpnConnection;
            }
        }
        return $ret;
    }

    public static function getRunningInstances() : array
    {
        $vpnConnections = static::getInstances();

        $ret = [];
        foreach ($vpnConnections as $vpnConnection) {
            if (
                   !$vpnConnection->isTerminated()
                &&  $vpnConnection->isAlive()
            ) {
                $ret[] = $vpnConnection;
            }
        }
        return $ret;
    }

    public static function unsetInstanceByIndex($connectionIndex)
    {
        global $VPN_CONNECTIONS;
        unset($VPN_CONNECTIONS[$connectionIndex]);
    }

    public static function actionAfterInitSession()
    {
        global $VPN_SESSION_STARTED_AT;
        foreach (static::getInstances() as $vpnConnection) {
            $vpnConnection->sessionStartedAt = $VPN_SESSION_STARTED_AT;
            $vpnConnection->collectNetworkInterfaceStatsAfterInitSession();
        }
    }

    public static function actionBeforeMainOutputLoopIteration()
    {
        // Re-apply bandwidth limit to VPN connections
        static $previousLoopOnStartVpnConnectionsCount = 0;
        $vpnConnections = static::getInstances();

        if (count($vpnConnections) !== $previousLoopOnStartVpnConnectionsCount) {
            foreach ($vpnConnections as $vpnConnection) {
                if ($vpnConnection->isConnected()) {
                    $vpnConnection->calculateAndSetBandwidthLimit(count($vpnConnections));
                }
            }
            $previousLoopOnStartVpnConnectionsCount = count($vpnConnections);
        }
    }

    public static function actionMainOutputLongBrake()
    {
        foreach (static::getInstances() as $vpnConnection) {
            $vpnConnection->collectNetworkInterfaceStatsLast();
        }
    }

    public static function actionTerminateInstances()
    {
        foreach (static::getInstances() as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                  (!is_object($hackApplication)  ||  $hackApplication->isTerminated())
                && !$vpnConnection->isTerminated()
            ) {
                $vpnConnection->clearLog();
                $vpnConnection->terminate(false);
                MainLog::log('VPN' . $connectionIndex . ': ' . $vpnConnection->getLog(), 1, 0, MainLog::LOG_PROXY);
            }
        }
    }

    public static function actionKillInstances()
    {
        foreach (static::getInstances() as $connectionIndex => $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            if (
                    !is_object($hackApplication)
                ||  $hackApplication->isTerminated()
            ) {
                $vpnConnection->clearLog();
                $vpnConnection->kill();
                static::unsetInstanceByIndex($connectionIndex);
                MainLog::log('VPN' . $connectionIndex . ': ' . $vpnConnection->getLog(), 1, 0, MainLog::LOG_PROXY);
            }
        }
    }

    public static function calculateNetnsName($connectionIndex)
    {
        return 'netc' . $connectionIndex;
    }

    public static function calculateInterfaceName($connectionIndex)
    {
        return 'tun' . $connectionIndex;
    }

    protected static function checkIfbDevice()
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

        $openVpnConnections = static::getInstances();
        foreach ($openVpnConnections as $vpnConnection) {
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

    public static function filterKillZombieProcesses($data)
    {
        if (!class_exists('Config')) {
            return;
        }

        $linuxProcesses = $data['linuxProcesses'];

        if (count(static::getRunningInstances())) {
            $skipProcessesWithPids = $data['x100ProcessesPidsList'];
        } else {
            $skipProcessesWithPids = [];
        }

        killZombieProcesses($linuxProcesses, $skipProcessesWithPids, static::$OPEN_VPN_CLI_PATH);

        return $data;
    }
}

OpenVpnConnectionStatic::constructStatic();