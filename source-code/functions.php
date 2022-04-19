<?php

function getDirectoryFilesListRecursive($dir, $ext = '')
{
    $ret = [];
    $ext = mb_strtolower($ext);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $iterator->rewind();
    while($iterator->valid()) {
        if($iterator->isDot()) {
            goto nextFile;
        }

        if ($ext  &&  $ext !== mb_strtolower($iterator->getExtension())) {
            goto nextFile;
        }

        $ret[] = $iterator->getPathname();

        nextFile:
        $iterator->next();
    }
    return $ret;
}

function streamReadLines($stream, $wait = 0.5) : string
{
    $ret  = '';
    stream_set_blocking($stream, false);
    waitForOsSignals($wait);
    while (($line = fgets($stream)) !== false) {
        $ret .= $line;
    }
    return $ret;
}

function buildFirstLineLabel($vpnI, $label)
{
    global $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT;
    $vpnId = 'VPN' . $vpnI;
    /*if ($vpnI > 99) {
        $vpnId = 'V' . $vpnI;
    } else if ($vpnI > 9) {
        $vpnId = 'VP' . $vpnI;
    } else {
        $vpnId = 'VPN' . $vpnI;
    }*/
    $labelCut = substr($label, 0,$LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT - 2);
    $labelPadded = str_pad($labelCut, $LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT);
    return $labelPadded . $vpnId;
}

function _echo($vpnI, $label, $message, $noNewLineInTheEnd = false, $forceBadge = false)
{
    global $LOG_WIDTH, $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT, $LOG_PADDING_LEFT, $_echoPreviousLabel;
    $emptyLabel = str_repeat(' ', $LOG_BADGE_WIDTH);
    $separator = $emptyLabel . "│\n"
               . str_repeat('─', $LOG_BADGE_WIDTH) . '┼'
               . str_repeat('─', $LOG_WIDTH) . "\n"
               . $emptyLabel . "│\n";

    //$label = mbTrim($label);
    if ($label === $_echoPreviousLabel) {
        $labelLines = [];
    } else {
        $labelLines = mbSplitLines($label);
    }
    if (isset($labelLines[0])) {
        $labelLines[0] = buildFirstLineLabel($vpnI, $labelLines[0]);
        $label =  implode("\n", $labelLines);
    }
    $_echoPreviousLabel = $label;

    // Split long lines
    $messageLines = [];
    $pos = 0;
    $line = '';
    while ($pos < mb_strlen($message)) {
        $c = mb_substr($message, $pos, 1);

        if ($c === PHP_EOL) {
            $messageLines[] = $line;
            $line = '';
        } else if (mb_strlen($line) >= $LOG_WIDTH - $LOG_PADDING_LEFT) {
            $messageLines[] = $line;
            $line = '>   ' . $c;
        } else {
            $line .= $c;
        }

        $pos++;
    }
    $messageLines[] = $line;

    // ---------- Output ----------
    if (count($labelLines)  ||  $forceBadge) {
        echo $separator;
    }

    foreach ($messageLines as $i => $line) {
        $label = $labelLines[$i] ?? '';
        if ($label) {
            $label = str_repeat(' ', $LOG_BADGE_PADDING_LEFT) . $label;
            $label = substr($label, 0, $LOG_BADGE_WIDTH - $LOG_BADGE_PADDING_RIGHT);
            $label = str_pad($label, $LOG_BADGE_WIDTH);
        } else {
            $label = $emptyLabel;
        }

        echo $label . '│' . str_repeat(' ', $LOG_PADDING_LEFT)  . $line;

        if ($i !== array_key_last($messageLines)) {
            echo "\n";
        } else if (!$noNewLineInTheEnd) {
            echo "\n";
        }
    }
}

function _die($message)
{
    echo Term::red;
    echo Term::bold;
    echo "\n\n\nCRITICAL ERROR: $message\n\n\n";
    echo Term::clear;
    waitForOsSignals(3600);
    die();
}

function randomArrayItem(array $array, int $quantity = 1)
{
    $randomKeys = array_rand($array, $quantity);
    if (is_array($randomKeys)) {
        $ret = [];
        foreach ($randomKeys as $randomKey) {
            $ret[$randomKey] = $array[$randomKey];
        }
        return $ret;
    } else if (isset($array[$randomKeys])) {
        return $array[$randomKeys];
    } else {
        return $randomKeys;
    }
}

function waitForOsSignals($floatSeconds, $callback = 'onOsSignalReceived')
{
    $intSeconds = floor($floatSeconds);
    $nanoSeconds = ($floatSeconds - $intSeconds) * pow(10, 9);
    $signalId = pcntl_sigtimedwait([SIGTERM, SIGINT], $info,  $intSeconds,  $nanoSeconds);
    if (gettype($signalId) === 'integer'  &&  $signalId > 0) {
        $callback($signalId);
    }
}

function onOsSignalReceived($signalId)
{
    global $LONG_LINE, $LOG_BADGE_WIDTH;
    echo chr(7);
    echo "\n\n$LONG_LINE\n\n";
    echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "OS signal #$signalId received\n";
    echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Termination process started\n";
    terminateSession();
    echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "The script exited\n\n";

    passthru('killall hack-linux-runme.elf');
    exit(0);
}

function sayAndWait($seconds)
{
    global $IS_IN_DOCKER;

    if ($seconds > 2) {
        $clearSecond = 2;

        $message =  '';
        $efficiencyMessage = Efficiency::getMessage();
        if ($efficiencyMessage) {
            $message .= "\n$efficiencyMessage";
        }

        $url = $IS_IN_DOCKER ? "https://x100.vn.ua/db1000n/db1000nX100-for-docker/" : "https://x100.vn.ua/db1000n/db1000nx100-for-virtual-box/";
        $message .= "\n"
                 .  addUAFlagToLineEnd(
                        "Waiting $seconds seconds. Press Ctrl+C "
                         . Term::bgRed . Term::brightWhite
                         . " now "
                         . Term::clear
                         . ", if you want to terminate this script (correctly)"
                    ) . "\n"
                 .  Term::green
                 .  addUAFlagToLineEnd("The author of this virtual machine keeps working to improve it") . "\n"
                 .  Term::green
                 .  addUAFlagToLineEnd("Please, visit the project's website at least once daily, to download new versions") . "\n"
                 .  Term::green
                 .  Term::underline
                 .  addUAFlagToLineEnd($url)
                 .  Term::moveHomeAndUp . Term::moveDown . str_repeat(Term::moveRight, strlen($url) + 1);
    } else {
        $clearSecond = 0;
        $message = '';
    }

    echo $message;
    waitForOsSignals($seconds - $clearSecond);
    Term::removeMessage($message);
    waitForOsSignals($clearSecond);
}

function addUAFlagToLineEnd($line)
{
    global $LOG_WIDTH, $LOG_BADGE_WIDTH;
    $flagLine = Term::bgBrightBlue . '  ' . Term::bgBrightYellow . '  ' . Term::clear;
    $flagLineLength = 4;
    $line .= Term::clear
          .  str_repeat(' ', $LOG_BADGE_WIDTH + 1 + $LOG_WIDTH - mb_strlen(Term::removeMarkup($line)) - $flagLineLength)
          .  $flagLine;
    return $line;
}

function writeStatistics()
{
    global $TEMP_DIR;
    $statisticsLog = $TEMP_DIR . '/statistics-log.txt';

    ksort(OpenVpnConnection::$openVpnConfigFilesUsed);
    $statistics  = "OpenVpnConnection::\$openVpnConfigFilesUsed " . count(OpenVpnConnection::$openVpnConfigFilesUsed) . "\n";
    $statistics .= print_r(OpenVpnConnection::$openVpnConfigFilesUsed, true) . "\n\n";

    ksort(OpenVpnConnection::$openVpnConfigFilesWithError);
    $statistics .= "OpenVpnConnection::\$openVpnConfigFilesWithError " . count(OpenVpnConnection::$openVpnConfigFilesWithError) . "\n";
    $statistics .= print_r(OpenVpnConnection::$openVpnConfigFilesWithError, true) . "\n\n";

    uksort(OpenVpnConnection::$vpnPublicIPsUsed, 'strnatcmp');
    $statistics .= "OpenVpnConnection::\$vpnPublicIPsUsed " . count(OpenVpnConnection::$vpnPublicIPsUsed) . "\n";
    $statistics .= print_r(OpenVpnConnection::$vpnPublicIPsUsed, true) . "\n\n";
    file_put_contents($statisticsLog, $statistics);
}

function getDockerConfig()
{
    $cpus = 0;
    $memory = 0;
    $vpnQuantity = 0;

    $config = @file_get_contents(__DIR__ . '/docker.config');
    if (! $config) {
        return false;
    }

    $cpusRegExp = <<<PhpRegExp
        #cpus=(\d+)#
        PhpRegExp;
    if (preg_match(trim($cpusRegExp), $config, $matches) === 1) {
        $cpus = (int) $matches[1];
    }

    $memoryRegExp = <<<PhpRegExp
        #memory=(\d+)#
        PhpRegExp;
    if (preg_match(trim($memoryRegExp), $config, $matches) === 1) {
        $memory = (int) $matches[1];
    }

    $vpnQuantityRegExp = <<<PhpRegExp
        #vpnQuantity=(\d+)#
        PhpRegExp;
    if (preg_match(trim($vpnQuantityRegExp), $config, $matches) === 1) {
        $vpnQuantity = (int) $matches[1];
    }

    return [
        'cpus'        => $cpus,
        'memory'      => $memory,
        'vpnQuantity' => $vpnQuantity
    ];
}

function bytesToGiB($bytes)
{
    return round($bytes / (1024 * 1024 * 1024), 1);
}

function clearTotalEfficiencyLevel($keepPrevious = false)
{
    global $TOTAL_EFFICIENCY_LEVEL, $TOTAL_EFFICIENCY_LEVEL_PREVIOUS;
    if ($keepPrevious) {
        $TOTAL_EFFICIENCY_LEVEL_PREVIOUS = $TOTAL_EFFICIENCY_LEVEL;
    } else {
        $TOTAL_EFFICIENCY_LEVEL_PREVIOUS = null;
    }

    $TOTAL_EFFICIENCY_LEVEL = null;
}

function file_put_contents_secure(string $filename, $data, int $flags = 0, $context = null)
{
    global $NEW_DIR_ACCESS_MODE, $NEW_FILE_ACCESS_MODE;
    $dir = mbDirname($filename);
    @mkdir($dir, $NEW_DIR_ACCESS_MODE, true);
    file_put_contents($filename, 'nothing');
    chmod($filename, $NEW_FILE_ACCESS_MODE);
    file_put_contents($filename, $data, $flags, $context);
}

function isProcAlive($processResource)
{
    if (! is_resource($processResource)) {
        return false;
    }

    $processStatus = proc_get_status($processResource);
    return $processStatus['running'];
}

/*
 * After practical experiments I have found out that posix_getpgid() works only if process haven't
 * created subprocess. Therefore, we need to delay to command proc_open('sleep 1; our_command')
 */
function procChangePGid($processResource, &$log)
{
    if (! isProcAlive($processResource)) {
        return false;
    }

    $log .= "Script process    PID/PGID/SID/PPID    ";
    $log .= posix_getpid() . '/'
        .  posix_getpgid(posix_getpid()) . '/'
        .  posix_getsid(posix_getpid())  . '/'
        .  posix_getppid() .  "\n";

    $processStatus = proc_get_status($processResource);
    $subPid = (int) $processStatus['pid'];
    $subPGid = posix_getpgid($subPid);
    $subSid = posix_getsid($subPid);

    $log .= "Subprocess        PID/PGID/SID         ";
    $log .= "$subPid/$subPGid/$subSid\n";

    $pgidChangeStatus = posix_setpgid($subPid, $subPid);
    $newSubPGid = posix_getpgid($subPid);
    $log .= "Subprocess new    PGID                 " . $newSubPGid;

    if ($pgidChangeStatus === false  ||  $newSubPGid === $subPGid) {
        $log = "Failed to change subprocess PGID: " . posix_strerror(posix_get_last_error()) . "\n" . $log;
        return false;
    }

    return $newSubPGid;
}

function getArrayLastValue($array)
{
    if (!is_array($array)  ||  !count($array)) {
        return null;
    }

    end($array);
    return current($array);
}

function roundLarge($value)
{
    $roundOneDigit  =       round($value, 1);
    $roundZeroDigit = (int) round($value, 0);
    if ($roundOneDigit > 0  &&  $roundOneDigit < 10) {
        return $roundOneDigit;
    } else {
        return $roundZeroDigit;
    }
}

function isTimeForBrake()
{
    global $isTimeForBrake_lastBreak;
    $now = time();
    if ($now > $isTimeForBrake_lastBreak + 60) {
        $isTimeForBrake_lastBreak = $now;
        return true;
    } else {
        return false;
    }
}

// ps -p 792 -o args                         Command line by pid
// ps -o pid --no-heading --ppid 792         Children pid by parent
// ps -o ppid= -p 1167                       Parent pid by current pid
// pgrep command                             Find pid by command
// ps -e -o pid,pri,cmd | grep command       Check process priority
// ps -efj | grep 2428