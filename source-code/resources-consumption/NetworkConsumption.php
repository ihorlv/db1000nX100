<?php

class NetworkConsumption
{
    public static int $transmitSpeedLimitBits,
                      $receiveSpeedLimitBits,
                      $trackingPeriodReceiveSpeed,
                      $trackingPeriodTransmitSpeed,
                      $speedTestLastTestAt;

    private static object $currentSessionStats;
    private static array  $speedtestStatTransmit,
                          $speedtestStatReceive;

    public static function constructStatic()
    {
        static::$speedtestStatTransmit = [];
        static::$speedtestStatReceive = [];
    }

    public static function trackingPeriodNetworkUsageStartTracking()
    {
        $defaultNetworkInterfaceStats = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();
        if (!$defaultNetworkInterfaceStats) {
            _die('Failed to obtain statistics from Linux default network interface');
        }

        $stats = new stdClass();
        $stats->trackingStartedAt  = time();
        $stats->onStartReceived    = $defaultNetworkInterfaceStats->received;
        $stats->onStartTransmitted = $defaultNetworkInterfaceStats->transmitted;

        static::$currentSessionStats = $stats;
    }

    public static function trackingPeriodNetworkUsageFinishTracking()
    {
        $defaultNetworkInterfaceStats = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();
        if (!$defaultNetworkInterfaceStats) {
            _die('Failed to obtain statistics from Linux default network interface');
        }

        $stats = static::$currentSessionStats;
        $stats->trackingFinishededAt    = time();
        $stats->onFinishReceived        = $defaultNetworkInterfaceStats->received;
        $stats->onFinishTransmitted     = $defaultNetworkInterfaceStats->transmitted;

        // ---

        $trackingPeriodDuration = $stats->trackingFinishededAt - $stats->trackingStartedAt;
        if ($trackingPeriodDuration === 0) {
            $trackingPeriodDuration = 60;
        }

        $trackingPeriodReceived    = $stats->onFinishReceived - $stats->onStartReceived;
        $trackingPeriodTransmitted = $stats->onFinishTransmitted - $stats->onStartTransmitted;

        static::$trackingPeriodReceiveSpeed  = intRound($trackingPeriodReceived    * 8 / $trackingPeriodDuration);
        static::$trackingPeriodTransmitSpeed = intRound($trackingPeriodTransmitted * 8 / $trackingPeriodDuration);
    }

    public static function calculateNetworkBandwidthLimit($marginTop = 1, $marginBottom = 1)
    {
        global $NETWORK_USAGE_GOAL,
               $SESSIONS_COUNT;

        if (!$NETWORK_USAGE_GOAL) {
            return;
        }

        if (!Config::isOptionValueInPercents($NETWORK_USAGE_GOAL)) {
            $NETWORK_USAGE_GOAL = (int) $NETWORK_USAGE_GOAL;
            $transmitSpeedLimitMib = intRound($NETWORK_USAGE_GOAL);
            $receiveSpeedLimitMib  = intRound($NETWORK_USAGE_GOAL);
            MainLog::log("Network speed limit is set to fixed value {$NETWORK_USAGE_GOAL}Mib (upload {$transmitSpeedLimitMib}Mib; download {$receiveSpeedLimitMib}Mib)", $marginBottom, $marginTop);
            static::$transmitSpeedLimitBits = $transmitSpeedLimitMib * 1024 * 1024;
            static::$receiveSpeedLimitBits  = $receiveSpeedLimitMib  * 1024 * 1024;
            return;
        }

        if (
            $SESSIONS_COUNT > 10
            &&  time() - static::$speedTestLastTestAt < 30 * 60
        ) {
            MainLog::log('Omit SpeedTest in this session', $marginBottom, $marginTop);
            return;
        }

        // ---------------------------

        $testReturnObj = $uploadBandwidthBits = $downloadBandwidthBits = null;

        $serversListStdout = _shell_exec('/usr/bin/speedtest  --servers  --format=json-pretty');
        $serversListReturnObj = @json_decode($serversListStdout);
        $serversList = $serversListReturnObj->servers  ??  [];
        $attempt = 1;

        if (count($serversList)) {
            shuffle($serversList);
            foreach ($serversList as $server) {

                MainLog::log("Performing Speed Test of your Internet connection ", 1, $attempt === 1  ?  $marginTop : 0);
                TimeTracking::startTaskTimeTracking('InternetConnectionSpeedTest');
                $stdout = _shell_exec("/usr/bin/speedtest  --accept-license  --accept-gdpr  --server-id=$server->id  --format=json-pretty");
                $stdout = preg_replace('#^.*?\{#s', '{', $stdout);
                $testReturnObj = @json_decode($stdout);
                TimeTracking::stopTaskTimeTracking( 'InternetConnectionSpeedTest');

                $uploadBandwidthBits   = ($testReturnObj->upload->bandwidth   ?? 0) * 8;
                $downloadBandwidthBits = ($testReturnObj->download->bandwidth ?? 0) * 8;

                if (
                    is_object($testReturnObj)
                    &&  $uploadBandwidthBits
                    &&  $downloadBandwidthBits
                ) {
                    break;
                }

                if ($attempt >= 5) {
                    break;
                } else {
                    $attempt++;
                }

                MainLog::log($stdout, 1, 0, MainLog::LOG_GENERAL_ERROR);
                MainLog::log("Network speed test failed. Doing one more attempt", 2, 0, MainLog::LOG_GENERAL_ERROR);
            }
        } else {
            MainLog::log($serversListStdout,             1, 0, MainLog::LOG_GENERAL_ERROR);
            MainLog::log("Failed to fetch servers list", 1, 0, MainLog::LOG_GENERAL_ERROR);
        }

        if (
            !is_object($testReturnObj)
            || !$uploadBandwidthBits
            || !$downloadBandwidthBits
        ) {
            MainLog::log("Network speed test failed $attempt times", 1, 0, MainLog::LOG_GENERAL_ERROR);
            if (static::$transmitSpeedLimitBits  &&  static::$receiveSpeedLimitBits) {
                MainLog::log("The script will use previous session network limits");
            }
            MainLog::log('', $marginBottom);
            return;
        }

        $serverName     = $testReturnObj->server->name     ?? '';
        $serverLocation = $testReturnObj->server->location ?? '';
        $serverCountry  = $testReturnObj->server->country  ?? '';
        MainLog::log("Server:  $serverName; $serverLocation; $serverCountry; https://www.speedtest.net");

        static::$speedtestStatTransmit[$SESSIONS_COUNT] = $transmitSpeed = (int) $uploadBandwidthBits;
        static::$speedtestStatTransmit = array_slice(static::$speedtestStatTransmit, -10, null, true);
        $transmitSpeedAverage = intRound(array_sum(static::$speedtestStatTransmit) / count(static::$speedtestStatTransmit));
        static::$transmitSpeedLimitBits = intRound(intval($NETWORK_USAGE_GOAL) * $transmitSpeedAverage / 100);

        static::$speedtestStatReceive[$SESSIONS_COUNT] = $receiveSpeed = (int) $downloadBandwidthBits;
        static::$speedtestStatReceive = array_slice(static::$speedtestStatReceive, -10, null, true);
        $receiveSpeedAverage = intRound(array_sum(static::$speedtestStatReceive) / count(static::$speedtestStatReceive));
        static::$receiveSpeedLimitBits = intRound(intval($NETWORK_USAGE_GOAL) * $receiveSpeedAverage / 100);

        static::$speedTestLastTestAt = time();

        MainLog::log(
            'Results: Upload speed '
            . humanBytes($transmitSpeed, HUMAN_BYTES_BITS)
            . ', average '
            . humanBytes($transmitSpeedAverage, HUMAN_BYTES_BITS)
            . ', set limit to '
            . humanBytes(static::$transmitSpeedLimitBits, HUMAN_BYTES_BITS)
            . " ($NETWORK_USAGE_GOAL)"
        );

        MainLog::log(
            '       Download speed '
            . humanBytes($receiveSpeed, HUMAN_BYTES_BITS)
            . ', average '
            . humanBytes($receiveSpeedAverage, HUMAN_BYTES_BITS)
            . ', set limit to '
            . humanBytes(static::$receiveSpeedLimitBits, HUMAN_BYTES_BITS)
            . " ($NETWORK_USAGE_GOAL)",
            $marginBottom);
    }
}

NetworkConsumption::constructStatic();