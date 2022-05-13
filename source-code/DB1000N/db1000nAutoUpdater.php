<?php

class db1000nAutoUpdater {

    public static  $isStandAloneRun;

    private static $distDir,
                   $distBinFile,
                   $releases;

    const          latestCompatibleVersionFilename = 'latest-compatible-version.txt';

    public static function initStatic()
    {
        global $HOME_DIR;
        static::$isStandAloneRun = false;
        static::$distDir = $HOME_DIR . '/DB1000N';
        static::$distBinFile = $HOME_DIR . '/DB1000N/db1000n';
        static::$releases = static::getReleases();
    }

    private static function getReleases()
    {
        $rest = httpGet('https://api.github.com/repos/Arriven/db1000n/releases', $httpCode);
        if (! $rest) {
            return false;
        }
        $releasesJson = json_decode($rest);
        $ret = [];
        foreach ($releasesJson as $releaseJson) {
            $version = preg_replace('#[^\d\.]#', '', $releaseJson->tag_name);
            $links = [];
            foreach ($releaseJson->assets as $asset) {
                if (preg_match('#[-_]([^-_]+)[-_]([^-_]+)\.tar\.(gz|bz2|zip)$#', $asset->browser_download_url, $matches) === 1) {
                    $arch = $matches[1] . '-' . $matches[2];
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
            static::log("current version $currentVersion, latest version $latestVersion, latest compatible version $latestCompatibleVersion");
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
            case 'i386':
                $url = $links['linux-386'];
            break;

            case 'aarch64':
                $url = $links['linux-arm64'];
            break;

            default:
                $url = $links['linux-amd64'];
        }

        if (! $url) {
            static::log("error: can't find url for $version");
        }
        static::log("fetching $version from $url");

        $distArchiveContent = httpGet($url, $httpCode);
        if (! $distArchiveContent) {
            static::log("error: can't fetch version $version from $url");
            return;
        }

        $distArchiveFile = static::$distDir . '/' . mbBasename($url);
        file_put_contents_secure($distArchiveFile, $distArchiveContent);
        $phar = new PharData($distArchiveFile);
        $phar->extractTo(static::$distDir, null, true);
        unlink($distArchiveFile);

        if (file_exists(static::$distBinFile)) {
            static::log("updated to " . static::getCurrentVersion());
            chmod(static::$distBinFile, changeLinuxPermissions(0, 'rwx', 'rx', 'rx'));
        } else {
            static::log("update failed");
        }
    }

    private static function getCurrentVersion() : string
    {
        $versionJson = static::exec(static::$distBinFile . '  --log-format json  -version');
        $versionObj = json_decode($versionJson);
        $version = $versionObj->version ?? false;
        return trim($version);
    }

    private static function getLatestVersion() : string
    {
        return is_array(static::$releases)  ?  array_key_last(static::$releases) : false;
    }

    private static function getLatestCompatibleVersion() : string
    {
        global $HOME_DIR;
        $localDevelopmentVersionFile = $HOME_DIR . '/DB1000N/development-' . db1000nAutoUpdater::latestCompatibleVersionFilename;
        $latestDevelopmentVersion = @file_get_contents($localDevelopmentVersionFile);

        $latestPublicVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/DB1000N/' . db1000nAutoUpdater::latestCompatibleVersionFilename;
        $latestPublicVersion = httpGet($latestPublicVersionUrl, $httpCode);

        $versions = [trim($latestDevelopmentVersion), trim($latestPublicVersion)];
        natsort($versions);
        $versions = array_values($versions);
        $latestVersion = $versions[1];
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
    db1000nAutoUpdater::$isStandAloneRun = true;
    require_once $commonPhp;
    db1000nAutoUpdater::initStatic();
    db1000nAutoUpdater::update();
} else {
    db1000nAutoUpdater::initStatic();
}