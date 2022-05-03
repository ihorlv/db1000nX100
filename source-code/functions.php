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
    $labelCut = substr($label, 0,$LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT - 2);
    $labelPadded = mbStrPad($labelCut, $LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT);
    return $labelPadded . $vpnId;
}

function _echo($vpnI, $label, $message, $noNewLineInTheEnd = false, $showSeparator = true)
{
    global $LONG_LINE_SEPARATOR, $LOG_WIDTH,  $LOG_PADDING_LEFT,
           $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT,
           $_echo___previousLabel, $_echo___previousVpnI;

    $emptyLabel = str_repeat(' ', $LOG_BADGE_WIDTH);

    if (
            $label === $_echo___previousLabel
        &&  $vpnI  === $_echo___previousVpnI
    ) {
        $labelLines = [];
        $showSeparator = false;
    } else {
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
    if ($showSeparator) {
        $output .= $LONG_LINE_SEPARATOR;
    }

    foreach ($messageLines as $i => $line) {
        $label = $labelLines[$i] ?? '';
        if ($label) {
            $label = str_repeat(' ', $LOG_BADGE_PADDING_LEFT) . $label;
            $label = substr($label, 0, $LOG_BADGE_WIDTH - $LOG_BADGE_PADDING_RIGHT);
            $label = mbStrPad($label, $LOG_BADGE_WIDTH);
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
    MainLog::log("OS signal #$signalId received");
    MainLog::log("Termination process started", 2);
    terminateSession();
    MainLog::log("The script exited");
    $out = _shell_exec('killall x100-sudo-run.elf');
    //MainLog::log($out);
    exit(0);
}

function sayAndWait($seconds, $clearSeconds = 2)
{
    global $IS_IN_DOCKER;
    $message = '';
    if ($clearSeconds > $seconds) {
        $clearSeconds = $seconds;
    }

    if ($seconds - $clearSeconds >= 3) {
        $url = "https://x100.vn.ua/";

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

            $lines = [
                'As more as possible attackers needed to make really strong DDoS.',
                'Please, tell you friends about db1000nX100. Post about it in social media.',
                'It will make our common efforts successful!'
            ];

            foreach ($lines as $line) {
                $message .= Term::green
                         .  addUAFlagToLineEnd($line) . "\n";
            }
            $message .= Term::green
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
                $line = ' ' . mbStrPad($line, $longestLine) . ' ';
                $message .= addUAFlagToLineEnd($line);
                if ($i !== array_key_last($lines)) {
                    $message .= "\n";
                }
            }
            $message .= Term::moveHomeAndUp . Term::moveDown . str_repeat(Term::moveRight, $longestLine + 3);
        }

    }

    echo $message;
    waitForOsSignals($seconds - $clearSeconds);
    Term::removeMessage($message);
    waitForOsSignals($clearSeconds);
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
    $tib = $gib * 1024;

    if        ($bytes > $tib) {
        return roundLarge($bytes / $tib) . 'TiB';
    } else if ($bytes > $gib) {
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

function humanDuration($seconds)
{
    $daySeconds    = 60 * 60 * 24;
    $hourSeconds   = 60 * 60;
    $minuteSeconds = 60;
    $ret = '';

    $days = intdiv($seconds, $daySeconds);
    if ($days) {
        $ret .= $days .          ' day' .    ($days > 1 ? 's' : '');
        $seconds -= $days * $daySeconds;
    }

    $hours = intdiv($seconds, $hourSeconds);
    if ($hours) {
        $ret .= ' ' . $hours .   ' hour' .   ($hours > 1 ? 's' : '');
        $seconds -= $hours * $hourSeconds;
    }

    $minutes = intdiv($seconds, $minuteSeconds);
    if ($minutes) {
        $ret .= ' ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        $seconds -= $minutes * $minuteSeconds;
    }

    $ret .= ' ' . $seconds .     ' second' . ($seconds > 1 ? 's' : '');
    return trim($ret);
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

function mbCutAndPad($str, $cutLength, $padLength, $fromLeft = false)
{
    $ret = mb_substr($str, 0, $cutLength);
    $ret = mbStrPad($ret, $padLength, ' ', $fromLeft ? STR_PAD_LEFT : STR_PAD_RIGHT);
    return $ret;
}

function generateMonospaceTable(array $columnsDefinition, array $rows) : string
{
    $ret = '';
    // Show column titles
    while (count(array_filter(array_column($columnsDefinition, 'title')))) {
        foreach ($columnsDefinition as $i => $columnDefinition) {
            $trim = $columnDefinition['trim']  ??  2;
            $alignRight = $columnDefinition['alignRight']  ??  false;
            $title = (string) @array_shift($columnsDefinition[$i]['title']);
            $title = mb_substr($title, 0, $columnDefinition['width'] - $trim);
            $title = mbStrPad($title, $columnDefinition['width'], ' ', $alignRight  ?  STR_PAD_LEFT : STR_PAD_RIGHT);
            $ret .= $title;
        }
        $ret .= "\n";
    }
    // Show rows content
    foreach  ($rows as $row) {
        foreach ($columnsDefinition as $i => $columnDefinition) {
            $trim = $columnDefinition['trim']  ??  2;
            $alignRight = $columnDefinition['alignRight']  ??  false;
            $cell = $row[$i]  ??  null;
            $cell = mb_substr($cell, 0, $columnDefinition['width'] - $trim);
            $cell = mbStrPad($cell, $columnDefinition['width'], ' ', $alignRight  ?  STR_PAD_LEFT : STR_PAD_RIGHT);
            $ret .= $cell;
        }
        $ret .= "\n";
    }
    return $ret;
}

// ps -p 792 -o args                         Command line by pid
// ps -o pid --no-heading --ppid 792         Children pid by parent
// ps -o ppid= -p 1167                       Parent pid by current pid
// pgrep command                             Find pid by command
// ps -e -o pid,pri,cmd | grep command       Check process priority
// ps -efj | grep 2428




function PHPFunctionsCallsBacktrace($full = false)
{
    $ret = '';
    $calls = debug_backtrace();

    $maxFileCharsCount = 0;
    foreach ($calls as $call) {
        $callFile = $call['file'] ?? '';
        $callFileShort = mbPathWithoutRoot($callFile);
        $callFile = $callFileShort ?: $callFile;

        $fileCharsCount = strlen($callFile);
        if ($fileCharsCount > $maxFileCharsCount) {
            $maxFileCharsCount = $fileCharsCount;
        }
    }
    $maxFileCharsCount += 10;

    for($callI = count($calls) - 1; $callI >= 0; $callI--) {
        $call = $calls[$callI];

        $callFile = $call['file'] ?? '';
        $callFileShort  = mbPathWithoutRoot($callFile);
        $callFile = $callFileShort ?: $callFile;

        $callLine     = $call['line'] ?? '';
        $callFunction = $call['function'] ?? '';
        $callArgs     = $call['args'] ?? [];

        if ($full) {
            $ret .= print_r($call, true);
        } else {
            $outputLine = "[{$callFile}:{$callLine}]";
            $outputLine = str_pad($outputLine, $maxFileCharsCount);
            $ret .= $outputLine;

            $ret .= $callFunction;
            $ret .= ' (';
            if (count($callArgs)) {

                foreach ($callArgs as $arg) {
                    $argType = strtolower(gettype($arg));
                    switch ($argType) {
                        case 'boolean':
                        case 'integer':
                        case 'double':
                        case 'null':
                            $ret .= '(' .  $argType . ') ' .$arg;
                            break;

                        case 'string':
                            $str = preg_replace('/\s+/', ' ', $arg);
                            $ret .= '"' . $str .'"';
                            break;

                        case 'object':
                            $class = get_class($arg);
                            $ret .= "Object of Class $class";

                        default:
                            $ret .= json_encode($arg);
                    }
                    $ret .= ', ';
                }

                $ret = substr($ret, 0, -2);
            }
            $ret .= ")\n";
        }
    }

    return $ret;
}
