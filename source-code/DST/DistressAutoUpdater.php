<?php

class DistressAutoUpdater {

    public static  $isStandAloneRun;

    private static $distDir,
                   $distBinFile,
                   $releases;

    const          latestCompatibleVersionFilename = 'latest-compatible-version.txt';

    public static function initStatic()
    {
        global $HOME_DIR;
        static::$isStandAloneRun = false;
        static::$distDir     = $HOME_DIR . '/DST';
        static::$distBinFile = $HOME_DIR . '/DST/app';
        Actions::addAction('AfterInitSession',  [static::class, 'actionAfterInitSession'], 9);
    }

    public static function actionAfterInitSession()
    {
        global $SESSIONS_COUNT;

        if ($SESSIONS_COUNT === 1  ||  $SESSIONS_COUNT % 10 === 0) {
            static::update();
        }
    }

    public static function getReleases()
    {
        $rest = httpGet('https://api.github.com/repos/Yneth/distress-releases/releases', $httpCode);
        if (! $rest) {
            return false;
        }
        $releasesJson = json_decode($rest);

        $ret = [];
        foreach ($releasesJson as $releaseJson) {
            $version = $releaseJson->tag_name;

            $links = [];
            foreach ($releaseJson->assets as $asset) {
                if (preg_match('#distress_(.*)-unknown-linux#', $asset->browser_download_url, $matches) === 1) {
                    $arch = $matches[1];
                    $links[$arch] = $asset->browser_download_url;
                }
            }
            $ret[$version] = $links;
        }

        uksort($ret, 'strnatcmp');
        return $ret;
    }

    public static function update()
    {
        static::$releases = static::getReleases();

        $latestVersion = static::getLatestVersion();
        if (! $latestVersion) {
            static::log('error: can\'t detect latest version');
            return;
        }

        $latestCompatibleVersion = static::getLatestCompatibleVersion();
        if (! $latestCompatibleVersion) {
            static::log('error: can\'t detect latest compatible version');
            return;
        }

        $currentVersion = static::getCurrentVersion();
        if (! $currentVersion) {
            static::log('error: can\'t detect current version');
        }

        if ($currentVersion === $latestVersion) {
            static::log("is the newest version ($currentVersion)");
        } else {

            if ($currentVersion) {
                static::log("current version $currentVersion, ");
            }

            static::log("latest version $latestVersion, latest compatible version $latestCompatibleVersion");

            if ($currentVersion === $latestCompatibleVersion) {
                static::log('is the latest compatible version');
            } else {
                static::fetch($latestCompatibleVersion);
            }
        }
    }

    private static function fetch($version)
    {
        global $CPU_ARCHITECTURE;
        $links = static::$releases[$version];
        static::log("CPU architecture $CPU_ARCHITECTURE");

        switch ($CPU_ARCHITECTURE) {

            case 'armv7l':
                $url = $links['arm'];  //armv6
            break;

            case 'aarch64':
                $url = $links['aarch64'];
            break;

            default:
                $url = $links['x86_64'];
        }

        if (! $url) {
            static::log("error: can't find url for $version");
        }
        static::log("fetching $version from $url");

        $distContent = httpGet($url, $httpCode);
        if (! $distContent) {
            static::log("error: can't fetch version $version from $url");
            return;
        }

        file_put_contents_secure(static::$distBinFile, $distContent);

        if (file_exists(static::$distBinFile)) {
            chmod(static::$distBinFile, changeLinuxPermissions(0, 'rwx', 'rx', 'rx'));
            static::log("updated to " . static::getCurrentVersion());
        } else {
            static::log("update failed");
        }
    }

    private static function getCurrentVersion() : string
    {
        $versionStdout = static::exec(static::$distBinFile . '  --version');
        if (preg_match('#^distress (.*)$#', trim($versionStdout), $matches) > 0) {
            return $matches[1];
        } else {
            return false;
        }
    }

    private static function getLatestVersion() : ?string
    {
        return is_array(static::$releases)  ?  array_key_last(static::$releases) : false;
    }

    private static function getLatestCompatibleVersion() : string
    {
        //return '0.7.9';

        global $HOME_DIR;
        $localDevelopmentVersionFile = $HOME_DIR . '/DST/' . DistressAutoUpdater::latestCompatibleVersionFilename;
        $latestDevelopmentVersion = @file_get_contents($localDevelopmentVersionFile);

        $latestPublicVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/DST/' . DistressAutoUpdater::latestCompatibleVersionFilename;
        $latestPublicVersion = httpGet($latestPublicVersionUrl, $httpCode);
        $versions = [trim($latestDevelopmentVersion), trim($latestPublicVersion)];

        $versions = array_filter($versions);
        natsort($versions);
        $versions = array_values(array_reverse($versions));

        $latestVersion = $versions[0];
        return $latestVersion  ?  trim($latestVersion) : false;
    }

    private static function exec($command) : ?string
    {
        $ret = _shell_exec($command);
        //echo "\n\n────────────────────────────────────\n$command\n────────────────────────────────────\n$ret\n";
        return $ret;
    }

    private static function log($message)
    {
        MainLog::log(static::class . ': ' . $message);
    }
}

$commonPhp = dirname(__DIR__) . '/common.php';
if (! in_array($commonPhp, get_included_files())) {
    DistressAutoUpdater::$isStandAloneRun = true;
    require_once $commonPhp;
    DistressAutoUpdater::initStatic();
    DistressAutoUpdater::update();
} else {
    DistressAutoUpdater::initStatic();
}