#!/usr/bin/env php
<?php

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/ResourcesConsumption.php';
global $TEMP_DIR;

$cliOptions = getopt('', ['main_cli_php_pid:', 'time_interval:']);

$mainCliPhpPid = $cliOptions['main_cli_php_pid'] ?? null;
$timeInterval  = $cliOptions['time_interval']    ?? 10;

if (! $mainCliPhpPid) {
    echo "Usage: --main_cli_php_pid 1234  --time_interval 10\n\n";
    die();
}

$mainCliPhpPid = (int) $mainCliPhpPid;
while (true) {
    $systemCpuStatsOnStart = ResourcesConsumption::readCpuStats();
    $processesStatsOnStart = ResourcesConsumption::getProcessesStats($mainCliPhpPid);

    echo "--\n";
    //------------------------------------------------
    waitForOsSignals($timeInterval, 'signalReceived');
    //------------------------------------------------

    $systemCpuStatsOnFinish = ResourcesConsumption::readCpuStats();
    $systemCpu = ResourcesConsumption::cpuStatCalculateAverageCPUUsage($systemCpuStatsOnStart, $systemCpuStatsOnFinish);

    $processesStatsOnEnd = ResourcesConsumption::getProcessesStats($mainCliPhpPid);
    $processesCpuUsage = ResourcesConsumption::processesCalculateAverageCPUUsage($processesStatsOnStart, $processesStatsOnEnd);
    $mainCliPhpCpu     = ResourcesConsumption::processesCalculateAverageCPUUsage($processesStatsOnStart, $processesStatsOnEnd, $mainCliPhpPid);

    $memoryStat = ResourcesConsumption::readMemoryStats();
    $systemMem = 100 - roundLarge($memoryStat['MemFree'] * 100 / $memoryStat['MemTotal']);


    $processesMemPages = array_sum(array_column($processesStatsOnEnd['process'], 'rss'));
    $processesMemBytes = $processesMemPages * $memoryStat['pageSize'];
    $processesMem      = roundLarge($processesMemBytes * 100 / $memoryStat['MemTotal']);

    echo $memoryStat['MemTotal'] . " $processesMemPages $processesMemBytes $processesMem\n";

    $statObj = new stdClass();
    $statObj->systemCpu       = $systemCpu;
    $statObj->processesCpu    = $processesCpuUsage;
    $statObj->mainCliPhpCpu   = $mainCliPhpCpu;
    $statObj->processesMem    = $processesMem;
    $statObj->systemMem       = $systemMem;

    $statJson                 = json_encode($statObj);
    echo "$statJson\n";

}

//--------------------------------------------------

function signalReceived($signalId)
{
    echo "Signal $signalId received. Exit\n\n";
    exit(0);
}

/*function calculateProcessesResourcesUsage($pid, &$psOutputArray = '', &$mainCliPhpCpu = null, &$processesCpu = 0, &$processesMem = 0, &$processesCount = 0)
{
    if (! $psOutputArray) {
        $psOutput = _shell_exec('ps -e -o pid=,ppid=,%cpu=,%mem,cmd=');
        $psOutputArray = psOutputToArray($psOutput);
    }

    $pidRow   = getRowsByCellValue($psOutputArray, 'pid',  $pid);
    $ppidRows = getRowsByCellValue($psOutputArray, 'ppid', $pid);

    if (count($pidRow)) {
        $pidRow = array_shift($pidRow);
        if ($processesCount === 0) {
            $mainCliPhpCpu = $pidRow['cpu'];
        }

        //echo '  ' . $pidRow['cmd'] . "\n";
        $processesCpu += $pidRow['cpu'];
        $processesMem += $pidRow['mem'];
        $processesCount++;
    }

    foreach ($ppidRows as $ppidRow) {
        calculateProcessesResourcesUsage($ppidRow['pid'], $psOutputArray, $mainCliPhpCpu, $processesCpu, $processesMem, $processesCount);
    }

}

function getRowsByCellValue(array $array, $cellName, $cellValue)
{
    $column = array_column($array, $cellName);
    $keys   = array_keys($column, $cellValue);
    $ret = [];
    foreach ($keys as $key) {
        $ret[$key] = $array[$key];
    }
    return $ret;
}

function psOutputToArray($psOutput)
{
    $regExp = <<<PhpRegExp
#^\s+(\d+)\s+(\d+)\s+([\d\.]+)\s+([\d\.]+)\s+(.*)$#m
PhpRegExp;

    if (preg_match_all($regExp, $psOutput, $matches) < 1) {
        return false;
    }

    $ret = [];
    for ($i = 0; $i < count($matches[0]); $i++) {
        $ret[] = [
            'line' =>  mbTrim($matches[0][$i]),
            'pid'  => (int)   $matches[1][$i],
            'ppid' => (int)   $matches[2][$i],
            'cpu'  => (float) $matches[3][$i],
            'mem'  => (float) $matches[4][$i],
            'cmd'  =>         $matches[5][$i]
        ];
    }

    return $ret;
}*/

