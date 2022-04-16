<?php

$NEW_DIR_ACCESS_MODE = 0770;
$NEW_FILE_ACCESS_MODE = 0660;
$TEMP_DIR = '/tmp/hack-linux';
$HOME_DIR = '/root/DDOS';
@mkdir($TEMP_DIR, $NEW_DIR_ACCESS_MODE, true);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions-mb-string.php';
require_once __DIR__ . '/Term.php';
