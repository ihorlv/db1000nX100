<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
class EmailNotification
{
    private static string $pastSessionTotalBadge = '',
                          $targetsFileChangeMessage = '',
                          $scaleValueMessage;

    private static int $lastNotificationHour = -1;
    public static string $notificationMessage = '';

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function filterOpenVpnStatisticsTotalBadge($badge)
    {
        static::$pastSessionTotalBadge = trim($badge);
        return $badge;
    }

    public static function filterTargetsFileChangeMessage($targetsFileChangeMessage)
    {
        static::$targetsFileChangeMessage = $targetsFileChangeMessage;
        return $targetsFileChangeMessage;
    }

    public static function filterScaleValueMessage($message)
    {
        static::$scaleValueMessage = $message;
        return $message;
    }

    public static function actionAfterCalculateResources()
    {
        Actions::addFilter('OpenVpnStatisticsTotalBadge', [static::class, 'filterOpenVpnStatisticsTotalBadge'], 11);
        Actions::addFilter('TargetsFileChangeMessage', [static::class, 'filterTargetsFileChangeMessage']);
        Actions::addFilter('ScaleValueMessage', [static::class, 'filterScaleValueMessage']);
        Actions::addAction('AfterTerminateSession', [static::class, 'actionAfterTerminateSession'], 12);
        Actions::addAction('AfterTerminateFinalSession', [static::class, 'actionAfterTerminateSession'], 12);

        static::$lastNotificationHour = intval(date('G'));
    }
    public static function actionAfterTerminateSession()
    {
        global $SESSIONS_COUNT, $PAST_VPN_SESSION_DURATION;

        $usageValues = ResourcesConsumption::$pastSessionUsageValues;

        if (!static::$pastSessionTotalBadge || !$usageValues) {
            return;
        }

        // ---

        $lastSessionInfo = "Last #$SESSIONS_COUNT session duration: " . humanDuration($PAST_VPN_SESSION_DURATION) . "\n"
            . "Last session average CPU usage was: " . $usageValues['systemAverageCpuUsage']['current'] . "%\n"
            . "Last session average RAM usage was: " . $usageValues['systemAverageRamUsage']['current'] . "%\n";

        if (isset($usageValues['systemAverageNetworkUsageReceive'])) {
            $lastSessionInfo .= "Last session average Network usage was: " . humanBytes(NetworkConsumption::$trackingPeriodTransmitSpeed + NetworkConsumption::$trackingPeriodReceiveSpeed, HUMAN_BYTES_BITS) . "\n" .
                "  ↑ " . humanBytes(NetworkConsumption::$trackingPeriodTransmitSpeed, HUMAN_BYTES_BITS)  . ' of ' . humanBytes(NetworkConsumption::$transmitSpeedLimitBits, HUMAN_BYTES_BITS) . " (" . $usageValues['systemAverageNetworkUsageTransmit']['current'] . "%), " .
                '↓ ' . humanBytes(NetworkConsumption::$trackingPeriodReceiveSpeed, HUMAN_BYTES_BITS) . ' of ' . humanBytes(NetworkConsumption::$receiveSpeedLimitBits, HUMAN_BYTES_BITS) . ' (' . $usageValues['systemAverageNetworkUsageReceive']['current'] . "%)\n";
        }

        if (static::$targetsFileChangeMessage) {
            $lastSessionInfo .= static::$targetsFileChangeMessage . "\n";
            static::$targetsFileChangeMessage = '';
        }

        if (static::$scaleValueMessage) {
            $lastSessionInfo .=  static::$scaleValueMessage . "\n";
            static::$scaleValueMessage = '';
        }

        $badgeSplitTop = mbSeparateNLines(Term::removeMarkup(static::$pastSessionTotalBadge));
        static::$pastSessionTotalBadge = '';

        static::$notificationMessage = $lastSessionInfo . "\n" . trim($badgeSplitTop->nLines) . "\n" . $badgeSplitTop->restLines;

        // -----------------------

        global $EMAIL_NOTIFICATIONS_ENABLED,
               $EMAIL_NOTIFICATIONS_TO_ADDRESS,
               $EMAIL_NOTIFICATIONS_FROM_ADDRESS,
               $EMAIL_NOTIFICATIONS_AT_HOURS,
               $EMAIL_NOTIFICATIONS_SMTP_HOST,
               $EMAIL_NOTIFICATIONS_SMTP_PORT,
               $EMAIL_NOTIFICATIONS_SMTP_USERNAME,
               $EMAIL_NOTIFICATIONS_SMTP_PASSWORD,
               $EMAIL_NOTIFICATIONS_SMTP_ENCRYPTION,
               $EMAIL_NOTIFICATIONS_SEND_DEBUG,
               $X100_INSTANCE_TITLE;

        if (
            !$EMAIL_NOTIFICATIONS_ENABLED
            || !count($EMAIL_NOTIFICATIONS_AT_HOURS)
        ) {
            return;
        }

        // ---

        $currentHour = intval(date('G'));

        if (!(
            static::$lastNotificationHour !== $currentHour
            &&  in_array($currentHour, $EMAIL_NOTIFICATIONS_AT_HOURS)
        )) {
            return;
        }

        static::$lastNotificationHour = $currentHour;

        // -----------------------

        /*
        echo "\$EMAIL_NOTIFICATIONS_ENABLED $EMAIL_NOTIFICATIONS_ENABLED\n";
        echo "\$EMAIL_NOTIFICATIONS_TO_ADDRESS $EMAIL_NOTIFICATIONS_TO_ADDRESS\n";
        echo "\$EMAIL_NOTIFICATIONS_FROM_ADDRESS $EMAIL_NOTIFICATIONS_FROM_ADDRESS\n";
        echo "\$EMAIL_NOTIFICATIONS_AT_HOURS " . print_r($EMAIL_NOTIFICATIONS_AT_HOURS, true) . "\n";
        echo "\$EMAIL_NOTIFICATIONS_SMTP_HOST $EMAIL_NOTIFICATIONS_SMTP_HOST\n";
        echo "\$EMAIL_NOTIFICATIONS_SMTP_PORT $EMAIL_NOTIFICATIONS_SMTP_PORT\n";
        echo "\$EMAIL_NOTIFICATIONS_SMTP_USERNAME $EMAIL_NOTIFICATIONS_SMTP_USERNAME\n";
        echo "\$EMAIL_NOTIFICATIONS_SMTP_PASSWORD $EMAIL_NOTIFICATIONS_SMTP_PASSWORD\n";
        echo "\$EMAIL_NOTIFICATIONS_SMTP_ENCRYPTION $EMAIL_NOTIFICATIONS_SMTP_ENCRYPTION\n";
        echo "\$EMAIL_NOTIFICATIONS_SEND_DEBUG $EMAIL_NOTIFICATIONS_SEND_DEBUG\n";
        */

        if (
            !$EMAIL_NOTIFICATIONS_TO_ADDRESS
            || !$EMAIL_NOTIFICATIONS_FROM_ADDRESS
            || !$EMAIL_NOTIFICATIONS_SMTP_HOST
            || !$EMAIL_NOTIFICATIONS_SMTP_PORT
        ) {
            MainLog::log('Incomplete credentials to send Email Notifications', 1, 1, MainLog::LOG_GENERAL_ERROR);
        }

        $pm = new PHPMailer(true);

        try {
            if ($EMAIL_NOTIFICATIONS_SEND_DEBUG) {
                $pm->SMTPDebug = SMTP::DEBUG_CONNECTION;
                MainLog::log("Email Notification Send debug:" , 1, 0, MainLog::LOG_DEBUG);
                $pm->Debugoutput = function($str, $level) {
                    MainLog::log(trim($str), 1, 0, MainLog::LOG_DEBUG);
                };
            }

            $pm->isSMTP();
            $pm->charSet = 'UTF-8';
            $pm->Encoding = 'base64';

            $pm->addAddress($EMAIL_NOTIFICATIONS_TO_ADDRESS);
            $pm->setFrom($EMAIL_NOTIFICATIONS_FROM_ADDRESS, 'X100');

            $pm->Host = $EMAIL_NOTIFICATIONS_SMTP_HOST;
            $pm->Port = $EMAIL_NOTIFICATIONS_SMTP_PORT;

            if ($EMAIL_NOTIFICATIONS_SMTP_USERNAME) {
                $pm->SMTPAuth = true;
                $pm->Username = $EMAIL_NOTIFICATIONS_SMTP_USERNAME;
            }

            if ($EMAIL_NOTIFICATIONS_SMTP_PASSWORD) {
                $pm->SMTPAuth = true;
                $pm->Password = $EMAIL_NOTIFICATIONS_SMTP_PASSWORD;
            }

            if ($EMAIL_NOTIFICATIONS_SMTP_ENCRYPTION) {
                $pm->SMTPSecure = $EMAIL_NOTIFICATIONS_SMTP_ENCRYPTION;
            }

            $pm->Subject = "$currentHour  $X100_INSTANCE_TITLE";

            $body = static::$notificationMessage;
            $body = str_replace('↑', 'upload', $body);
            $body = str_replace('↓', 'download', $body);
            $pm->Body = $body;

            $pm->send();

        } catch (Exception $e) {
            MainLog::log("Email Notifications could not be sent. Mailer Error: \n" . $pm->ErrorInfo , 1, 1, MainLog::LOG_GENERAL_ERROR);
        }
    }

}

EmailNotification::constructStatic();