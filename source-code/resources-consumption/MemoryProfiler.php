<?php

/*
    https://stackoverflow.com/questions/2192657/how-to-determine-the-memory-footprint-size-of-a-variable
    install of required packets:
        export PATH="/usr/sbin:$PATH"
        apt install libjudy-dev libjudydebian1
        pecl install memprof
        echo "extension=memprof.so" > /etc/php/7.4/mods-available/memprof.ini
        phpenmod -v 7.4 memprof
*/

class MemoryProfiler
{

    private static bool $memoryProfilerEnabled = false;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources']);
    }

    public static function actionAfterCalculateResources()
    {
        if (
                !static::$memoryProfilerEnabled
            ||  !SelfUpdate::$isDevelopmentVersion
        ) {
            return;
        }

        Actions::addAction('DelayAfterSession', [static::class, 'actionDelayAfterSession']);
    }

    public static function actionDelayAfterSession()
    {
        global $TEMP_DIR, $SESSIONS_COUNT;

        $outPath = "$TEMP_DIR/memory-profiler-$SESSIONS_COUNT.out";
        memprof_dump_callgrind(fopen($outPath, "w"));
        chmod($outPath, 0444);
    }

}

MemoryProfiler::constructStatic();