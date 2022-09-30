<?php

class OpenVpnStatistics
{
    public static object $pastSessionNetworkStats,
                         $totalNetworkStats;

    private static string $statisticsBadge = '';

    public static function constructStatic()
    {
        static::$pastSessionNetworkStats = new stdClass();
        static::$pastSessionNetworkStats->received = 0;
        static::$pastSessionNetworkStats->transmitted = 0;
        static::$pastSessionNetworkStats->receiveSpeed = 0;
        static::$pastSessionNetworkStats->transmitSpeed = 0;

        static::$totalNetworkStats = new stdClass();
        static::$totalNetworkStats->received = 0;
        static::$totalNetworkStats->transmitted = 0;
        static::$totalNetworkStats->receiveSpeed = 0;
        static::$totalNetworkStats->transmitSpeed = 0;

        Actions::addAction('TerminateSession',           [static::class, 'actionTerminateSession'], 13);
        Actions::addAction('TerminateFinalSession',      [static::class, 'actionTerminateSession'], 13);

        Actions::addAction('AfterTerminateSession',      [static::class, 'actionAfterTerminateSession'], 11);
        Actions::addAction('AfterTerminateFinalSession', [static::class, 'actionAfterTerminateSession'], 11);
    }

    public static function actionTerminateSession()
    {
        global $SCRIPT_STARTED_AT, $VPN_SESSION_STARTED_AT;

        static::$pastSessionNetworkStats->received = 0;
        static::$pastSessionNetworkStats->transmitted = 0;
        static::$pastSessionNetworkStats->receiveSpeed = 0;
        static::$pastSessionNetworkStats->transmitSpeed = 0;

        $openVpnConnections = OpenVpnConnectionStatic::getInstances();
        foreach ($openVpnConnections as $vpnConnection) {
            if (!is_object($vpnConnection)) {
                continue;
            }
            $networkStats = $vpnConnection->calculateNetworkStats();
            static::$pastSessionNetworkStats->received    += $networkStats->session->received;
            static::$pastSessionNetworkStats->transmitted += $networkStats->session->transmitted;
        }

        $sessionDuration = time() - $VPN_SESSION_STARTED_AT;
        if ($sessionDuration) {
            static::$pastSessionNetworkStats->receiveSpeed =  intRound(static::$pastSessionNetworkStats->received    / $sessionDuration * 8 );
            static::$pastSessionNetworkStats->transmitSpeed = intRound(static::$pastSessionNetworkStats->transmitted / $sessionDuration * 8 );
        }

        //MainLog::log(print_r([static::$pastSessionNetworkStats, $sessionDuration], true));

        // ---

        $totalDuration = time() - $SCRIPT_STARTED_AT;
        static::$totalNetworkStats->received     += static::$pastSessionNetworkStats->received;
        static::$totalNetworkStats->transmitted  += static::$pastSessionNetworkStats->transmitted;
        static::$totalNetworkStats->receiveSpeed  = intRound(static::$totalNetworkStats->received    / $totalDuration * 8 );
        static::$totalNetworkStats->transmitSpeed = intRound(static::$totalNetworkStats->transmitted / $totalDuration * 8 );

        static::$statisticsBadge = static::generateBadge();
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
        foreach (OpenVpnConnectionStatic::getInstances() as $connectionIndex => $vpnConnection) {
            if (!is_object($vpnConnection)) {
                continue;
            }
            $hackApplication = $vpnConnection->getApplicationObject();
            if (!is_object($hackApplication)) {
                continue;
            }
            $openVpnConfig = $vpnConnection->getOpenVpnConfig();
            $vpnProvider = $openVpnConfig->getProvider();

            $stats = new stdClass();
            $stats->index = $connectionIndex;
            $stats->line = 'VPN' . $stats->index;
            $stats->country = $vpnConnection->getCurrentCountry() ?: 'not detected';
            $stats->vpnProviderName = $vpnProvider->getName();
            $stats->ovpnFileSubPath = $openVpnConfig->getOvpnFileSubPath();

            $networkStats = $vpnConnection->calculateNetworkStats();
            $stats->receivedTraffic           = $networkStats->session->received;
            $stats->transmittedTraffic        = $networkStats->session->transmitted;
            $stats->receiveSpeed              = $networkStats->session->receiveSpeed;
            $stats->applicationEfficiency     = $hackApplication->getEfficiencyLevel();
            $stats->applicationEfficiencyPcnt = $stats->applicationEfficiency ? $stats->applicationEfficiency . '' : '?';

            $stats->score = static::calculateScore($networkStats, $stats->applicationEfficiency);
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

        $statisticsBadge .= getHumanBytesLabel('Session network traffic', static::$pastSessionNetworkStats->received, static::$pastSessionNetworkStats->transmitted) . "\n";
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

            $statisticsBadge .= "Attacked during " . humanDuration(time() - $SCRIPT_STARTED_AT) .  ", from " . count($totalUniqueIPsPool) . " unique IP addresses\n";
            $statisticsBadge .=  getHumanBytesLabel('Total network traffic', static::$totalNetworkStats->received, static::$totalNetworkStats->transmitted) . "\n";
            $statisticsBadge .=  getHumanBytesLabel('Average network utilization', static::$totalNetworkStats->receiveSpeed, static::$totalNetworkStats->transmitSpeed, HUMAN_BYTES_BITS) . "\n";

            $statisticsBadge = Actions::doFilter('OpenVpnStatisticsBadge', $statisticsBadge);
        }

        //--------------------------------------------------------------

        return $statisticsBadge;
    }

    public static function actionAfterTerminateSession()
    {
        MainLog::log(static::$statisticsBadge);
        MainLog::log('', 2);
    }
}

OpenVpnStatistics::constructStatic();