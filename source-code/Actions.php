<?php

class Actions
{
    private static array $actionsStructure,
                         $filtersStructure;

    public static function addAction($actionName, $callback, $priority = 10)
    {
        static::$actionsStructure[$actionName][$priority][] = $callback;
    }

    public static function doAction($actionName)
    {
        $actionCallbacksByPriority = static::$actionsStructure[$actionName]  ??  [];
        if (
               !count($actionCallbacksByPriority)
            && SelfUpdate::$isDevelopmentVersion  // <---
        ) {
            MainLog::log("No callbacks found for action \"$actionName\" (development message)", 1, 0, MainLog::LOG_DEBUG);
        }

        ksort($actionCallbacksByPriority);
        foreach ($actionCallbacksByPriority as $priority => $actionCallbacks) {
            foreach ($actionCallbacks as $actionCallback) {
                call_user_func($actionCallback);
            }
        }
    }

    // ---

    public static function addFilter($filterName, $callback, $priority = 10)
    {
        static::$filtersStructure[$filterName][$priority][] = $callback;
    }

    public static function doFilter($filterName, $valueToFilter)
    {
        $filterCallbacksByPriority = static::$filtersStructure[$filterName]  ??  [];
        if (
               !count($filterCallbacksByPriority)
            && SelfUpdate::$isDevelopmentVersion  // <---
        ) {
            MainLog::log("No callbacks found for filter \"$filterName\" (development message)", 1, 0, MainLog::LOG_DEBUG);
        }

        ksort($filterCallbacksByPriority);
        foreach ($filterCallbacksByPriority as $priority => $filterCallbacks) {
            foreach ($filterCallbacks as $filterCallback) {
                $valueToFilter = call_user_func($filterCallback, $valueToFilter);
            }
        }
        return $valueToFilter;
    }

    public function newSecretMethod()
    {

    }

}