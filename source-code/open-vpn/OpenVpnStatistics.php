<?php

class OpenVpnStatistics
{
    public static function generateBadge()
    {
        global $VPN_CONNECTIONS, $VPN_CONNECTIONS_ESTABLISHED_COUNT,
               $LONG_LINE_WIDTH,
               $SESSIONS_COUNT, $SCRIPT_STARTED_AT;

        $statisticsBadge = '';

        OpenVpnConnection::recalculateSessionTraffic();
        $sessionTrafficReceived = array_sum(OpenVpnConnection::$devicesReceived);
        $sessionTrafficTransmitted = array_sum(OpenVpnConnection::$devicesTransmitted);
        $totalTrafficReceived = OpenVpnConnection::$previousSessionsReceived + $sessionTrafficReceived;
        $totalTrafficTransmitted = OpenVpnConnection::$previousSessionsTransmitted + $sessionTrafficTransmitted;

        if (Efficiency::wereValuesReceivedFromAllConnection()) {

            $statisticsBadge .=  mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    SESSION STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
            $statisticsBadge .= "Session #$SESSIONS_COUNT\n";

            //--------------------------------------------------------------------------

            $connectionsStatistics = [];
            foreach ($VPN_CONNECTIONS as $vpnConnection) {
                $stat = new stdClass();
                $hackApplication = $vpnConnection->getApplicationObject();
                $scoreBlock = $vpnConnection->getScoreBlock();
                if (!$hackApplication  ||  !$scoreBlock) {
                    continue;
                }
                $openVpnConfig = $vpnConnection->getOpenVpnConfig();
                $vpnProvider = $openVpnConfig->getProvider();

                $stat->index = $vpnConnection->getIndex();
                $stat->line = 'VPN' . $stat->index;
                $stat->country = $hackApplication->getCurrentCountry() ?: 'not detected';
                $stat->vpnProviderName = $vpnProvider->getName();
                $stat->ovpnFileSubPath = $openVpnConfig->getOvpnFileSubPath();
                $stat->receivedTraffic = $scoreBlock->trafficStat->received;
                $stat->transmittedTraffic = $scoreBlock->trafficStat->transmitted;
                $stat->receiveSpeed = $scoreBlock->trafficStat->receiveSpeed;
                $stat->responseRate = $scoreBlock->efficiencyLevel;
                $stat->responseRatePcnt = $stat->responseRate ? $stat->responseRate . '' : '?';
                $stat->score = $scoreBlock->score;

                $connectionsStatistics[] = $stat;
            }

            usort($connectionsStatistics, function ($l, $r) {
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
            foreach ($connectionsStatistics as $stat) {
                $row = [
                    $stat->line,
                    $stat->country,
                    $stat->vpnProviderName,
                    $stat->ovpnFileSubPath,
                    humanBytes($stat->transmittedTraffic, HUMAN_BYTES_SHORT),
                    humanBytes($stat->receivedTraffic, HUMAN_BYTES_SHORT),
                    humanBytes($stat->receiveSpeed, HUMAN_BYTES_BITS + HUMAN_BYTES_SHORT),
                    $stat->responseRatePcnt,
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

            $statisticsBadge .= static::getTrafficMessage('Session network traffic', $sessionTrafficReceived, $sessionTrafficTransmitted) . "\n\n\n";
        }

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

            $statisticsBadge .=  static::getTrafficMessage('Total network traffic', $totalTrafficReceived, $totalTrafficTransmitted) . "\n";
            $statisticsBadge .= "Attacked during " . humanDuration(time() - $SCRIPT_STARTED_AT) .  ", from " . count($totalUniqueIPsPool) . " unique IP addresses\n";
        }

        // ----------- write results to statistics log file  -----------

        /*if ($statisticsBadge) {
            $statisticsFileContent = 'Collected at ' . date('Y-m-d H:i:s') . "\n\n";
            $statisticsFileContent .= Term::removeMarkup($statisticsBadge);
            MainLog::writeStatistics($statisticsFileContent);
        }*/

        //--------------------------------------------------------------

        return $statisticsBadge;
    }

    private static function getTrafficMessage($title, $rx, $tx)
    {
        return    "$title: " . humanBytes($rx + $tx)
                . '  (received:' . humanBytes($rx)
                . '/transmitted:'   . humanBytes($tx) . ')';
    }
}