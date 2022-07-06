<?php

class Actions
{
    private static $actionsList;

    public static function addAction($actionName, $callback)
    {
        static::$actionsList[$actionName][] = $callback;
    }

    public static function doAction($actionName)
    {
        $actionCallbacks = static::$actionsList[$actionName]  ??  [];
        foreach ($actionCallbacks as $actionCallback) {
            call_user_func($actionCallback);
        }
    }

}