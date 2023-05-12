<?php

class LoadAverageStatistics
{
    public static object $totalNetworkStats,
                         $todayNetworkStats,
                         $yesterdayNetworkStats;

    public static array  $cpuUsagesPerSession,
                         $ramUsagesPerSession;

    private static int $currentDay = -1;

    private static string $badge;

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

        Actions::addAction('TerminateSession',            [static::class, 'actionTerminateSession'], 12);
        Actions::addAction('TerminateFinalSession',       [static::class, 'actionTerminateSession'], 12);
        Actions::addFilter('OpenVpnStatisticsTotalBadge', [static::class, 'filterOpenVpnStatisticsTotalBadge']);
    }

    public static function actionTerminateSession()
    {
        global $SESSIONS_COUNT, $SCRIPT_STARTED_AT, $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START;

        static::$badge = "\n";

        $vpnInstancesNetworkTotals = OpenVpnConnectionStatic::getInstancesNetworkTotals();
        $pastSessionNetworkStats = $vpnInstancesNetworkTotals->session;

        // ---

        static::$totalNetworkStats->received    += $pastSessionNetworkStats->received;
        static::$totalNetworkStats->transmitted += $pastSessionNetworkStats->transmitted;

        static::$todayNetworkStats->received    += $pastSessionNetworkStats->received;
        static::$todayNetworkStats->transmitted += $pastSessionNetworkStats->transmitted;

        $newDay = intval(date('j'));
        if (static::$currentDay !== $newDay) {
            static::$currentDay = $newDay;

            static::$yesterdayNetworkStats = clone static::$todayNetworkStats;
            static::$todayNetworkStats->received = 0;
            static::$todayNetworkStats->transmitted = 0;
        }

        // ---

        if (static::$yesterdayNetworkStats->received + static::$yesterdayNetworkStats->transmitted) {
            static::$badge .= getHumanBytesLabel('Today traffic:    ', static::$todayNetworkStats->received, static::$todayNetworkStats->transmitted) . "\n";
            static::$badge .= getHumanBytesLabel('Yesterday traffic:', static::$yesterdayNetworkStats->received, static::$yesterdayNetworkStats->transmitted) . "\n";
        }

        static::$badge .=     getHumanBytesLabel('Total traffic:    ', static::$totalNetworkStats->received, static::$totalNetworkStats->transmitted) . "\n\n";

        // ---

        $defaultNetworkInterfaceStatsCurrent = OpenVpnConnectionStatic::getDefaultNetworkInterfaceStats();
        $defaultNetworkInterfaceReceiveDifference = $defaultNetworkInterfaceStatsCurrent->received - $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START->received;
        $defaultNetworkInterfaceTransmitDifference = $defaultNetworkInterfaceStatsCurrent->transmitted - $DEFAULT_NETWORK_INTERFACE_STATS_ON_SCRIPT_START->transmitted;

        $scriptRunDuration = time() - $SCRIPT_STARTED_AT;
        $defaultNetworkInterfaceReceiveSpeed = intRound($defaultNetworkInterfaceReceiveDifference * 8 / $scriptRunDuration);
        $defaultNetworkInterfaceTransmitSpeed = intRound($defaultNetworkInterfaceTransmitDifference * 8 / $scriptRunDuration);
        static::$badge .= getHumanBytesLabel('System average Network utilization:', $defaultNetworkInterfaceReceiveSpeed, $defaultNetworkInterfaceTransmitSpeed, HUMAN_BYTES_BITS) . "\n";

        // ---

        $usageValues = ResourcesConsumption::$pastSessionUsageValues;
        if (count($usageValues)) {

            static::$cpuUsagesPerSession[$SESSIONS_COUNT] = $usageValues['systemAverageCpuUsage']['current'];
            static::$ramUsagesPerSession[$SESSIONS_COUNT] = $usageValues['systemAverageRamUsage']['current'];

            static::$cpuUsagesPerSession = array_slice(static::$cpuUsagesPerSession, -100, null, true);
            static::$ramUsagesPerSession = array_slice(static::$ramUsagesPerSession, -100, null, true);

            $allSessionsAverageCpuUsage = intRound(array_sum(static::$cpuUsagesPerSession) / count(static::$cpuUsagesPerSession));
            $allSessionsAverageRamUsage = intRound(array_sum(static::$ramUsagesPerSession) / count(static::$ramUsagesPerSession));

            static::$badge .= "System average CPU utilization: $allSessionsAverageCpuUsage%\n";
            static::$badge .= "System average RAM utilization: $allSessionsAverageRamUsage%\n";
        }
    }

    public static function filterOpenVpnStatisticsTotalBadge($badge): string
    {
        return $badge . static::$badge;
    }
}

LoadAverageStatistics::constructStatic();