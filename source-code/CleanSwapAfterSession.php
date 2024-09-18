<?php
class CleanSwapAfterSession
{
    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources'], 9);
    }

    public static function actionAfterCalculateResources()
    {
        Actions::addAction('DelayAfterSession', [static::class, 'actionDelayAfterSession']);
    }

    public static function actionDelayAfterSession()
    {
        $usageValues = ResourcesConsumption::$pastSessionUsageValues;
        if (
               (isset($usageValues['systemAverageSwapUsage'])  &&  $usageValues['systemAverageSwapUsage'] > 0)
            || (isset($usageValues['systemPeakSwapUsage'])  &&  $usageValues['systemPeakSwapUsage'] > 0)
        ) {
            MainLog::log(_shell_exec('/usr/sbin/swapoff --all'), 1, 0, MainLog::LOG_GENERAL_ERROR);
            sleep(1);
            MainLog::log(_shell_exec('/usr/sbin/swapon  --discard  --all'), 1, 0, MainLog::LOG_GENERAL_ERROR);
        }
    }
}

CleanSwapAfterSession::constructStatic();