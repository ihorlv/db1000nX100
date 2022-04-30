<?php

function getDirectoryFilesListRecursive($dir, $ext = '')
{
    $ret = [];
    $ext = mb_strtolower($ext);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $iterator->rewind();
    while($iterator->valid()) {

        if ($ext  &&  $ext !== mb_strtolower($iterator->getExtension())) {
            goto nextFile;
        }

        $ret[] = $iterator->getPathname();

        nextFile:
        $iterator->next();
    }
    return $ret;
}

function rmdirRecursive(string $dir) : bool
{
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dir);
        return true;
    } catch (\Exception $e) {
        return false;
    }
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
    global $LOG_WIDTH, $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT, $LOG_PADDING_LEFT,
           $_echo___previousLabel, $_echo___previousVpnI;

    $emptyLabel = str_repeat(' ', $LOG_BADGE_WIDTH);
    $separator = $emptyLabel . "│\n"
               . str_repeat('─', $LOG_BADGE_WIDTH) . '┼'
               . str_repeat('─', $LOG_WIDTH) . "\n"
               . $emptyLabel . "│\n";

    if (
            $label === $_echo___previousLabel
        &&  $vpnI  === $_echo___previousVpnI
        &&  !$forceBadge
    ) {
        $skipSeparator = true;
        $labelLines = [];
    } else {
        $skipSeparator = false;
        $_echo___previousLabel = $label;
        $_echo___previousVpnI  = $vpnI;

        $labelLines = mbSplitLines($label);
        if (!count($labelLines)) {
            $labelLines[0] = '';
        }
        $labelLines[0] = buildFirstLineLabel($vpnI, $labelLines[0]);
    }



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
    $output = '';
    if (! $skipSeparator) {
        $output .= $separator;
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

        $output .= $label . '│' . str_repeat(' ', $LOG_PADDING_LEFT)  . $line;

        if ($i !== array_key_last($messageLines)) {
            $output .= "\n";
        } else if (!$noNewLineInTheEnd) {
            $output .= "\n";
        }
    }

    MainLog::log($output, MainLog::LOG_HACK_APPLICATION, 0);
}

function _die($message)
{
    MainLog::log("CRITICAL ERROR: $message", MainLog::LOG_GENERAL_ERROR, 3, 3);
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
    MainLog::log("$LONG_LINE", MainLog::LOG_GENERAL, 2, 2);
    MainLog::log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "OS signal #$signalId received");
    MainLog::log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Termination process started");
    terminateSession();
    MainLog::log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "The script exited");
    $out = _shell_exec('killall x100-sudo-run.elf');
    //MainLog::log($out);
    exit(0);
}

function sayAndWait($seconds)
{
    global $IS_IN_DOCKER;
    $url = "https://x100.vn.ua/";
    $authorsLine = 'The authors of this project keep working to improve it';
    $clearSecond = 0;
    $message = '';

    if ($seconds > 2) {
        $clearSecond = 2;

        $efficiencyMessage = Efficiency::getMessage();
        if ($efficiencyMessage) {
            $message .= "\n$efficiencyMessage";
        }

        $message .= "\n"
                 .  addUAFlagToLineEnd(
                        "Waiting $seconds seconds. Press Ctrl+C "
                         . Term::bgRed . Term::brightWhite
                         . " now "
                         . Term::clear
                         . ", if you want to terminate this script (correctly)"
                    ) . "\n";

        if (SelfUpdate::isUpToDate()) {

            $message .= Term::green
                .  addUAFlagToLineEnd($authorsLine) . "\n"
                .  Term::green
                .  addUAFlagToLineEnd("Please, visit the project's website at least once daily, to download new versions") . "\n"
                .  Term::green
                .  Term::underline
                .  addUAFlagToLineEnd($url)
                .  Term::moveHomeAndUp . Term::moveDown . str_repeat(Term::moveRight, strlen($url) + 1);
        } else {
            $clearSecond = 0;
            $message .= addUAFlagToLineEnd(' ') . "\n";
            $line1 = 'New version of db1000nX100 is available!' . str_pad(' ', 20) . SelfUpdate::getLatestVersion();
            if ($IS_IN_DOCKER) {
                $line2 = 'Please, restart this Docker container to update';
                $line3 = '';
            } else {
                $line2 = 'Please, visit the project\'s website to download it';
                $line3 = $url;
            }
            $longestLine = max(mb_strlen($line1), mb_strlen($line2), mb_strlen($line3));
            $lines = mbRemoveEmptyLinesFromArray([$line1, $line2, $line3]);

            foreach ($lines as $i => $line) {
                $message .= Term::bgRed . Term::brightWhite;
                $line = ' ' . str_pad($line, $longestLine) . ' ';
                $message .= addUAFlagToLineEnd($line);
                if ($i !== array_key_last($lines)) {
                    $message .= "\n";
                }
            }
            $message .= Term::moveHomeAndUp . Term::moveDown . str_repeat(Term::moveRight, $longestLine + 3);
        }

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

function bytesToGiB(?int $bytes)
{
    return round($bytes / (1024 * 1024 * 1024), 1);
}

function humanBytes(?int $bytes) : string
{
    $kib = 1024;
    $mib = $kib * 1024;
    $gib = $mib * 1024;

    if ($bytes > $gib) {
        return roundLarge($bytes / $gib) . 'GiB';
    } else if ($bytes > $mib) {
        return roundLarge($bytes / $mib) . 'MiB';
    } else if ($bytes > $kib) {
        return roundLarge($bytes / $kib) . 'KiB';
    } else if ($bytes > 0) {
        return $bytes . 'B';
    } else {
        return $bytes;
    }
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
    return file_put_contents($filename, $data, $flags, $context);
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

function isTimeForLongBrake() : bool
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

function _shell_exec(string $command)
{
    $ret = shell_exec($command . '   2>&1');
    if (! mbTrim($ret)) {
        $ret = '';
    }
    return $ret;
}

function httpGet($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
    curl_setopt($curl, CURLOPT_USERAGENT, 'curl');
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true );
    $content = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode === 200) {
        return $content;
    } else {
        return false;
    }
}

function changeLinuxPermissions($permissions, $toUser, $toGroup = '', $toOther = '', $remove = false)
{
	$x = 01;
	$w = 02;
	$r = 04;
	
	$u = 0100;
	$g = 010;

	$sign = $remove ? -01 : 01;

	$permissions += (is_int(strpos($toUser, 'r'))  ? $r * $u : 0) * $sign;	
	$permissions += (is_int(strpos($toUser, 'w'))  ? $w * $u : 0) * $sign;
	$permissions += (is_int(strpos($toUser, 'x'))  ? $x * $u : 0) * $sign;

	$permissions += (is_int(strpos($toGroup, 'r'))  ? $r * $g : 0) * $sign;		
	$permissions += (is_int(strpos($toGroup, 'w'))  ? $w * $g : 0) * $sign;
	$permissions += (is_int(strpos($toGroup, 'x'))  ? $x * $g : 0) * $sign;
	
	$permissions += (is_int(strpos($toOther, 'r'))  ? $r : 0) * $sign;
	$permissions += (is_int(strpos($toOther, 'w'))  ? $w : 0) * $sign;
	$permissions += (is_int(strpos($toOther, 'x'))  ? $x : 0) * $sign;
	
	return $permissions;	
}

function cleanSwapDisk()
{


    MainLog::log('Cleaning Swap disk   ', MainLog::LOG_GENERAL, 0);
    $commands = [
        '/usr/sbin/swapoff  --all',
        '/usr/sbin/swapon   --all  --discard',
        '/usr/sbin/swapoff  --all',
        '/usr/sbin/swapon   --all'
    ];

    foreach ($commands as $command) {
        $output = _shell_exec($command);
        if ($output) {
            MainLog::log(_shell_exec($command));
        }
    }

    MainLog::log();
}

function trimFileFromBeginning($path, $trimChunkSize, $discardIncompleteLine = false)
{
    if (filesize($path) <= $trimChunkSize) {
        return;
    }

    $copyPath = $path . '.tmp';
    copy($path, $copyPath);
    file_put_contents($path, '');

    $fCopy = fopen($copyPath, 'rb');
    $fLog  = fopen($path, 'w');

    fseek($fCopy, $trimChunkSize);
    if ($discardIncompleteLine) {
        fgets($fCopy);
    }

    while(!feof($fCopy)) {
        $block = fread($fCopy, 1024 * 256);
        fwrite($fLog, $block);
    }

    fclose($fLog);
    fclose($fCopy);
    unlink($copyPath);
}

function cutAndPad($str, $cutLength, $padLength, $fromLeft = false)
{
    $ret = mb_substr($str, 0, $cutLength);
    $ret =   str_pad($ret, $padLength, ' ', $fromLeft ? STR_PAD_LEFT : STR_PAD_RIGHT);
    return $ret;
}


// ps -p 792 -o args                         Command line by pid
// ps -o pid --no-heading --ppid 792         Children pid by parent
// ps -o ppid= -p 1167                       Parent pid by current pid
// pgrep command                             Find pid by command
// ps -e -o pid,pri,cmd | grep command       Check process priority
// ps -efj | grep 2428