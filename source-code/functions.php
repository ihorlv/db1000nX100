<?php

function _echo($vpnI, $label, $message, $logChannel, $forceBadge = false, $noNewLineInTheEnd = false, $showSeparator = true)
{
    global $LONG_LINE_SEPARATOR, $LOG_WIDTH,  $LOG_PADDING_LEFT,
           $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT,
           $_echo___previousLabel, $_echo___previousVpnI;

    TimeTracking::startTaskTimeTracking('_echo');
    $emptyLabel = str_repeat(' ', $LOG_BADGE_WIDTH);

    if (
            $label === $_echo___previousLabel
        &&  $vpnI  === $_echo___previousVpnI
        &&  !$forceBadge
    ) {
        $labelLines = [];
        $showSeparator = false;
    } else {
        $labelLines = mbSplitLines($label);
        if (!count($labelLines)) {
            $labelLines[0] = '';
        }
        $labelLines[0] = buildFirstLineLabel($vpnI, $labelLines[0]);
    }

    $_echo___previousLabel = $label;
    $_echo___previousVpnI  = $vpnI;

    // ---

    $messageLines = mbSplitLines($message);

    $linesCountDifference = count($labelLines) - count($messageLines) + 1;
    for ($i = 0; $i < $linesCountDifference; $i++) {
        $messageLines[] = '';
    }

    // ---------- Output ----------
    $output = '';
    if ($showSeparator) {
        $output .= $LONG_LINE_SEPARATOR;
    }

    $labelLineI = 0;
    foreach ($messageLines as $li => $line) {
        $subLines = mb_str_split($line, $LOG_WIDTH - $LOG_PADDING_LEFT);
        $subLines = count($subLines) === 0 ? [''] : $subLines;

        foreach ($subLines as $si => $subLine) {
            $label = $labelLines[$labelLineI++]  ??  '';
            if ($label) {
                $label = str_repeat(' ', $LOG_BADGE_PADDING_LEFT) . $label;
                $label = substr($label, 0, $LOG_BADGE_WIDTH - $LOG_BADGE_PADDING_RIGHT);
                $label = mbStrPad($label, $LOG_BADGE_WIDTH);
            } else {
                $label = $emptyLabel;
            }

            $output .= $label . '│' . str_repeat(' ', $LOG_PADDING_LEFT)  . $subLine;

            if ($li === array_key_last($messageLines)  &&  $si === array_key_last($subLines)) {
                if (!$noNewLineInTheEnd) {
                    $output .= "\n";
                }
            } else {
                $output .= "\n";
            }
        }
    }

    MainLog::log($output, 0, 0, $logChannel);
    TimeTracking::stopTaskTimeTracking('_echo');
}

function buildFirstLineLabel($vpnI, $label)
{
    global $LOG_BADGE_WIDTH, $LOG_BADGE_PADDING_LEFT, $LOG_BADGE_PADDING_RIGHT;
    $vpnId = 'VPN' . $vpnI;
    $labelCut = substr($label, 0,$LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT - 2);
    $labelPadded = mbStrPad($labelCut, $LOG_BADGE_WIDTH - strlen($vpnId) - $LOG_BADGE_PADDING_LEFT - $LOG_BADGE_PADDING_RIGHT);
    return $labelPadded . $vpnId;
}


function getFilesListOfDirectory(string $dirRoot, bool $includeDirs = false) : array
{
    $ret = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $iterator->rewind();
    while($iterator->valid()) {
        $path = $iterator->getPathname();
        if (
                 is_dir($path)
            &&  !is_link($path)
            &&  $path !== $dirRoot
        ) {
            $ret[] = $path . '/';
        } else {
            $ret[] = $path;
        }
        $iterator->next();
    }
    return array_unique($ret);  // array_unique because the of bug. same paths were in list twice
}


const SEARCH_IN_FILES_LIST_RETURN_FILES       = 1 << 1;
const SEARCH_IN_FILES_LIST_RETURN_DIRS        = 1 << 2;

const SEARCH_IN_FILES_LIST_MATCH_DIRNAME      = 1 << 3;
const SEARCH_IN_FILES_LIST_MATCH_FILENAME     = 1 << 4;
const SEARCH_IN_FILES_LIST_MATCH_BASENAME     = 1 << 5;
const SEARCH_IN_FILES_LIST_MATCH_EXT          = 1 << 6;

function searchInFilesList(array $list, int $flags, string $searchRegExp, string $regExpModifier = 'u') : array
{
    $searchRegExp = "#$searchRegExp#$regExpModifier";
    $returnFiles   = SEARCH_IN_FILES_LIST_RETURN_FILES    & $flags;
    $returnDirs    = SEARCH_IN_FILES_LIST_RETURN_DIRS     & $flags;
    $matchDirname  = SEARCH_IN_FILES_LIST_MATCH_DIRNAME   & $flags;
    $matchBasename = SEARCH_IN_FILES_LIST_MATCH_BASENAME  & $flags;
    $matchFilename = SEARCH_IN_FILES_LIST_MATCH_FILENAME  & $flags;
    $matchExt      = SEARCH_IN_FILES_LIST_MATCH_EXT       & $flags;

    $ret = [];
    $alreadySearchedIn = [];
    foreach ($list as $path) {

        $pathTrimmed = mbTrimDir($path);
        if (!$pathTrimmed) {
            _die("Invalid path $path");
        }

        // ---

        $isDir = mb_substr($path, -1) === '/';
        $path = $pathTrimmed;

        if (!($returnFiles && $returnDirs)) {
            if ($returnDirs && !$isDir) {
                continue;
            } else if ($returnFiles && $isDir) {
                continue;
            }
        }

               if ($matchDirname) {
            $searchIn = $isDir  ?  $path : mbDirname($path);
        } else if ($matchBasename) {
            $searchIn = mbBasename($path);
        } else if ($matchFilename) {
            $searchIn = mbFilename($path);
        } else if ($matchExt) {
            $searchIn = mbExt($path);
        } else {
            $searchIn = $path;
        }

        //echo $searchIn . "\n";

        if (isset($alreadySearchedIn[$searchIn])) {
            $match = $alreadySearchedIn[$searchIn];
        } else {
            $match = preg_match($searchRegExp, $searchIn) > 0;
            $alreadySearchedIn[$searchIn] = $match;
        }

        if ($match) {
            if (is_dir($path)  && !is_link($path)) {
                $path .= '/';
            }

            $ret[] = $path;
        }
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
        foreach ($files as $fileInfo) {
            if (
                   $fileInfo->isLink()
                || $fileInfo->isFile()
            ) {
                unlink($fileInfo->getPathname());
            } else {
                rmdir($fileInfo->getPathname());
            }
        }
        rmdir($dir);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function copyDirRecursive(string $sourceDir, string $destinationDir, $force = false) : bool
{
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileInfo) {
            $sourcePath = $fileInfo->getPathname();
            $sourcePermissions = fileperms($sourcePath);
            $sourceSubPath = mbPathWithoutRoot($sourcePath, $sourceDir);
            $destPath = $destinationDir . $sourceSubPath;

            if ($force) {
                if (is_dir($destPath)) {
                    rmdirRecursive($destPath);
                } else if (file_exists($destPath)) {
                    unlink($destPath);
                }
            }

            if (is_dir($sourcePath)) {
                @mkdir($destPath, 0750, true);
            } else {
                @mkdir(mbDirname($destPath), 0750, true);
                copy($sourcePath, $destPath);
                chmod($destPath, $sourcePermissions);
            }

        }
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function streamReadLines($stream, float $wait = 0.1) : string
{
    $ret  = '';
    if (!is_resource($stream)) {
        return $ret;
    }

    stream_set_blocking($stream, false);
    waitForOsSignals($wait);
    while (($line = fgets($stream)) !== false) {
        $ret .= $line;
    }
    return $ret;
}

function _die($message)
{
    MainLog::log("CRITICAL ERROR: $message", 3, 3, MainLog::LOG_GENERAL_ERROR);
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

function sortArrayBySubValue($array, bool $ascending, ...$subKeys)
{
    $ascendingMultiplier = $ascending ? 1 : -1;

    uasort($array, function($leftArr, $rightArr) use ($subKeys, $ascendingMultiplier) {

        $left  = val($leftArr, $subKeys);
        $right = val($rightArr, $subKeys);

        if ($left == $right) {
            return 0;
        } else if (gettype($left) === 'string'  &&  gettype($right) === 'string') {
            return strnatcmp($left, $right) * $ascendingMultiplier;
        } else {
            $cmp = ($left < $right) ? -1 : 1;
            return $cmp * $ascendingMultiplier;
        }

    });

    return $array;
}

function waitForOsSignals(float $floatSeconds, $callback = 'onOsSignalReceived')
{
    if (class_exists('ResourcesConsumption')) {
        TimeTracking::startTaskTimeTracking('waitForOsSignals');
    }
    $intSeconds = floor($floatSeconds);
    $nanoSeconds = ($floatSeconds - $intSeconds) * pow(10, 9);

    $signalId = pcntl_sigtimedwait([SIGTERM, SIGINT, SIGQUIT], $info,  $intSeconds,  $nanoSeconds);

    if (class_exists('ResourcesConsumption')) {
        TimeTracking::stopTaskTimeTracking('waitForOsSignals');
    }
    if (gettype($signalId) === 'integer'  &&  $signalId > 0) {
        $callback($signalId);
    }

    // ---

    global $STDIN;
    $stdin = trim(stream_get_contents($STDIN));
    if ($stdin) {
        onStdinCode($stdin);
    }
}

function onOsSignalReceived($signalId)
{
    global $LONG_LINE, $LOG_BADGE_WIDTH;
    echo chr(7);
    MainLog::log("$LONG_LINE", 2, 2, MainLog::LOG_GENERAL);
    MainLog::log("OS signal #$signalId received");
    MainLog::log("Termination process started", 2);
    terminateSession(true);
}

function onStdinCode($code)
{
    switch($code) {
        case 'hide':
            MainLog::interactiveHideConsoleOutput();
            break;

        case 'show':
            MainLog::interactiveShowConsoleOutput();
            break;
    }
}

function sayAndWait(float $seconds, float $clearSeconds = 2)
{
    global $IS_IN_DOCKER, $SHOW_CONSOLE_OUTPUT;

    $message = "\n";

    if ($seconds < 0) {
        $seconds = 0;
    }

    if ($clearSeconds > $seconds) {
        $clearSeconds = $seconds;
    }

    if ($seconds - $clearSeconds >= 3) {

        if ($SHOW_CONSOLE_OUTPUT) {

            $url = "https://x100.vn.ua/";

            $message .= addUAFlagToLineEnd(
                    "Waiting $seconds seconds. Press Ctrl+C "
                    . Term::bgRed . Term::brightWhite
                    . " now "
                    . Term::clear
                    . ", if you want to terminate this script (correctly)"
                ) . "\n";

            /*$efficiencyMessage = Efficiency::getMessage();
            if ($efficiencyMessage) {
                $message = "\n$efficiencyMessage\n$message";
            } else {
                $message = "\n$message";
            }*/

            if (!SelfUpdate::isOutOfDate()) {

                $lines = [
                    'We need as many attackers as possible to make a really strong DDoS.',
                    'Please, tell you friends about the X100. Post about it in social media.',
                    'It will make our common efforts successful!'
                ];

                foreach ($lines as $line) {
                    $message .= Term::green
                        . addUAFlagToLineEnd($line) . "\n";
                }
                $message .= Term::green
                    . Term::underline
                    . addUAFlagToLineEnd($url)
                    . Term::moveHomeAndUp . Term::moveDown . str_repeat(Term::moveRight, strlen($url) + 1);

            } else {

                $message .= addUAFlagToLineEnd(' ') . "\n";
                $line1 = 'New version of the X100 is available!' . str_pad(' ', 20) . SelfUpdate::getLatestVersion();
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

        } else {
            $message .= "Waiting $seconds seconds. Press Ctrl+C now, if you want to terminate this script (correctly)";
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
    $flagLine = Term::bgUkraineBlue . '  ' . Term::bgUkraineYellow . '  ' . Term::clear;
    $flagLineLength = 4;
    $line .= Term::clear
          .  str_repeat(' ', $LOG_BADGE_WIDTH + 1 + $LOG_WIDTH - mb_strlen(Term::removeMarkup($line)) - $flagLineLength)
          .  $flagLine;
    return $line;
}

function bytesToGiB(?int $bytes) : float
{
    return round($bytes / (1024 * 1024 * 1024), 1);
}

const HUMAN_BYTES_BITS  = 1 << 0;
const HUMAN_BYTES_SHORT = 1 << 1;
function humanBytes(?int $bytes, int $flags = 0) : string
{
    $bitsFlag  = HUMAN_BYTES_BITS & $flags;
    $shortFlag = HUMAN_BYTES_SHORT & $flags;

    $kib = 1024;
    $mib = $kib * $kib;
    $gib = $mib * $kib;
    $tib = $gib * $kib;

    $kb = 1000;
    $mb = $kb * $kb;
    $gb = $mb * $kb;
    $tb = $gb * $kb;

    if        ($bytes >= $tb) {
        $ret = roundLarge($bytes / $tib) . 'T';
    } else if ($bytes >= $gb) {
        $ret = roundLarge($bytes / $gib) . 'G';
    } else if ($bytes >= $mb) {
        $ret = roundLarge($bytes / $mib) . 'M';
    } else if ($bytes >= $kb) {
        $ret = roundLarge($bytes / $kib) . 'K';
    } else if ($bytes > 0) {
        $ret = $bytes . ($bitsFlag ? 'b' : 'B');
    } else {
        $ret = (string) $bytes;
    }

    if (
           !$shortFlag
        &&  $bytes >= $kib
    ) {
        $ret .= $bitsFlag ? 'ib' : 'iB';
    }

    return $ret;
}

function getHumanBytesLabel($title, $rx, $tx, $humanBytesFlags = 0)
{
    return    "$title " . humanBytes($rx + $tx, $humanBytesFlags)
        . '  (received:' . humanBytes($rx, $humanBytesFlags)
        . '/transmitted:'   . humanBytes($tx, $humanBytesFlags) . ')';
}

function humanDuration(?int $seconds) : string
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

    if ($seconds) {
        $ret .= ' ' . $seconds . ' second' . ($seconds > 1 ? 's' : '');
    }

    return trim($ret);
}

function HHMMSSduration($seconds)
{
    $SecondsPerHour = 3600;
    $SecondsPerMinute = 60;
    $MinutesPerHour = 60;

    $hh = intval($seconds / $SecondsPerHour);
    if (strlen($hh) === 1) {
        $hh = '0' . $hh;
    }

    $mm = intval($seconds / $SecondsPerMinute) % $MinutesPerHour;
    if (strlen($mm) === 1) {
        $mm = '0' . $mm;
    }

    $ss = $seconds % $SecondsPerMinute;
    if (strlen($ss) === 1) {
        $ss = '0' . $ss;
    }

    $ret = $mm . ":" . $ss;
    if ($hh !== '00') {
        $ret = $hh . ':' . $ret;
    }

    return $ret;
}

function file_put_contents_secure(string $filename, $data, int $flags = 0, $context = null)
{
    global $NEW_DIR_ACCESS_MODE, $NEW_FILE_ACCESS_MODE;

    $dir = mbDirname($filename);
    if (!is_dir($dir)) {
        mkdir($dir, $NEW_DIR_ACCESS_MODE, true);
    }

    file_put_contents($filename, 'nothing');
    chmod($filename, $NEW_FILE_ACCESS_MODE);
    return file_put_contents($filename, $data, $flags, $context);
}

function procChangePGid($processResource, &$log = '')
{
    $processStatus = proc_get_status($processResource);
    if (! $processStatus['running']) {
        return false;
    }

    $subPid = (int) $processStatus['pid'];
    posix_setpgid($subPid, $subPid);  // Run this code as quickly as possible

    // ---

    $log .= "Script process    PID/PGID/SID/PPID    ";
    $log .= posix_getpid() . '/'
        .  posix_getpgid(posix_getpid()) . '/'
        .  posix_getsid(posix_getpid())  . '/'
        .  posix_getppid() .  "\n";

    // ---

    $subPGid = posix_getpgid($subPid);
    $subSid = posix_getsid($subPid);

    $log .= "Subprocess        PID/PGID/SID         ";
    $log .= "$subPid/$subPGid/$subSid\n";

    if ($subPid !== $subPGid) {
        $log .= "Failed to change subprocess PGID: " . posix_strerror(posix_get_last_error());
        return false;
    }

    return $subPGid;
}

function roundLarge(float $value, $maxPrecision = 2)
{
    if (
           $value < 1
        && $value > -1
    ) {
        $ret = round($value, $maxPrecision);
    } else if (
           $value < 10
        && $value > -10
    ) {
        $ret = round($value, 1);
    } else {
        $ret = (int) round($value, 0);
    }

    if ($ret == 0) {
        $ret = 0;
    }

    return $ret;
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

function resetTimeForLongBrake()
{
    global $isTimeForBrake_lastBreak;
    $isTimeForBrake_lastBreak = time();
}

function _shell_exec(string $command)
{
    $ret = shell_exec($command . '   2>&1');
    if (! mbTrim($ret)) {
        $ret = '';
    }
    return $ret;
}

function echoPassthru($command)
{
    echo Term::green . "\n$command\n\n" . Term::clear;
    passthru($command);
}

function httpGet(string $url, ?int &$httpCode = 0)
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
    curl_close($curl);
    if ($httpCode === 200) {
        return $content;
    } else {
        return false;
    }
}

function getUrlOrigin($url)
{
    $purl = parse_url($url);
    $ret = $purl['scheme'] ?? 'http';
    $ret .= '://';
    $ret .= $purl['host'];

    if (isset($purl['port'])) {
        $ret .= ":" . $purl['port'];
    }

    return $ret;
}

function changeLinuxPermissions(int $permissions, string $toUser, string $toGroup = '', string $toOther = '', bool $remove = false) : int
{
	$x = 01;
	$w = 02;
	$r = 04;

    $u = 0100;
	$g = 010;

    $suid   = 04000;
    $sgid   = 02000;
    $sticky = 01000;

	$sign = $remove ? -01 : 01;

	$permissions += (is_int(strpos($toUser, 'r'))  ? $r * $u : 0) * $sign;	
	$permissions += (is_int(strpos($toUser, 'w'))  ? $w * $u : 0) * $sign;
	$permissions += (is_int(strpos($toUser, 'x'))  ? $x * $u : 0) * $sign;
    $permissions += (is_int(strpos($toUser, 's'))  ? $suid   : 0) * $sign;

	$permissions += (is_int(strpos($toGroup, 'r'))  ? $r * $g : 0) * $sign;		
	$permissions += (is_int(strpos($toGroup, 'w'))  ? $w * $g : 0) * $sign;
	$permissions += (is_int(strpos($toGroup, 'x'))  ? $x * $g : 0) * $sign;
    $permissions += (is_int(strpos($toGroup, 's'))  ? $sgid   : 0) * $sign;
	
	$permissions += (is_int(strpos($toOther, 'r'))  ? $r      : 0) * $sign;
	$permissions += (is_int(strpos($toOther, 'w'))  ? $w      : 0) * $sign;
	$permissions += (is_int(strpos($toOther, 'x'))  ? $x      : 0) * $sign;
    $permissions += (is_int(strpos($toOther, 's'))  ? $sticky : 0) * $sign;
	
	return $permissions;	
}

function trimDisks()
{
    MainLog::log('Sending TRIM to disks', 0, 0, MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
    $commands = [
        '/sbin/swapoff  --all',
        '/sbin/swapon   --all  --discard',
        '/sbin/swapoff  --all',
        '/sbin/swapon   --all',

        '/sbin/fstrim   --all'
    ];

    foreach ($commands as $command) {
        $output = _shell_exec($command);
        if ($output) {
            MainLog::log(_shell_exec($command));
        }
    }

    MainLog::log();
}

function trimFileFromBeginning(string $path, int $trimChunkSize, bool $discardIncompleteLine = false)
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
    //echo "trimChunkSize $trimChunkSize\n";
    if ($discardIncompleteLine) {
        fgets($fCopy);
    }

    while(!feof($fCopy)) {
        $block = fread($fCopy, 1024 * 1024);
        fwrite($fLog, $block);
    }

    fclose($fLog);
    fclose($fCopy);
    unlink($copyPath);
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
            $cell = $row[$i]  ??  '';
            $cell = mb_substr($cell, 0, $columnDefinition['width'] - $trim);
            $cell = mbStrPad($cell, $columnDefinition['width'], ' ', $alignRight  ?  STR_PAD_LEFT : STR_PAD_RIGHT);
            $ret .= $cell;
        }
        $ret .= "\n";
    }
    return $ret;
}

/*
    Checker for getProcessPidWithChildrenPids()
    -------------------------------------------

    $pidsList1 = [];
    getProcessPidWithChildrenPids(1, true, $pidsList1);

    $pidsList2 = [];
    getProcessPidWithChildrenPids(2, true, $pidsList2);

    $pidsList12 = array_unique(array_merge($pidsList1, $pidsList2));
    echo (count($pidsList1)) ."\n";
    echo (count($pidsList2)) ."\n";
    echo (count($pidsList12)) ."\n";

    $linuxProcesses = getLinuxProcesses();
    echo (count($linuxProcesses)) ."\n";
    foreach ($linuxProcesses as $pid => $data) {
        if (!in_array($pid, $pidsList12)) {
            print_r($data);
        }
    }
 */

function getProcessPidWithChildrenPids($pid, bool $skipThreads, &$list = [])
{
    $isFirstCall = !count($list);
    $taskDir = "/proc/$pid/task";
    if (
            in_array($pid, $list)
        || !file_exists($taskDir)
    ) {
        return;
    }

    $dirHandle = @opendir($taskDir);
    if (!is_resource($dirHandle)) {
        return;
    }

    $list[] = $pid;
    while (false !== ($taskDirSubDir = readdir($dirHandle))) {
        if ($taskDirSubDir === '.'  ||  $taskDirSubDir === '..') {
            continue;
        }

        $threadId = (int) $taskDirSubDir;
        if ($threadId !== $pid  &&  !$skipThreads) {
            getProcessPidWithChildrenPids($threadId, false, $list);
        }

        $childrenPids = (string) @file_get_contents($taskDir . "/$pid/children");
        foreach (explode(' ', $childrenPids)  as $childPid) {
            if ($childPid) {
                $childPid = (int) $childPid;
                getProcessPidWithChildrenPids($childPid, $skipThreads, $list);
            }
        }
    }

    if ($isFirstCall) {
        $list = array_unique($list);
        sort($list);
    }

    closedir($dirHandle);
}

function getProcessChildrenPids($parentPid, bool $skipSubTasks, &$list)
{
    getProcessPidWithChildrenPids($parentPid, $skipSubTasks, $list);
    if (isset($list[0])) {
        unset($list[0]);
    }
}

function killZombieProcesses(array $linuxProcesses, array $skipProcessesWithPids, $commandPart)
{
    foreach ($linuxProcesses as $pid => $data) {
        if (
                strpos($data['cmd'], $commandPart) !== false
            && !in_array($pid, $skipProcessesWithPids)
        ) {
            MainLog::log("Kill pid=$pid ppid={$data['ppid']} pgid={$data['pgid']} cmd={$data['cmd']}", 2, 0, MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);

            @posix_kill($pid, SIGKILL);
        }
    }
}

function getLinuxProcesses() : array
{
    $ret = [];
    $out = _shell_exec('ps -e -o pid=,ppid=,pgid=,cmd=');
    if (preg_match_all('#^\s*(\d+)\s+(\d+)\s+(\d+)(.*)$#mu', $out, $matches) > 0) {
        for ($i = 0; $i < count($matches[0]); $i++) {
            $pid  = (int)$matches[1][$i];
            $ppid = (int)$matches[2][$i];
            $pgid = (int)$matches[3][$i];
            $cmd  = mbTrim($matches[4][$i]);
            $ret[$pid] = [
                'pid'  => $pid,
                'ppid' => $ppid,
                'pgid' => $pgid,
                'cmd'  => $cmd
            ];
        }
    }
    return $ret;
}

function intRound($var) : int
{
    return (int) round($var);
}

function fitBetweenMinMax($minPossible, $maxPossible, $value)
{
    if (
            $minPossible !== false
        &&  $maxPossible !== false
        &&  $minPossible > $maxPossible
    ) {
        return null;
    }

    if ($minPossible !== false) {
        $value = max($minPossible, $value);
    }

    if ($maxPossible !== false) {
        $value = min($maxPossible, $value);
    }

    return $value;
}

function val($something, ...$keys)
{
    if (isset($keys[0])  &&  is_array($keys[0])) {
        $keys = $keys[0];
    }

    foreach ($keys as $key) {
        if (is_array($something)  &&  isset($something[$key])) {
            $something = $something[$key];
        } else if (
            is_string($something)
            && is_int($key)
            && isset($something[$key])
        ) {
            $something = $something[$key];
        } else if (is_object($something)  &&  isset($something->$key)) {
            $something = $something->$key;
        } else {
            return null;
        }
    }

    return $something;
}


function getArrayFirstValue($array)
{
    if (!is_array($array)  ||  !count($array)) {
        return null;
    }

    reset($array);
    return current($array);
}

function getArrayLastValue($array)
{
    if (!is_array($array)  ||  !count($array)) {
        return null;
    }

    end($array);
    return current($array);
}

function getArrayFreeIntKey(array $arr)
{
    $key = 0;
    while (isset($arr[$key])) {
        $key++;
    }
    return $key;
}

function sumSameArrays(...$arrays)
{
    $ret = $arrays[0];
    for ($arrayIndex = 1, $max = (count($arrays)); $arrayIndex < $max; $arrayIndex++) {
        $array = $arrays[$arrayIndex];
        foreach($array as $key => $value) {
            if (is_numeric($value)) {
                $ret[$key] += $value;
            } else if (is_array($value)) {
                $ret[$key] = sumSameArrays($ret[$key], $value);
            }
        }
    }
    return $ret;
}

function filePregReplace($patterns, $replacements, $sourcePath, $destPath = null)
{
    $content = file_get_contents($sourcePath);
    $replacedContent = preg_replace($patterns, $replacements, $content);
    if (is_string($replacedContent)) {
        $destPath = $destPath ?: $sourcePath;
        file_put_contents_secure($destPath, $replacedContent);
        return true;
    } else {
        return false;
    }
}

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
