<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions-mb-string.php';
require_once __DIR__ . '/Term.php';

if (file_exists(__DIR__ . '/ActionsSecret.php')) {
    require_once __DIR__ . '/ActionsSecret.php';
} else {
    require_once __DIR__ . '/Actions.php';
}

$LOGS_ENABLED = true;
$NEW_DIR_ACCESS_MODE =  changeLinuxPermissions(0, 'rwx', 'rx', 'rx');
$NEW_FILE_ACCESS_MODE = changeLinuxPermissions(0, 'rw',  'r',  '');
$HOME_DIR = __DIR__;
$TEMP_DIR = '/tmp/x100';
$CPU_ARCHITECTURE = trim(_shell_exec('uname -m'));

$STDIN = fopen('php://stdin', 'r');
stream_set_blocking($STDIN, false);

require_once __DIR__ . '/SelfUpdate.php';

$LOG_FILE_MAX_SIZE_MIB = 10; // Temporary value, before init
require_once __DIR__ . '/MainLog.php';
