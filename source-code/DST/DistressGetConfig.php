<?php

class DistressGetConfig extends SFunctions
{
    private static RecurrentHttpGet $targetsHttpGet, $proxyPoolHttpGet, $configHttpGet;

    public static function constructStatic()
    {
        Actions::addAction('AfterCalculateResources', [static::class, 'actionAfterCalculateResources'], 11);
        Actions::addAction('BeforeInitSession', [static::class, 'actionBeforeInitSession'], 9);
    }

    public static function actionAfterCalculateResources()
    {
        global $DISTRESS_ENABLED;

        if (!$DISTRESS_ENABLED) {
            return;
        }

        static::$proxyPoolHttpGet = new RecurrentHttpGet(
            [
                'https://github.com/Yneth/funny/raw/main/01.txt'
            ],
            DistressApplicationStatic::$proxyPoolFilePath
        );

        static::$configHttpGet = new RecurrentHttpGet(
            [
                'https://raw.githubusercontent.com/Yneth/distress-releases/main/config1.txt'
            ],
            DistressApplicationStatic::$configFilePath
        );

        // ---

        $targetsUrls = [
            "https://raw.githubusercontent.com/crayfish-kissable-marrow/crayfish/master/31.json",
            "https://raw.githubusercontent.com/snoring-huddling-charred/snoring/master/31.json",
        ];

        $targetsUrlIndex = rand(0, count($targetsUrls) - 1);
        $targetsUrl = $targetsUrls[$targetsUrlIndex];

        static::$targetsHttpGet = new RecurrentHttpGet(
            [ $targetsUrl ],
            DistressApplicationStatic::$targetsFilePath
        );
    }

    public static function actionBeforeInitSession()
    {
        global $SESSIONS_COUNT;

        static::$targetsHttpGet->get();
        if (static::$targetsHttpGet->changed) {
            //chown(static::$targetsHttpGet->path, 'app-h');
            //chgrp(static::$targetsHttpGet->path, 'app-h');

            if ($SESSIONS_COUNT !== 1) {
                DistressApplicationStatic::$localTargetsFileHasChanged = true;
                DistressApplicationStatic::$localTargetsFileLastChangeAt = time();
            }
        } else {
            DistressApplicationStatic::$localTargetsFileHasChanged = false;
        }

        // ---

        static::$proxyPoolHttpGet->get();
        if (static::$proxyPoolHttpGet->changed) {
            //chown(static::$proxyPoolHttpGet->path, 'app-h');
            //chgrp(static::$proxyPoolHttpGet->path, 'app-h');
        }

        // ---

        static::$configHttpGet->get();
        if (static::$configHttpGet->changed) {
            //chown(static::$configHttpGet->path, 'app-h');
            //chgrp(static::$configHttpGet->path, 'app-h');
        }
    }
}

DistressGetConfig::constructStatic();