#!/usr/bin/env php
<?php

passthru('reset');
require_once __DIR__ . '/../../common.php';
$DATA = json_decode(file_get_contents(__DIR__ . '/packages-data.json'), JSON_OBJECT_AS_ARRAY);

$rootPackages = [];
foreach ($DATA['remove'] as $packageName => $removeInfo) {
    if (count($removeInfo['requireRemoveList']) === 0) {
        $sumSize = $DATA['sizeOnDisk'][$packageName];
        foreach ($removeInfo['autoRemoveList'] as $autoRemovePackageName) {
            $sumSize += $DATA['sizeOnDisk'][$autoRemovePackageName];
        }

        $rootPackages[$packageName] = $sumSize;
    }
}

asort($rootPackages);
foreach ($rootPackages as $packageName => $sumSize) {
    echo "$packageName " . humanBytes($sumSize) . " " . date('c', $DATA['lastAccess'][$packageName]) . "\n";
}