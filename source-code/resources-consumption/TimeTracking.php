<?php

class TimeTracking
{
    private static array $tasksTimeTracking;

    public static function resetTaskTimeTracking()
    {
        static::$tasksTimeTracking = [];
    }

    public static function startTaskTimeTracking($taskName)
    {
        global $SESSIONS_COUNT;
        if (!SelfUpdate::$isDevelopmentVersion) {
            return false;
        }

        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName]  ??  false;

        if (! $taskData) {
            $taskData['count'] = 0;
            $taskData['duration'] = 0;
        }

        $taskData['startedAt'] = hrtime(true);
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
    }

    public static function stopTaskTimeTracking($taskName) : bool
    {
        global $SESSIONS_COUNT;
        if (!SelfUpdate::$isDevelopmentVersion) {
            return false;
        }

        if (!count(static::$tasksTimeTracking)) {
            return false;
        }

        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] ?? false;
        if (!$taskData) {
            return false;
        }

        $taskData['count']++;
        $taskData['duration'] += hrtime(true) - $taskData['startedAt'];
        unset($taskData['startedAt']);

        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
        return true;
    }

    public static function getTasksTimeTrackingResultsBadge($sessionId)
    {
        if (!SelfUpdate::$isDevelopmentVersion) {
            return '';
        }

        $tasksData = static::$tasksTimeTracking[$sessionId];
        $ret = [];
        $sessionDuration = 1;
        foreach ($tasksData as $taskName => $taskData) {
            if ($taskName === 'session') {
                $sessionDuration = $taskData['duration'];
            }

            $retItem['count'] =  $taskData['count'];
            $retItem['duration'] = $taskData['duration'];
            $retItem['durationSeconds'] = intdiv($retItem['duration'], pow(10, 9));
            $retItem['percent'] = round($retItem['duration'] * 100 / $sessionDuration);

            $ret[$taskName] = $retItem;
        }
        MainLog::log("TasksTimeTrackingResults:\n" . print_r($ret, true), 2, 0,  MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
    }
}