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
            return;
        }

        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName]  ??  [];
        $lastItem['startedAt'] = hrtime(true);
        $taskData[] = $lastItem;
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
        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName];
        if (!$taskData) {
            return false;
        }
        $lastItemKey = array_key_last($taskData);
        $lastItem = $taskData[$lastItemKey];
        $lastItem['duration']   = hrtime(true) - $lastItem['startedAt'];
        $taskData[$lastItemKey] = $lastItem;
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
        return true;
    }

    public static function getTasksTimeTrackingResultsBadge($sessionId)
    {
        if (!SelfUpdate::$isDevelopmentVersion) {
            return '';
        }

        $tasksData =  static::$tasksTimeTracking[$sessionId];
        $ret = [];
        $sessionDuration = 1;
        foreach ($tasksData as $taskName => $taskData) {
            if ($taskName === 'session') {
                $sessionDuration = $taskData[0]['duration'];
            }

            $durationColumn = array_column($taskData, 'duration');
            $retItem['totalDuration'] = array_sum($durationColumn);
            $retItem['totalDurationSeconds'] = intdiv($retItem['totalDuration'], pow(10, 9));
            $retItem['percent'] = round($retItem['totalDuration'] * 100 / $sessionDuration);

            $retItem['count'] = count($durationColumn);
            $ret[$taskName] = $retItem;
        }
        MainLog::log("TasksTimeTrackingResults:\n" . print_r($ret, true), 2, 0,  MainLog::LOG_GENERAL_OTHER + MainLog::LOG_DEBUG);
    }
}