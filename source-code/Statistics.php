<?php

class Statistics
{
    public static function generateBadge()
    {
        global $VPN_CONNECTIONS, $VPN_CONNECTIONS_ESTABLISHED_COUNT, $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT,
               $LOG_BADGE_WIDTH, $LOG_PADDING_LEFT, $SESSIONS_COUNT, $SCRIPT_STARTED_AT;

        if (! $VPN_CONNECTIONS_ESTABLISHED_COUNT  ||  !count($VPN_CONNECTIONS)) {
            return;
        }

        $statisticBadge = str_repeat(' ', $LOG_BADGE_WIDTH + 1 + $LOG_PADDING_LEFT) . Term::bgBrightBlue . Term::brightYellow . '    SESSION STATISTICS    ' . Term::clear . "\n\n";
        $statisticBadge .= "Session #$SESSIONS_COUNT\n";
        //--------------------------------------------------------------------------

        $connectionsStatistics = [];
        foreach ($VPN_CONNECTIONS as $vpnConnection) {
            $stat = new stdClass();
            $hackApplication = $vpnConnection->getApplicationObject();
            $openVpnConfig = $vpnConnection->getOpenVpnConfig();
            $vpnProvider = $openVpnConfig->getProvider();
            $scoreBlock = $vpnConnection->getScoreBlock();

            $stat->index = $vpnConnection->getIndex();
            $stat->line = 'VPN' . $stat->index;
            $stat->country = $hackApplication->getCurrentCountry() ?: 'not detected';
            $stat->vpnProviderName = $vpnProvider->getName();
            $stat->ovpnFileBasename = $openVpnConfig->getOvpnFileBasename();
            $stat->receivedTraffic = $scoreBlock->trafficReceived;
            $stat->transmittedTraffic = $scoreBlock->trafficTransmitted;
            $stat->responseRate = $scoreBlock->efficiencyLevel;
            $stat->responseRatePcnt = $stat->responseRate ? $stat->responseRate . '%' : null;
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

        $statisticBadge .= "Connections chart:\n\n";

        $rows[] = [];
        foreach ($connectionsStatistics as $stat) {
            $row = [
                $stat->line,
                $stat->country,
                $stat->vpnProviderName,
                $stat->ovpnFileBasename,
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
                'title' => ['Provider'],
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
        $statisticBadge .= generateMonospaceTable($columnsDefinition, $rows);

        $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT = count($VPN_CONNECTIONS);
        $statisticBadge .=
            "\n" . $VPN_CONNECTIONS_ESTABLISHED_COUNT . ' connections were established, ' .
            $VPN_CONNECTIONS_WERE_EFFECTIVE_COUNT . " connection were effective\n\n";


        // This should be called after all $vpnConnection->getNetworkTrafficStat()
        $sessionTrafficReceived = array_sum(OpenVpnConnection::$devicesReceived);
        $sessionTrafficTransmitted = array_sum(OpenVpnConnection::$devicesTransmitted);
        $totalTrafficReceived = OpenVpnConnection::$previousSessionsReceived + $sessionTrafficReceived;
        $totalTrafficTransmitted = OpenVpnConnection::$previousSessionsTransmitted + $sessionTrafficTransmitted;

        $statisticBadge .= static::getTrafficMessage('Session network traffic', $sessionTrafficReceived, $sessionTrafficTransmitted) . "\n\n\n";

        //-----------------------------------------------------------------------------------

        $statisticBadge  .= str_repeat(' ', $LOG_BADGE_WIDTH + 1 + $LOG_PADDING_LEFT) . Term::bgBrightBlue . Term::brightYellow . '    TOTAL STATISTICS    ' . Term::clear . "\n\n";
        $statisticBadge .= "Providers statistics:\n\n";
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

            $row = [
                $vpnProvider->getName(),
                $maxSimultaneousConnections,
                $vpnProvider->getSuccessfulConnectionsCount(),
                $vpnProvider->getFailedConnectionsCount(),
                $uniqueIPsCount,
                $vpnProvider->getAverageScorePoints()
            ];
            $rows[] = $row;
        }

        $columnsDefinition = [
            [
                'title' => ['Provider'],
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
        $statisticBadge .= generateMonospaceTable($columnsDefinition, $rows);
        $statisticBadge .= "\n" . static::getTrafficMessage('Total network traffic', $totalTrafficReceived, $totalTrafficTransmitted) . "\n";
        $statisticBadge .= "Attacked during " . humanDuration(time() - $SCRIPT_STARTED_AT) .  "from " . count($totalUniqueIPsPool) . " unique IP addresses\n";

        return $statisticBadge;
    }

    private static function getTrafficMessage($title, $rx, $tx)
    {
        return    "$title: " . humanBytes($rx + $tx)
                . '  (rx:' . humanBytes($rx)
                . '/tx:'   . humanBytes($tx) . ')';
    }
}