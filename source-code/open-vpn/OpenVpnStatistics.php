<?php

class OpenVpnStatistics
{
    public static function generateBadge()
    {
        global $VPN_CONNECTIONS, $VPN_CONNECTIONS_ESTABLISHED_COUNT, $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT,
               $LONG_LINE_WIDTH,
               $SESSIONS_COUNT, $SCRIPT_STARTED_AT;

        if (! Efficiency::wereValuesReceivedFromAllConnection()) {
            return;
        }

        $statisticBadge  = mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    SESSION STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
        $statisticBadge .= "Session #$SESSIONS_COUNT\n";

        //--------------------------------------------------------------------------

        $connectionsStatistics = [];
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $stat = new stdClass();
            $hackApplication = $vpnConnection->getApplicationObject();
            if (! $hackApplication) {
                continue;
            }
            $scoreBlock = $vpnConnection->getScoreBlock();
            $openVpnConfig = $vpnConnection->getOpenVpnConfig();
            $vpnProvider = $openVpnConfig->getProvider();

            $stat->index = $vpnConnection->getIndex();
            $stat->line = 'VPN' . $stat->index;
            $stat->country = $hackApplication->getCurrentCountry() ?: 'not detected';
            $stat->vpnProviderName = $vpnProvider->getName();
            $stat->ovpnFileSubPath = $openVpnConfig->getOvpnFileSubPath();
            $stat->receivedTraffic = $scoreBlock->trafficReceived;
            $stat->transmittedTraffic = $scoreBlock->trafficTransmitted;
            $stat->responseRate = $scoreBlock->efficiencyLevel;
            $stat->responseRatePcnt = $stat->responseRate ? $stat->responseRate . '%' : '?';
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

        $statisticBadge .= mbStrPad('> Connections chart <', $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";

        $rows[] = [];
        foreach ($connectionsStatistics as $stat) {
            $row = [
                $stat->line,
                $stat->country,
                $stat->vpnProviderName,
                $stat->ovpnFileSubPath,
                humanBytes($stat->transmittedTraffic),
                humanBytes($stat->receivedTraffic),
                $stat->responseRatePcnt,
                $stat->score
            ];
            $rows[] = $row;
        }

        $columnsDefinition = [
            [
                'title' => ['Line'],
                'width' => 10,
            ],
            [
                'title' => ['GeoIP'],
                'width' => 20,
                'trim'   => 3,
            ],
            [
                'title' => ['Provider', '(folder name)'],
                'width' => 20,
                'trim'   => 3,
            ],
            [
                'title' => ['Config'],
                'width' => 41,
                'trim'   => 4,
            ],
            [
                'title' => ['Traffic', 'transmitted'],
                'width' => 12,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['Traffic', 'received'],
                'width' => 12,
                'trim'   => 1,
                'alignRight' => true
            ],
            [
                'title' => ['HTTP res-', 'ponse rate'],
                'width' => 12,
                'alignRight' => true
            ],
            [
                'title' => ['Score'],
                'width' => 12,
                'alignRight' => true
            ]
        ];
        $statisticBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n";

        $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT = count($VPN_CONNECTIONS);
        $statisticBadge .=
            $VPN_CONNECTIONS_ESTABLISHED_COUNT    . ' connections were established, ' .
            $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT . " connection were effective\n\n";


        // This should be called after all $vpnConnection->getNetworkTrafficStat()
        $sessionTrafficReceived = array_sum(OpenVpnConnection::$devicesReceived);
        $sessionTrafficTransmitted = array_sum(OpenVpnConnection::$devicesTransmitted);
        $totalTrafficReceived = OpenVpnConnection::$previousSessionsReceived + $sessionTrafficReceived;
        $totalTrafficTransmitted = OpenVpnConnection::$previousSessionsTransmitted + $sessionTrafficTransmitted;

        $statisticBadge .= static::getTrafficMessage('Session network traffic', $sessionTrafficReceived, $sessionTrafficTransmitted) . "\n\n\n";

        //-----------------------------------------------------------------------------------

        $statisticBadge .= mbStrPad(Term::bgUkraineBlue . Term::ukraineYellow . '    TOTAL STATISTICS    ' . Term::clear, $LONG_LINE_WIDTH, ' ', STR_PAD_BOTH) . "\n\n";
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
        $statisticBadge .= mbStrPad('> Providers statistics <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
        $statisticBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";

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
                        Term::red .$openVpnConfig->getOvpnFileSubPath() . Term::clear,
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
                    'width' => 39,
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
            $statisticBadge .= mbStrPad('> Bad configs <', $lineLength, ' ', STR_PAD_BOTH) . "\n\n";
            $statisticBadge .= generateMonospaceTable($columnsDefinition, $rows) . "\n\n";
        }

        //-----------------------------------------------------------------------------------

        $statisticBadge .=  static::getTrafficMessage('Total network traffic', $totalTrafficReceived, $totalTrafficTransmitted) . "\n";
        $statisticBadge .= "Attacked during " . humanDuration(time() - $SCRIPT_STARTED_AT) .  ", from " . count($totalUniqueIPsPool) . " unique IP addresses\n";


        return $statisticBadge;
    }

    private static function getTrafficMessage($title, $rx, $tx)
    {
        return    "$title: " . humanBytes($rx + $tx)
                . '  (received:' . humanBytes($rx)
                . '/transmitted:'   . humanBytes($tx) . ')';
    }
}