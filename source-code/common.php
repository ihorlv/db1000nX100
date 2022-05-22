<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions-mb-string.php';

$LOGS_ENABLED = true;
$NEW_DIR_ACCESS_MODE =  changeLinuxPermissions(0, 'rwx', 'rx', 'rx');
$NEW_FILE_ACCESS_MODE = changeLinuxPermissions(0, 'rw',  'r',  '');
$HOME_DIR = __DIR__;
$TEMP_DIR = '/tmp/db1000nX100';
$CPU_ARCHITECTURE = trim(_shell_exec('uname -m'));

require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/SelfUpdate.php';
require_once __DIR__ . '/MainLog.php';