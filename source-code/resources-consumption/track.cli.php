#!/usr/bin/env php
<?php

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/LinuxResources.php';
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

    echo '-';
    $x100ProcessesPidsList = [];
    getProcessPidWithChildrenPids($mainCliPhpPid, true, $x100ProcessesPidsList);
    $x100ProcessesStatsOnStart  = LinuxResources::getAllProcessesStats($x100ProcessesPidsList);
    $systemCpuStatsOnStart      = LinuxResources::readSystemCpuStats();
    echo '+';

    //------------------------------------------------
    waitForOsSignals($timeInterval, 'signalReceived');
    //------------------------------------------------

    echo '-';
    $x100ProcessesPidsList = [];
    getProcessPidWithChildrenPids($mainCliPhpPid, true, $x100ProcessesPidsList);
    $x100ProcessesStatsOnEnd = LinuxResources::getAllProcessesStats($x100ProcessesPidsList);
    $systemCpuStatsOnEnd     = LinuxResources::readSystemCpuStats();
    $systemMemoryStatsOnEnd  = LinuxResources::readSystemMemoryStats();
    echo "+\n";

    $systemCpuUsage  = LinuxResources::calculateSystemCpuUsagePercentage($systemCpuStatsOnStart, $systemCpuStatsOnEnd);
    $systemRamUsage  = LinuxResources::calculateSystemRamUsagePercentage($systemMemoryStatsOnEnd);
    $systemSwapUsage = LinuxResources::calculateSystemSwapUsagePercentage($systemMemoryStatsOnEnd);
    $systemTmpUsage  = LinuxResources::calculateSystemTmpUsagePercentage();

    $x100ProcessesCpuUsage  = LinuxResources::calculateProcessesCpuUsagePercentage($x100ProcessesStatsOnStart, $x100ProcessesStatsOnEnd);
    $x100ProcessesMemUsage  = LinuxResources::calculateProcessesMemoryUsagePercentage($x100ProcessesStatsOnEnd, $systemMemoryStatsOnEnd);

    $x100MainCliPhpCpuUsage = LinuxResources::calculateProcessesCpuUsagePercentage(
        filterProcessesForParticularPid($x100ProcessesStatsOnStart, $mainCliPhpPid),
        filterProcessesForParticularPid($x100ProcessesStatsOnEnd, $mainCliPhpPid)
    );
    $x100MainCliPhpMemUsage = LinuxResources::calculateProcessesMemoryUsagePercentage(
        filterProcessesForParticularPid($x100ProcessesStatsOnEnd, $mainCliPhpPid),
        $systemMemoryStatsOnEnd
    );

    // ---

    $db1000nProcessesCpuUsage = LinuxResources::calculateProcessesCpuUsagePercentage(
        filterDb1000nProcesses($x100ProcessesStatsOnStart),
        filterDb1000nProcesses($x100ProcessesStatsOnEnd)
    );
    $db1000nProcessesMemUsage = LinuxResources::calculateProcessesMemoryUsagePercentage(
        filterDb1000nProcesses($x100ProcessesStatsOnEnd),
        $systemMemoryStatsOnEnd
    );

    // ---

    $distressProcessesCpuUsage = LinuxResources::calculateProcessesCpuUsagePercentage(
        filterDistressProcesses($x100ProcessesStatsOnStart),
        filterDistressProcesses($x100ProcessesStatsOnEnd)
    );
    $distressProcessesMemUsage = LinuxResources::calculateProcessesMemoryUsagePercentage(
        filterDistressProcesses($x100ProcessesStatsOnEnd),
        $systemMemoryStatsOnEnd
    );

    // ---

    $statObj = new stdClass();
    $statObj->timestamp  = time();

    $statObj->systemCpu  = $systemCpuUsage;
    $statObj->systemRam  = $systemRamUsage;
    $statObj->systemSwap = $systemSwapUsage;
    $statObj->systemTmp  = $systemTmpUsage;

    $statObj->x100ProcessesCpu    = $x100ProcessesCpuUsage;
    $statObj->x100ProcessesMem    = $x100ProcessesMemUsage;

    $statObj->x100MainCliPhpCpu   = $x100MainCliPhpCpuUsage;
    $statObj->x100MainCliPhpMem   = $x100MainCliPhpMemUsage;

    $statObj->distressProcessesCpu = $distressProcessesCpuUsage;
    $statObj->distressProcessesMem = $distressProcessesMemUsage;

    $statJson                    = json_encode($statObj);
    echo "$statJson\n";
}

//--------------------------------------------------

function filterDb1000nProcesses($x100ProcessesStats)
{
    foreach ($x100ProcessesStats['processes'] as $pid => $data) {
        if ( strpos($data['command'], '/root/DDOS/DB1000N/db1000n') !== 0) {
            unset($x100ProcessesStats['processes'][$pid]);
        }
    }
    return $x100ProcessesStats;
}

function filterDistressProcesses($x100ProcessesStats)
{
    foreach ($x100ProcessesStats['processes'] as $pid => $data) {
        if ( strpos($data['command'], '/root/DDOS/DISTRESS/distress') !== 0) {
            unset($x100ProcessesStats['processes'][$pid]);
        }
    }
    return $x100ProcessesStats;
}

function filterProcessesForParticularPid($x100ProcessesStats, $particularPid)
{
    foreach ($x100ProcessesStats['processes'] as $pid => $data) {
        if ($pid !== $particularPid) {
            unset($x100ProcessesStats['processes'][$pid]);
        }
    }
    return $x100ProcessesStats;
}

function signalReceived($signalId)
{
    echo "\n\n" . time() .": Signal $signalId received. Exit\n\n";
    exit(0);
}