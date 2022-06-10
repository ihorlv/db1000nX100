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
    $processesMem = ResourcesConsumption::processesCalculatePeakMemoryUsage($processesStatsOnEnd, $memoryStat);

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