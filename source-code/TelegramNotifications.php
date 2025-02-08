<?php

//  https://api.telegram.org/bot6160435771:AAEc6iG6eSguWLe8VO9alfYPymsFP9J3foE/getUpdates?offset=-1&limit=1

class TelegramNotifications extends SFunctions
{
    private static int $lastNotificationHour = -1;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        Actions::addAction('AfterTerminateSession', [static::class, 'actionAfterTerminateSession'], 13);
        Actions::addAction('AfterTerminateFinalSession', [static::class, 'actionAfterTerminateSession'], 13);

        static::$lastNotificationHour = intval(date('G'));
    }

    public static function actionAfterTerminateSession()
    {
        global $X100_INSTANCE_TITLE, $TEMP_DIR,
               $TELEGRAM_NOTIFICATIONS_ENABLED, $TELEGRAM_NOTIFICATIONS_TO_USER_ID, $TELEGRAM_NOTIFICATIONS_AT_HOURS,
               $TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES, $TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES;

        if (
            !$TELEGRAM_NOTIFICATIONS_ENABLED
            || !$TELEGRAM_NOTIFICATIONS_TO_USER_ID
            || !count($TELEGRAM_NOTIFICATIONS_AT_HOURS)
            || !EmailNotification::$notificationMessage
        ) {
            return;
        }

        // ---

        $currentHour = intval(date('G'));

        if (!(
            static::$lastNotificationHour !== $currentHour
            &&  in_array($currentHour, $TELEGRAM_NOTIFICATIONS_AT_HOURS)
        )) {
            return;
        }

        static::$lastNotificationHour = $currentHour;

        // ---

        $title = mbStrPad(" $currentHour ─── $X100_INSTANCE_TITLE ───────", 60, '─', STR_PAD_BOTH);

        /*$sessionTotalBadge = Term::removeMarkup(static::$pastSessionTotalBadge);
        static::$pastSessionTotalBadge = '';
        $badgeSplitTop = mbSeparateNLines($sessionTotalBadge);
        $message = trim($badgeSplitTop->nLines) . "\n" . $badgeSplitTop->restLines;


        // ---

        $usageValues = ResourcesConsumption::$pastSessionUsageValues;
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
        }

        if (static::$scaleValueMessage) {
            $lastSessionInfo .=  static::$scaleValueMessage . "\n";
        }

        $message = $lastSessionInfo . "\n" . $message;*/

        // ---

        if ($TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES) {
            $messageFilePath = $TEMP_DIR . '/telegram.html';
            $messageHtml = static::messageToHtmlDocument(EmailNotification::$notificationMessage, $title);
            file_put_contents_secure($messageFilePath, $messageHtml);

            $r = static::sendFileToUser($TELEGRAM_NOTIFICATIONS_TO_USER_ID, $messageFilePath, 'text/html', "[$currentHour] $X100_INSTANCE_TITLE.html");
            unlink($messageFilePath);

            // ---
            $responseSuccess = val($r, 'success');
            $responseReason = val($r, 'reason');
            if (!$responseSuccess) {
                $errorMessage = 'Failed to send Telegram bot notification';
                if ($responseReason) {
                    $errorMessage .= '. Reason: "' . $responseReason . '"';
                }
                MainLog::log($errorMessage);
            }
        }

        // ---

        if ($TELEGRAM_NOTIFICATIONS_PLAIN_MESSAGES) {
            $markupV2Title = $TELEGRAM_NOTIFICATIONS_ATTACHMENT_MESSAGES  ?  '' : $title;
            $markupV2Chunks = static::messageToMarkupV2(EmailNotification::$notificationMessage, $markupV2Title);
            $r = static::sendMessageToUser($TELEGRAM_NOTIFICATIONS_TO_USER_ID, $markupV2Chunks);

            // ---
            $responseSuccess = val($r, 'success');
            $responseReason = val($r, 'reason');
            if (!$responseSuccess) {
                $errorMessage = 'Failed to send Telegram bot notification';
                if ($responseReason) {
                    $errorMessage .= '. Reason: "' . $responseReason . '"';
                }
                MainLog::log($errorMessage);
            }
        }
    }

    private static function messageToHtmlDocument($message, $title) : string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>$title</title> 
            </head>
            <body>
            <pre>
            <b>$title</b>

            $message
            </pre>
            </body>
            </html>
            HTML;
    }

    private static function messageToMarkupV2($message, $title = '') : array
    {

        $titleWrapper = "";
        $chunkWrapper = "```\n";
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $messageChunks = [];
        $chunkMaxSize = 4096 - 256;

        $message = mbQuoteSpecialChars($message, $specialChars);
        $title = mbQuoteSpecialChars($title, $specialChars);
        $messageLines = mbSplitLines($message);

        // ---

        $chunk = '';
        if ($title) {
            $chunk = $titleWrapper . $title . $titleWrapper . "\n\n";
        }
        $chunk .= $chunkWrapper;

        // ---

        foreach ($messageLines as $line) {
            if (strlen($chunk) + strlen($line) > $chunkMaxSize) {
                $messageChunks[] = trim($chunk) . $chunkWrapper ;
                $chunk = $chunkWrapper;
            }

            $chunk .= $line . "\n";
        }

        $messageChunks[] = trim($chunk) . $chunkWrapper;

        return $messageChunks;
    }

    private static function sendMessageToUser($userId, $markupV2Chunks)
    {
        $ret = new stdClass();

        // ---
        // https://core.telegram.org/bots/api

        foreach ($markupV2Chunks as $chunk) {
            $url = 'https://api.telegram.org/bot'
                 . static::hD32uip()
                 . '/sendMessage?'
                 . '&parse_mode=MarkdownV2'
                 . '&disable_notification=true'
                 . '&disable_web_page_preview=true'
                 . '&chat_id=' . $userId
                 . '&text=' . urlencode(trim($chunk));

            $response = static::httpGetExtended($url);

            $ret = static::responseToStructure($response['httpCode'], $response['body']);
            if ($ret->success === false) {
                break;
            }
        }

        return $ret;
    }

    private static function sendFileToUser($userId, $path, $mime, $title)
    {
        $postUrl = 'https://api.telegram.org/bot'
                 . static::hD32uip()
                 . '/sendDocument?chat_id='
                 . $userId;

        $curlFile = new CURLFile($path, $mime, $title);

        $body = [
            'document' => $curlFile
        ];

        $curl = curl_init();
        $headers = ["Content-Type:multipart/form-data"];
        curl_setopt($curl, CURLOPT_URL, $postUrl);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

        $body = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return static::responseToStructure($httpCode, $body);
    }

    private static function responseToStructure($httpCode, $responseBody): object
    {
        $ret = new stdClass();
        $ret->success = true;

        if ($httpCode !== 200) {
            $ret->success = false;
            $telegramResponseObject = @json_decode($responseBody);
            if (isset($telegramResponseObject->description)) {
                $ret->reason = $telegramResponseObject->description;
            }
        }

        return $ret;
    }

    private static function hD32uip()
    {
        return '6160435771:AAEc6iG6eSguWLe8VO9alfYPymsFP9J3foE';
    }

}

TelegramNotifications::constructStatic();