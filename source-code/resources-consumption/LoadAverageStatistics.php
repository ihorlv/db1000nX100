<?php

class LoadAverageStatistics
{
    public static object $totalNetworkStats,
                         $todayNetworkStats,
                         $yesterdayNetworkStats;

    public static array  $cpuUsagesPerSession,
                         $ramUsagesPerSession;

    private static int $currentDay = -1;

    public static function constructStatic()
    {
        static::$totalNetworkStats = new stdClass();
        static::$totalNetworkStats->received = 0;
        static::$totalNetworkStats->transmitted = 0;

        static::$todayNetworkStats = new stdClass();
        static::$todayNetworkStats->received = 0;
        static::$todayNetworkStats->transmitted = 0;

        static::$yesterdayNetworkStats = new stdClass();
        static::$yesterdayNetworkStats->received = 0;
        static::$yesterdayNetworkStats->transmitted = 0;

        static::$currentDay = intval(date('j'));

        Actions::addFilter('OpenVpnStatisticsTotalBadge', [static::class, 'filterOpenVpnStatisticsTotalBadge']);
    }

    public static function filterOpenVpnStatisticsTotalBadge($badge): string
    {
        global $SESSIONS_COUNT, $SCRIPT_STARTED_AT, $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START;

        // ---

        static::$totalNetworkStats->received    += OpenVpnStatistics::$pastSessionNetworkStats->received;
        static::$totalNetworkStats->transmitted += OpenVpnStatistics::$pastSessionNetworkStats->transmitted;

        static::$todayNetworkStats->received    += OpenVpnStatistics::$pastSessionNetworkStats->received;
        static::$todayNetworkStats->transmitted += OpenVpnStatistics::$pastSessionNetworkStats->transmitted;

        $newDay = intval(date('j'));
        if (static::$currentDay !== $newDay) {
            static::$currentDay = $newDay;

            static::$yesterdayNetworkStats = clone static::$todayNetworkStats;
            static::$todayNetworkStats->received = 0;
            static::$todayNetworkStats->transmitted = 0;
        }

        // ---

        $avBadge = "\n";

        if (static::$yesterdayNetworkStats->received + static::$yesterdayNetworkStats->transmitted) {
            $avBadge .= getHumanBytesLabel('Today traffic:    ', static::$todayNetworkStats->received, static::$todayNetworkStats->transmitted) . "\n";
            $avBadge .= getHumanBytesLabel('Yesterday traffic:', static::$yesterdayNetworkStats->received, static::$yesterdayNetworkStats->transmitted) . "\n";
        }

        $avBadge .=     getHumanBytesLabel('Total traffic:    ', static::$totalNetworkStats->received, static::$totalNetworkStats->transmitted) . "\n\n";

        // ---

        $defaultNetworkInterfaceStatsCurrent = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();
        $defaultNetworkInterfaceReceiveDifference = $defaultNetworkInterfaceStatsCurrent->received - $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START->received;
        $defaultNetworkInterfaceTransmitDifference = $defaultNetworkInterfaceStatsCurrent->transmitted - $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START->transmitted;

        $scriptRunDuration = time() - $SCRIPT_STARTED_AT;
        $defaultNetworkInterfaceReceiveSpeed = intRound($defaultNetworkInterfaceReceiveDifference * 8 / $scriptRunDuration);
        $defaultNetworkInterfaceTransmitSpeed = intRound($defaultNetworkInterfaceTransmitDifference * 8 / $scriptRunDuration);
        $avBadge .= getHumanBytesLabel('System average Network utilization:', $defaultNetworkInterfaceReceiveSpeed, $defaultNetworkInterfaceTransmitSpeed, HUMAN_BYTES_BITS) . "\n";

        // ---

        $pastSessionResourcesUsageValues = ResourcesConsumption::getPastSessionUsageValues();
        if (count($pastSessionResourcesUsageValues)) {

            static::$cpuUsagesPerSession[$SESSIONS_COUNT] = $pastSessionResourcesUsageValues['systemAverageCpuUsage']['current'];
            static::$ramUsagesPerSession[$SESSIONS_COUNT] = $pastSessionResourcesUsageValues['systemAverageRamUsage']['current'];

            static::$cpuUsagesPerSession = array_slice(static::$cpuUsagesPerSession, -100, null, true);
            static::$ramUsagesPerSession = array_slice(static::$ramUsagesPerSession, -100, null, true);

            $allSessionsAverageCpuUsage = intRound(array_sum(static::$cpuUsagesPerSession) / count(static::$cpuUsagesPerSession));
            $allSessionsAverageRamUsage = intRound(array_sum(static::$ramUsagesPerSession) / count(static::$ramUsagesPerSession));

            $avBadge .= "System average CPU utilization: $allSessionsAverageCpuUsage%\n";
            $avBadge .= "System average RAM utilization: $allSessionsAverageRamUsage%\n";
        }

        // ---

        return $badge . $avBadge;
    }
}

LoadAverageStatistics::constructStatic();