<?php

class OpenVpnStatistics
{
    private static string $statisticsBadge = '';

    public static function constructStatic()
    {
        Actions::addAction('TerminateSession',           [static::class, 'actionTerminateSession'], 13);
        Actions::addAction('TerminateFinalSession',      [static::class, 'actionTerminateSession'], 13);

        Actions::addAction('AfterTerminateSession',      [static::class, 'actionAfterTerminateSession'], 11);
        Actions::addAction('AfterTerminateFinalSession', [static::class, 'actionAfterTerminateSession'], 11);
    }

    public static function actionTerminateSession()
    {
        static::$statisticsBadge = static::generateBadge();
    }

    private static function calculateScore($networkStats, $applicationEfficiency) : int
    {
        $ret = roundLarge($networkStats->session->sumSpeed / 8 / 1024 );

        if ($applicationEfficiency > 5) {
            $ret *= $applicationEfficiency / 5;
        }

        return intRound($ret);
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
            $stats->sumSpeed                  = $networkStats->session->sumSpeed;
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
                humanBytes($stat->sumSpeed, HUMAN_BYTES_BITS + HUMAN_BYTES_SHORT),
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
                'title' => ['Speed', 'sum', '(bits/sec)'],
                'width' => 11,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['Response', ' rate', '(percents)'],
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

        // ---

        $vpnInstancesNetworkTotals = OpenVpnConnectionStatic::getInstancesNetworkTotals();
        $pastSessionNetworkStats = $vpnInstancesNetworkTotals->session;

        $statisticsBadge .= getHumanBytesLabel('Session network traffic: ', $pastSessionNetworkStats->received, $pastSessionNetworkStats->transmitted) . "\n";
        $statisticsBadge = Actions::doFilter('OpenVpnStatisticsSessionBadge', $statisticsBadge);
        $statisticsBadge .= "\n\n";


        //-----------------------------------------------------------------------------------

        if ($SESSIONS_COUNT > 1) {

            $totalBadge = mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    TOTAL STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
            OpenVpnProvider::sortProvidersByScorePoints();

            $rows   = [];
            $rows[] = [];
            $totalUniqueIPsPool = [];
            foreach (OpenVpnProvider::$openVpnProviders as $vpnProvider) {
                $maxSimultaneousConnections = $vpnProvider->getMaxSimultaneousConnections();
                $maxSimultaneousConnections = $maxSimultaneousConnections === -1  ?  '∞' : $maxSimultaneousConnections;

                $uniqueIPsPool = $vpnProvider->getUniqueIPsPool();
                $uniqueIPsCount = count($uniqueIPsPool);
                $totalUniqueIPsPool = array_unique(array_merge($totalUniqueIPsPool, $uniqueIPsPool));

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
            $totalBadge .= mbStrPad('> Providers statistics <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
            $totalBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";

            //-----------------------------------------------------------------------------------

            $rows   = [];
            $rows[] = [];
            foreach (OpenVpnProvider::$openVpnProviders as $vpnProvider) {
                foreach ($vpnProvider->getAllOpenVpnConfigs() as $openVpnConfig) {
                    $successfulConnectionsCount = $openVpnConfig->getSuccessfulConnectionsCount();
                    $failedConnectionsCount     = $openVpnConfig->getFailedConnectionsCount();
                    $isBadConfig                = $openVpnConfig->isBadConfig();

                    if ($isBadConfig) {
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
                $totalBadge .= mbStrPad('> Bad configs <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
                $totalBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";
            }

            //-----------------------------------------------------------------------------------

            $scriptRunDuration = time() - $SCRIPT_STARTED_AT;
            $totalBadge .= "Attacked during " . humanDuration($scriptRunDuration) .  ", through " . count($totalUniqueIPsPool) . " unique VPN IP addresses\n";

            // ---

            $totalBadge = Actions::doFilter('OpenVpnStatisticsTotalBadge', $totalBadge);
            $statisticsBadge .= $totalBadge;
        }

        //--------------------------------------------------------------

        $statisticsBadge = Actions::doFilter('OpenVpnStatisticsBadge', $statisticsBadge);

        return $statisticsBadge;
    }

    public static function actionAfterTerminateSession()
    {
        MainLog::log(static::$statisticsBadge);
        MainLog::log('', 2);
        static::$statisticsBadge = '';
    }
}

OpenVpnStatistics::constructStatic();