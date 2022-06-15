<?php

function getFilesListOfDirectory(string $dir, bool $includeDirs = false) : array
{
    $ret = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $iterator->rewind();
    while($iterator->valid()) {
        $basename = $iterator->getBasename();
        if (!(
                 $basename === '..'
            ||  ($basename === '.'  &&  !$includeDirs)
        )) {
            $ret[] = $iterator->getPathname();
        }
        $iterator->next();
    }
    return array_unique($ret);  // array_unique because the of bug. same paths were in list twice
}


const SEARCH_IN_FILES_LIST_MATCH_DIR          = 1 << 1;
const SEARCH_IN_FILES_LIST_MATCH_DIR_BASENAME = 1 << 2;
const SEARCH_IN_FILES_LIST_MATCH_FILENAME     = 1 << 3;
const SEARCH_IN_FILES_LIST_MATCH_BASENAME     = 1 << 4;
const SEARCH_IN_FILES_LIST_MATCH_EXT          = 1 << 5;
const SEARCH_IN_FILES_LIST_RETURN_DIRS        = 1 << 6;
const SEARCH_IN_FILES_LIST_RETURN_FILES       = 1 << 7;

function searchInFilesList(array $list, int $flags, string $searchRegExp, string $regExpModifier = 'u') : array
{
    $searchRegExp = "#$searchRegExp#$regExpModifier";
    $ret = [];
    $alreadySearchedIn = [];
    foreach ($list as $path) {

               if (SEARCH_IN_FILES_LIST_MATCH_DIR           & $flags) {
            $searchIn = mbDirname($path);
        } else if (SEARCH_IN_FILES_LIST_MATCH_DIR_BASENAME  & $flags) {
            $searchIn = mbBasename(mbDirname($path));
        } else if (SEARCH_IN_FILES_LIST_MATCH_FILENAME      & $flags) {
            $searchIn = mbFilename($path);
        } else if (SEARCH_IN_FILES_LIST_MATCH_BASENAME      & $flags) {
            $searchIn = mbBasename($path);
        } else if (SEARCH_IN_FILES_LIST_MATCH_EXT           & $flags) {
            $searchIn = mbExt($path);
        } else {
            $searchIn = $path;
        }

        if (isset($alreadySearchedIn[$searchIn])) {
            $match = $alreadySearchedIn[$searchIn];
        } else {
            $match = preg_match($searchRegExp, $searchIn) > 0;
            $alreadySearchedIn[$searchIn] = $match;
            //echo "$searchIn\n";
        }

        if (!$match) {
            continue;
        }

        $basename = mbBasename($path);
        if (
                (SEARCH_IN_FILES_LIST_RETURN_DIRS & $flags)
            &&  $basename === '.'
        ) {
            $ret[] = mbDirname($path);
        }

        if (
                (SEARCH_IN_FILES_LIST_RETURN_FILES & $flags)
            &&  $basename !== '.'
        ) {
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
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getPathname());
        }
        rmdir($dir);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function streamReadLines($stream, float $wait = 0.5) : string
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

    ResourcesConsumption::startTaskTimeTracking('_echo');
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

    $messageLines = mbSplitLines($message);

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

    MainLog::log($output, 0, 0, MainLog::LOG_HACK_APPLICATION);
    ResourcesConsumption::stopTaskTimeTracking('_echo');
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

function waitForOsSignals(float $floatSeconds, $callback = 'onOsSignalReceived')
{
    if (class_exists('ResourcesConsumption')) {
        ResourcesConsumption::startTaskTimeTracking('waitForOsSignals');
    }
    $intSeconds = floor($floatSeconds);
    $nanoSeconds = ($floatSeconds - $intSeconds) * pow(10, 9);
    $signalId = pcntl_sigtimedwait([SIGTERM, SIGINT], $info,  $intSeconds,  $nanoSeconds);
    if (class_exists('ResourcesConsumption')) {
        ResourcesConsumption::stopTaskTimeTracking('waitForOsSignals');
    }
    if (gettype($signalId) === 'integer'  &&  $signalId > 0) {
        $callback($signalId);
    }
}

function onOsSignalReceived($signalId)
{
    global $LONG_LINE, $LOG_BADGE_WIDTH;
    echo chr(7);
    MainLog::log("$LONG_LINE", 2, 2, MainLog::LOG_GENERAL);
    MainLog::log("OS signal #$signalId received");
    MainLog::log("Termination process started", 2);
    terminateSession();
    MainLog::log("The script exited");
    $out = _shell_exec('killall x100-suid-run.elf');
    //MainLog::log($out);
    exit(0);
}

function sayAndWait(float $seconds, float $clearSeconds = 2)
{
    global $IS_IN_DOCKER;
    $message = '';

    if ($seconds < 0) {
        $seconds = 0;
    }

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
                'We need as many attackers as possible to make a really strong DDoS.',
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

    if        ($bytes > $tb) {
        $ret = roundLarge($bytes / $tib) . 'T';
    } else if ($bytes > $gb) {
        $ret = roundLarge($bytes / $gib) . 'G';
    } else if ($bytes > $mb) {
        $ret = roundLarge($bytes / $mib) . 'M';
    } else if ($bytes > $kb) {
        $ret = roundLarge($bytes / $kib) . 'K';
    } else if ($bytes > 0) {
        $ret = $bytes . ($bitsFlag ? 'b' : 'B');
    } else {
        $ret = (string) $bytes;
    }

    if (!$shortFlag) {
        $ret .= $bitsFlag ? 'ib' : 'iB';
    }

    return $ret;
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

function roundLarge(float $value)
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
    if ($httpCode === 200) {
        return $content;
    } else {
        return false;
    }
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
    MainLog::log('Sending TRIM to disks', 0, 0, MainLog::LOG_GENERAL_OTHER);
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
    echo "trimChunkSize $trimChunkSize\n";
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

function getNetworkInterfaceStats(string $interfaceName, string $networkNamespaceName = '')
{
    $command = 'ip -s link';
    if ($networkNamespaceName) {
        $command = "ip netns exec $networkNamespaceName   " . $command;
    }

    $netStat = _shell_exec($command);
    $regExp = <<<PhpRegExp
                  #\d+:([^:@]+).*?\n.*?\n.*?\n\s+(\d+).*?\n.*?\n\s+(\d+)#
                  PhpRegExp;
    if (preg_match_all(trim($regExp), $netStat, $matches) > 0) {
        for ($i = 0 ; $i < count($matches[0]) ; $i++) {
            $interface        = trim($matches[1][$i]);
            $rx               = (int) trim($matches[2][$i]);
            $tx               = (int) trim($matches[3][$i]);
            $obj              = new stdClass();
            $obj->received    = $rx;
            $obj->transmitted = $tx;
            $interfacesArray[$interface] = $obj;
        }
    }

    if (isset($interfacesArray[$interfaceName])) {
        return $interfacesArray[$interfaceName];
    } else {
        return false;
    }
}

function getDefaultNetworkInterface()
{
    $out = _shell_exec('ip route show');
    $regExp = '#^default.* dev (.*)$#mu';
    if (preg_match($regExp, $out, $matches) !== 1) {
        return false;
    }
    return trim($matches[1]);
}

function getProcessPidWithChildrenPids($pid, bool $skipSubTasks, &$list)
{
    $taskDir = "/proc/$pid/task";
    $dirHandle = @opendir($taskDir);
    if (!is_resource($dirHandle)) {
        return;
    }
    if (!in_array($pid, $list)) {
        $list[] = $pid;
        while (false !== ($subDir = readdir($dirHandle))) {
            if ($subDir === '.'  ||  $subDir === '..') {
                continue;
            }
            $childPid = (int) $subDir;

            if ($childPid !== $pid  &&  !$skipSubTasks) {
                getProcessPidWithChildrenPids($childPid, $skipSubTasks, $list);
            }

            $childrenPids = (string) @file_get_contents($taskDir . "/$pid/children");
            foreach (explode(' ', $childrenPids)  as $childPid) {
                if ($childPid) {
                    $childPid = (int) $childPid;
                    getProcessPidWithChildrenPids($childPid, $skipSubTasks, $list);
                }
            }
        }
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

function intRound($var)
{
    return (int) round($var);
}

function val($objectOrArray, ...$keys)
{
    if (isset($keys[0])  &&  is_array($keys[0])) {
        $keys = $keys[0];
    }

    foreach ($keys as $key) {
               if (is_array($objectOrArray)  && isset($objectOrArray[$key])) {
            $objectOrArray = $objectOrArray[$key];
        } else if (is_object($objectOrArray)  && isset($objectOrArray->$key)) {
            $objectOrArray = $objectOrArray->$key;
        } else {
            return null;
        }
    }

    return $objectOrArray;
}


function getArrayFirstValue($array)
{
    if (!is_array($array)  ||  !count($array)) {
        return null;
    }

    reset($array);
    return current($array);
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
