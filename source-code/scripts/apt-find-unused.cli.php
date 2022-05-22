#!/usr/bin/env php
<?php

passthru('reset');
require_once __DIR__ . '/../common.php';

$instPackages = getInstPackages();
$instPackagesDepends = getInstPackagesDepends($instPackages);

foreach ($instPackages as $packageName) {
    $rDepends = getRDepends($packageName);
    $installedRDepends = array_intersect($instPackages, $rDepends);

    if (! count($installedRDepends)) {
        $depends = getDependsRecursive($packageName, $instPackages, $instPackagesDepends);

        echo "$packageName\n";
        echo "rDepends\n"           . print_r($rDepends, true);
        echo "depends\n"            . print_r($depends, true);
        echo "installedRDepends\n"  . print_r($installedRDepends, true);
        echo "-------------------------------------------------------------------\n";
    }
}

//---------------------------------------------------------------------------------------

function getInstPackages() : array
{
    $stdout = shell_exec('apt list --installed');
    $regExp = <<<PhpRegExp
          #^[^\/]+#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);
    if (isset($matches[0][0])) {
        unset($matches[0][0]);  // Remove header
    }
    return $matches[0] ?? [];
}

function getRDepends($packageName) : array
{
    $stdout = shell_exec("apt-cache rdepends $packageName");
    $regExp = <<<PhpRegExp
          #^  (.*)$#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);
    return $matches[1] ?? [];
}

function getDepends($packageName) : array
{
    //https://askubuntu.com/questions/83553/what-is-the-difference-between-dependencies-and-pre-depends
    $stdout = shell_exec("apt-cache depends $packageName");
    $regExp = <<<PhpRegExp
          #^.*?Depends: (.*)$#m
          PhpRegExp;
    preg_match_all(trim($regExp), $stdout, $matches);
    return $matches[1] ?? [];
}

function getInstPackagesDepends($instPackages) : array
{
    $ret = [];
    foreach ($instPackages as $packageName) {
        $ret[$packageName] = getDepends($packageName);
    }
    return $ret;
}

function getDependsRecursive($packageName, $instPackages, $instPackagesDepends) : array
{
    //echo "$packageName\n";
    $ret = [];
    $subPackagesNames = $instPackagesDepends[$packageName] ?? [];
    foreach ($subPackagesNames as $subPackageName) {
        if (!in_array($subPackageName, $instPackages)) {
            continue;  // Skip not installed dependencies
        }

        $r = getDependsRecursive($subPackageName, $instPackages, $instPackagesDepends);
        $ret = array_merge($ret, $r);
    }
    return array_unique($ret);
}



/*$PACKAGES = [];
$ACCESS_TIME_BEFORE = 1 * 60 * 60;

$stdout = shell_exec('apt list --installed');

$regExp = <<<PhpRegExp
          #^[^\/]+#m
          PhpRegExp;
preg_match_all(trim($regExp), $stdout, $matches);
unset($matches[0][0]);

foreach ($matches[0] as $packageName) {
    $stdout = shell_exec("dpkg-query -L $packageName");
    preg_match_all('#^\/.*#m', $stdout, $matches);
    $packageFiles = array_map('trim', $matches[0]);
    if ($packageFiles[0] === '/.') {
        unset($packageFiles[0]);
    }

    $beforeUt = time() - $ACCESS_TIME_BEFORE;
    $packageFilesSumSize = 0;
    $packageFilesLastAccessTime = 0;
    foreach ($packageFiles as $filePath) {
        if (!file_exists($filePath)) {
            continue;
        }
        $packageFilesSumSize += filesize($filePath);
        $packageFilesLastAccessTime = max([
            $packageFilesLastAccessTime,
            filectime($filePath),
            filemtime($filePath)
        ]);
    }

    $item = [
        '$packageName' => $packageName,
        'packageFilesSumSize' => $packageFilesSumSize,
        'packageFilesLastAccessTime' => $packageFilesLastAccessTime
    ];

    $PACKAGES['all'][] = $item;
    if ($packageFilesLastAccessTime >= $beforeUt) {
        $PACKAGES['recent'][] = $item;
    } else {
        $PACKAGES['old'][] = $item;
    }
}

print_r($PACKAGES);
*/


