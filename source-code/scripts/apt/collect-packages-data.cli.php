#!/usr/bin/env php
<?php

passthru('apt autoremove');

require_once __DIR__ . '/../../common.php';

$staredAt = time();

$DATA['installedPackages'] = getInstalledPackages();
$DATA['depends']  = [];
$DATA['rDepends'] = [];
$DATA['sizeOnDisk'] = [];

foreach ($DATA['installedPackages'] as $packageName) {
    echo "$packageName\n";
    //$DATA['depends'][$packageName]  = getDepends($packageName);
    //$DATA['rDepends'][$packageName] = getRDepends($packageName);
    $DATA['remove'][$packageName]   = getRemoveInfo($packageName);
    getFilesInfo($packageName, $sizeOnDisk, $lastAccess);
    $DATA['sizeOnDisk'][$packageName] = $sizeOnDisk;
    $DATA['lastAccess'][$packageName] = $lastAccess;
}

asort($DATA['sizeOnDisk'], SORT_NUMERIC);
file_put_contents(__DIR__ . '/packages-data.json', json_encode($DATA, JSON_PRETTY_PRINT));

echo "\n\nData was collected during " . humanDuration(time() - $staredAt) . "\n\n";

//---------------------------------------------------------------------------------------

function getInstalledPackages() : array
{
    $stdout = shell_exec('apt list  --installed   2>/dev/null');
    $regExp = <<<PhpRegExp
          #^[^\/]+#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);
    if (isset($matches[0][0])) {
        unset($matches[0][0]);  // Remove header
    }

    $ret = $matches[0] ?? [];
    sort($ret);
    return $ret;
}

function getRDepends($packageName) : array
{
    $stdout = shell_exec("apt-cache  --installed  rdepends  $packageName");
    $regExp = <<<PhpRegExp
          #^  (.*)$#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);

    $ret = $matches[1] ?? [];
    sort($ret);
    return $ret;
}

function getDepends($packageName) : array
{
    //https://askubuntu.com/questions/83553/what-is-the-difference-between-dependencies-and-pre-depends
    $stdout = shell_exec("apt-cache  --installed  depends  $packageName");
    $regExp = <<<PhpRegExp
          #^.*?Depends: (.*)$#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);

    $ret = $matches[1] ?? [];
    sort($ret);
    return $ret;
}

function getFilesInfo($packageName, &$sizeOnDisk = 0, &$lastAccess = 0)
{
    $stdout = shell_exec("dpkg-query -L $packageName");
    preg_match_all('#^\/.*#m', $stdout, $matches);
    $packageFiles = array_map('trim', $matches[0]);

    if (isset($packageFiles[0])  &&  $packageFiles[0] === '/.') {
        unset($packageFiles[0]);
    }

    $sizeOnDisk = $lastAccess = 0;
    foreach ($packageFiles as $filePath) {
        if (!file_exists($filePath)) {
            continue;
        }
        $sizeOnDisk += filesize($filePath);
        $lastAccess = max(
            $lastAccess,
            filemtime($filePath),
            filectime($filePath),
            fileatime($filePath),
        );
    }
}

function getRemoveInfo($packageName)
{
    $stdout = shell_exec("apt remove --dry-run $packageName   2>/dev/null");

    preg_match_all('#^Remv (.+?) \[#m', $stdout, $matches);
    $requireRemove = $matches[1] ?? [];
    $key = array_search($packageName, $requireRemove);
    if ($key !== false) {
        unset($requireRemove[$key]);
    }
    sort($requireRemove);

    //---

    $regExp = <<<PhpRegExp
              #The following packages were automatically installed and are no longer required:(.*?)Use 'apt autoremove' to remove them#s
              PhpRegExp;
    preg_match(trim($regExp), $stdout, $matches);
    $str = $matches[1] ?? '';
    $str = preg_replace('#\s+#', "\n", mbTrim($str));
    $autoRemove = mbSplitLines($str);

    return [
        'requireRemoveList' => $requireRemove,
        'autoRemoveList' => $autoRemove
    ];
}
