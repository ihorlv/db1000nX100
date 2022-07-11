#!/usr/bin/env php
<?php

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/OpenVpnCommon.php';

$netInterface = getenv('dev');
$envFilePath = OpenVpnCommon::getEnvFilePath($netInterface);
$envJson = json_encode(getenv(), JSON_PRETTY_PRINT);
file_put_contents_secure($envFilePath, $envJson);