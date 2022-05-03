<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions-mb-string.php';


$NEW_DIR_ACCESS_MODE =  changeLinuxPermissions(0, 'rwx', 'rx', 'rx');
$NEW_FILE_ACCESS_MODE = changeLinuxPermissions(0, 'rw',  'r',  '');
$TEMP_DIR = '/tmp/db1000nX100';
$HOME_DIR = '/root/DDOS';
@mkdir($TEMP_DIR, $NEW_DIR_ACCESS_MODE, true);
$CPU_ARCHITECTURE = trim(_shell_exec('uname -m'));


require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/SelfUpdate.php';
require_once __DIR__ . '/MainLog.php';