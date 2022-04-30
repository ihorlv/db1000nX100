<?php

class Statistic
{
    public static int $sessionTraffic,
                      $sessionTrafficReceived,
                      $sessionTrafficTransmitted,
                      $totalTraffic,
                      $totalTrafficReceived,
                      $totalTrafficTransmitted;


    public static function show()
    {
        global $VPN_CONNECTIONS, $VPN_CONNECTIONS_ESTABLISHED_COUNT;

        static::$sessionTrafficReceived = array_sum(OpenVpnConnection::$devicesReceived);
        static::$sessionTrafficTransmitted = array_sum(OpenVpnConnection::$devicesTransmitted);
        static::$sessionTraffic = static::$sessionTrafficReceived + static::$sessionTrafficTransmitted;

        static::$totalTrafficReceived = OpenVpnConnection::$previousSessionsReceived + static::$sessionTrafficReceived;
        static::$totalTrafficTransmitted = OpenVpnConnection::$previousSessionsTransmitted + static::$sessionTrafficTransmitted;
        static::$totalTraffic = static::$totalTrafficReceived + static::$totalTrafficTransmitted;

        if (! $VPN_CONNECTIONS_ESTABLISHED_COUNT  ||  !count($VPN_CONNECTIONS)) {
            return;
        }

        MainLog::log(Term::bgBrightBlue . Term::brightYellow . '  GLORY TO UKRAINE  ' . Term::clear, MainLog::LOG_GENERAL_STATISTIC, 2);

        //--------------------------------------------------------------------------
        $vpnConnectionsChart = $VPN_CONNECTIONS;
        usort($vpnConnectionsChart, function ($l, $r) {
            $lApplication = $l->getApplicationObject();
            $lResponseRate = $lApplication->getEfficiencyLevel()  ??  0;
            $lTrafficStat = $l->getNetworkTrafficStat();
            $lCompare = $lTrafficStat->received * $lResponseRate;

            $rApplication = $r->getApplicationObject();
            $rResponseRate = $rApplication->getEfficiencyLevel()  ??  0;
            $rTrafficStat = $r->getNetworkTrafficStat();
            $rCompare = $rTrafficStat->received * $rResponseRate;

            if ($lCompare === $rCompare) {
                return 0;
            } else if ($lCompare  >  $rCompare) {
                return -1;
            } else {
                return 1;
            }
        });

        static::showTrafficMessage('Session network traffic', static::$sessionTrafficReceived, static::$sessionTrafficTransmitted, 1, 2);
        MainLog::log('Connections chart:', MainLog::LOG_GENERAL_STATISTIC);
        MainLog::log(
              str_pad('Id',       10)
            . str_pad('GeoIp',    20)
            . str_pad('Provider', 20)
            . str_pad('Config',   40)
            . str_pad('Received', 10, ' ',STR_PAD_LEFT)
            . str_pad('R.rate',   10, ' ',STR_PAD_LEFT)
            . str_pad('Score',    10, ' ',STR_PAD_LEFT),
            MainLog::LOG_GENERAL_STATISTIC, 2);


        foreach ($vpnConnectionsChart as $connection) {
            $hackApplication = $connection->getApplicationObject();
            if (! $hackApplication) {
                continue;
            }
            $responseRate = $hackApplication->getEfficiencyLevel();
            if (!$responseRate) {
                continue;
            }
            $id = 'VPN' . $connection->getIndex();
            $country = $hackApplication->getCurrentCountry();
            $country = $country  ?: 'not detected';
            $provider         = $connection->getOpenVpnConfig()->getProvider()->getName();
            $ovpnFileBasename = $connection->getOpenVpnConfig()->getOvpnFileBasename();
            $connectionNetworkTrafficStat = $connection->getNetworkTrafficStat();
            $receivedTraffic = humanBytes($connectionNetworkTrafficStat->received);
            $score = (int) round($connectionNetworkTrafficStat->received / 1024 / 1024   * $responseRate);

            MainLog::log(
                  cutAndPad($id,                   8, 10)
                . cutAndPad($country,             18, 20)
                . cutAndPad($provider,            18, 20)
                . cutAndPad($ovpnFileBasename,    38, 40)
                . cutAndPad($receivedTraffic,      8, 10, true)
                . cutAndPad($responseRate . '%',   8, 10, true)
                . cutAndPad($score,                8, 10, true),
            MainLog::LOG_GENERAL_STATISTIC);
        }

        MainLog::log(
            $VPN_CONNECTIONS_ESTABLISHED_COUNT . ' connections were established, ' .
            count($vpnConnectionsChart) . ' connection were effective',
            MainLog::LOG_GENERAL_STATISTIC);

        //--------------------------------------------------------------------------

        static::showTrafficMessage('Total network traffic  ', static::$totalTrafficReceived, static::$totalTrafficTransmitted, 2);
    }

    private static function showTrafficMessage($title, $rx, $tx, $newLinesInTheEnd = 1, $newLinesInTheBeginning = 0)
    {
        MainLog::log(
                "$title: " . humanBytes($rx + $tx)
                . '  (rx:' . humanBytes($rx)
                . '/tx:'   . humanBytes($tx) . ')',
            MainLog::LOG_GENERAL_STATISTIC,
            $newLinesInTheEnd,
            $newLinesInTheBeginning
        );
    }
}