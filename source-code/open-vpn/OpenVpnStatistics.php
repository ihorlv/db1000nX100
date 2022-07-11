<?php

class OpenVpnStatistics
{
    public static object $previousSessionNetworkStats,
                         $totalNetworkStats;

    private static array  $connectionsStatsData;
    private static string $statisticsBadge = '';

    public static function constructStatic()
    {
        static::$previousSessionNetworkStats = new stdClass();
        static::$previousSessionNetworkStats->received = 0;
        static::$previousSessionNetworkStats->transmitted = 0;
        static::$previousSessionNetworkStats->receiveSpeed = 0;
        static::$previousSessionNetworkStats->transmitSpeed = 0;

        static::$totalNetworkStats = new stdClass();
        static::$totalNetworkStats->received = 0;
        static::$totalNetworkStats->transmitted = 0;

        Actions::addAction('BeforeInitSession',       [static::class, 'actionBeforeInitSession']);
        Actions::addAction('BeforeTerminateSession',  [static::class, 'actionBeforeTerminateSession'], 11);
        Actions::addAction('AfterTerminateSession',   [static::class, 'actionAfterTerminateSession']);
    }

    public static function actionBeforeInitSession()
    {
        static::$connectionsStatsData = [];
        static::$statisticsBadge      = '';
    }

    public static function actionBeforeTerminateSession()
    {
        static::calculateTrafficTotals();
        static::$statisticsBadge = static::generateBadge();
    }

    public static function actionAfterTerminateSession()
    {
        if (static::$statisticsBadge) {
            MainLog::log(static::$statisticsBadge);
            MainLog::log('', 2);
        }
    }

    public static function collectConnectionStats($connectionIndex, $networkStats)
    {
        $item = new stdClass();
        $item->networkStats = $networkStats;

        static::$connectionsStatsData[$connectionIndex] = $item;
    }

    private static function calculateTrafficTotals()
    {
        global $VPN_SESSION_STARTED_AT;

        static::$previousSessionNetworkStats->received = 0;
        static::$previousSessionNetworkStats->transmitted = 0;
        static::$previousSessionNetworkStats->receiveSpeed = 0;
        static::$previousSessionNetworkStats->transmitSpeed = 0;

        foreach (static::$connectionsStatsData as $connectionStatData) {
            static::$previousSessionNetworkStats->received    += $connectionStatData->networkStats->session->received;
            static::$previousSessionNetworkStats->transmitted += $connectionStatData->networkStats->session->transmitted;
        }

        $sessionDuration = time() - $VPN_SESSION_STARTED_AT;
        if ($sessionDuration) {
            static::$previousSessionNetworkStats->receiveSpeed =  intRound(static::$previousSessionNetworkStats->received    / $sessionDuration * 8 );
            static::$previousSessionNetworkStats->transmitSpeed = intRound(static::$previousSessionNetworkStats->transmitted / $sessionDuration * 8 );
        }

        // ---

        static::$totalNetworkStats->received    += static::$previousSessionNetworkStats->received;
        static::$totalNetworkStats->transmitted += static::$previousSessionNetworkStats->transmitted;
    }

    private static function calculateScore($networkStats, $applicationEfficiency) : int
    {
        return intRound($applicationEfficiency / 10  *  roundLarge($networkStats->session->receiveSpeed / 1024));
    }

    private static function generateBadge()
    {
        global $VPN_CONNECTIONS, $VPN_CONNECTIONS_ESTABLISHED_COUNT,
               $LONG_LINE_WIDTH,
               $SESSIONS_COUNT, $SCRIPT_STARTED_AT;

        $statisticsBadge  =  mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    SESSION STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
        $statisticsBadge .= "Session #$SESSIONS_COUNT\n";

        //--------------------------------------------------------------------------

        $completeStatsData = [];
        foreach (static::$connectionsStatsData as $connectionIndex => $connectionStatData) {
            $vpnConnection = $VPN_CONNECTIONS[$connectionIndex] ?? null;
            if (!$vpnConnection) {
                continue;
            }
            $hackApplication = $vpnConnection->getApplicationObject();
            if (!$hackApplication) {
                continue;
            }
            $openVpnConfig = $vpnConnection->getOpenVpnConfig();
            $vpnProvider = $openVpnConfig->getProvider();

            $stats = new stdClass();
            $stats->index = $connectionIndex;
            $stats->line = 'VPN' . $stats->index;
            $stats->country = $hackApplication->getCurrentCountry() ?: 'not detected';
            $stats->vpnProviderName = $vpnProvider->getName();
            $stats->ovpnFileSubPath = $openVpnConfig->getOvpnFileSubPath();

            $stats->receivedTraffic           = $connectionStatData->networkStats->session->received;
            $stats->transmittedTraffic        = $connectionStatData->networkStats->session->transmitted;
            $stats->receiveSpeed              = $connectionStatData->networkStats->session->receiveSpeed;
            $stats->applicationEfficiency     = $hackApplication->getEfficiencyLevel();
            $stats->applicationEfficiencyPcnt = $stats->applicationEfficiency ? $stats->applicationEfficiency . '' : '?';

            $stats->score = static::calculateScore($connectionStatData->networkStats, $stats->applicationEfficiency);
            $openVpnConfig->setCurrentSessionScorePoints($stats->score);

            $completeStatsData[] = $stats;
        }

        usort($completeStatsData, function ($l, $r) {
            if ($l->score === $r->score) {
                return 0;
            } else if ($l->score  >  $r->score) {
                return -1;
            } else {
                return 1;
            }
        });

        $statisticsBadge .= mbStrPad('> Connections chart <', $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";

        $rows[] = [];
        foreach ($completeStatsData as $stat) {
            $row = [
                $stat->line,
                $stat->country,
                $stat->vpnProviderName,
                $stat->ovpnFileSubPath,
                humanBytes($stat->transmittedTraffic, HUMAN_BYTES_SHORT),
                humanBytes($stat->receivedTraffic, HUMAN_BYTES_SHORT),
                humanBytes($stat->receiveSpeed, HUMAN_BYTES_BITS + HUMAN_BYTES_SHORT),
                $stat->applicationEfficiencyPcnt,
                $stat->score
            ];
            $rows[] = $row;
        }

        $columnsDefinition = [
            [
                'title' => ['Line'],
                'trim'   => 1,
                'width' => 8,
            ],
            [
                'title' => ['GeoIP', '(country)'],
                'width' => 19,
                'trim'   => 3,
            ],
            [
                'title' => ['Provider', '(folder name)'],
                'width' => 19,
                'trim'   => 3,
            ],
            [
                'title' => ['Config file'],
                'width' => 38,
                'trim'   => 4,
            ],
            [
                'title' => ['Traffic', 'transmit.', '(bytes)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['Traffic', 'received', '(bytes)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['Speed', 'received', '(bits/sec)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['HTTP res-', 'pon. rate', '(percents)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['Score', '', '(points)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ]
        ];
        $statisticsBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n";

        $statisticsBadge .=
            $VPN_CONNECTIONS_ESTABLISHED_COUNT    . ' connections were established, '
                        . count($VPN_CONNECTIONS) . " connection were effective\n\n";

        $statisticsBadge .= static::getTrafficMessage('Session network traffic', static::$previousSessionNetworkStats->received, static::$previousSessionNetworkStats->transmitted) . "\n";
        $statisticsBadge = Actions::doFilter('OpenVpnStatisticsSessionBadge', $statisticsBadge);
        $statisticsBadge .= "\n\n";


        //-----------------------------------------------------------------------------------

        if ($SESSIONS_COUNT > 1) {

            $statisticsBadge .= mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    TOTAL STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
            OpenVpnProvider::sortProvidersByScorePoints();

            $rows   = [];
            $rows[] = [];
            $totalUniqueIPsPool = [];
            foreach (OpenVpnProvider::$openVpnProviders as $vpnProvider) {
                $maxSimultaneousConnections = $vpnProvider->getMaxSimultaneousConnections();
                $maxSimultaneousConnections = $maxSimultaneousConnections === -1  ?  '∞' : $maxSimultaneousConnections;

                $uniqueIPsPool = $vpnProvider->getUniqueIPsPool();
                $uniqueIPsCount = count($uniqueIPsPool);
                $totalUniqueIPsPool = array_merge($totalUniqueIPsPool, $uniqueIPsPool);

                $successfulConnectionsCount = $vpnProvider->getSuccessfulConnectionsCount();
                $failedConnectionsCount     = $vpnProvider->getFailedConnectionsCount();

                if (!($successfulConnectionsCount + $failedConnectionsCount)) {
                    continue;
                }

                if (
                    $failedConnectionsCount > 0.30 * $successfulConnectionsCount
                    &&  ($successfulConnectionsCount + $failedConnectionsCount) > 10
                ) {
                    $failedConnectionsCount = Term::red . $failedConnectionsCount . Term::clear;
                }

                $row = [
                    $vpnProvider->getName(),
                    $maxSimultaneousConnections,
                    $successfulConnectionsCount,
                    $failedConnectionsCount,
                    $uniqueIPsCount,
                    $vpnProvider->getAverageScorePoints()
                ];
                $rows[] = $row;
            }

            $columnsDefinition = [
                [
                    'title' => ['Provider', '(folder name)'],
                    'width' => 25,
                    'trim'   => 3
                ],
                [
                    'title' => ['Simultaneous', 'conn. limit'],
                    'width' => 13,
                    'trim'   => 0,
                    'alignRight' => true
                ],
                [
                    'title' => ['Successful', 'connections'],
                    'width' => 13,
                    'trim'   => 0,
                    'alignRight' => true
                ],
                [
                    'title' => ['Failed', 'connections'],
                    'width' => 13,
                    'trim'   => 0,
                    'alignRight' => true
                ],
                [
                    'title' => ['Unique', 'IPs'],
                    'width' => 13,
                    'alignRight' => true
                ],
                [
                    'title' => ['Average', 'score'],
                    'width' => 13,
                    'alignRight' => true
                ]
            ];

            $lineLength = array_sum(array_column($columnsDefinition, 'width'));
            $statisticsBadge .= mbStrPad('> Providers statistics <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
            $statisticsBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";

            //-----------------------------------------------------------------------------------

            $rows   = [];
            $rows[] = [];
            foreach (OpenVpnProvider::$openVpnProviders as $vpnProvider) {
                foreach ($vpnProvider->getAllOpenVpnConfigs() as $openVpnConfig) {
                    $successfulConnectionsCount = $openVpnConfig->getSuccessfulConnectionsCount();
                    $failedConnectionsCount     = $openVpnConfig->getFailedConnectionsCount();

                    if (
                        $failedConnectionsCount >= 3
                        &&  $failedConnectionsCount > $successfulConnectionsCount * 2
                    ) {
                        $rows[] = [
                            $vpnProvider->getName(),
                            $openVpnConfig->getOvpnFileSubPath(),
                            $successfulConnectionsCount,
                            Term::red . $failedConnectionsCount . Term::clear
                        ];
                    }
                }
            }

            if (count($rows) > 1) {
                $columnsDefinition = [
                    [
                        'title' => ['Provider', '(folder name)'],
                        'width' => 25,
                        'trim'   => 3
                    ],
                    [
                        'title' => ['Config'],
                        'width' => 38,
                        'trim'   => 4,
                    ],
                    [
                        'title' => ['Successful', 'connections'],
                        'width' => 13,
                        'trim'   => 0,
                        'alignRight' => true
                    ],
                    [
                        'title' => ['Failed', 'connections'],
                        'width' => 13,
                        'trim'   => 0,
                        'alignRight' => true
                    ],
                ];
                $lineLength = array_sum(array_column($columnsDefinition, 'width'));
                $statisticsBadge .= mbStrPad('> Bad configs <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
                $statisticsBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";
            }

            //-----------------------------------------------------------------------------------

            $statisticsBadge .=  static::getTrafficMessage('Total network traffic', static::$totalNetworkStats->received, static::$totalNetworkStats->transmitted) . "\n";
            $statisticsBadge .= "Attacked during " . humanDuration(time() - $SCRIPT_STARTED_AT) .  ", from " . count($totalUniqueIPsPool) . " unique IP addresses\n";
            $statisticsBadge = Actions::doFilter('OpenVpnStatisticsBadge', $statisticsBadge);
        }

        //--------------------------------------------------------------

        return $statisticsBadge;
    }

    public static function getTrafficMessage($title, $rx, $tx)
    {
        return    "$title: " . humanBytes($rx + $tx)
                . '  (received:' . humanBytes($rx)
                . '/transmitted:'   . humanBytes($tx) . ')';
    }
}

OpenVpnStatistics::constructStatic();