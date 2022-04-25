<?php

class db1000nAutoUpdater {

    public static  $isStandAloneRun;

    private static $buildDir,
                   $buildScript,
                   $distBinFile;

    const          latestCompatibleVersionFilename = 'latest-compatible-version.txt';

    public static function initStatic()
    {
        global $HOME_DIR;
        static::$isStandAloneRun = false;
        static::$distBinFile = $HOME_DIR . '/DB1000N/db1000n';
        static::$buildDir  = $HOME_DIR . '/DB1000N/git-for-auto-update/db1000n';
        static::$buildScript = static::$buildDir . '/install.sh';
    }

    public static function update()
    {
        global $NEW_DIR_ACCESS_MODE;
        @mkdir(static::$buildDir, true, $NEW_DIR_ACCESS_MODE);
        chdir(static::$buildDir);

        if (file_exists(static::$buildScript)) {

            $currentVersion = static::getCurrentVersion();
            if (! $currentVersion) {
                static::log('error: can\'t detect current version');
                return;
            }

            $latestGitVersion = static::getGitRepoLatestVersion();
            if (! $latestGitVersion) {
                static::log('error: can\'t detect git latest version');
                return;
            }

            $latestCompatibleVersion = static::getLatestCompatibleVersion();
            if (! $latestCompatibleVersion) {
                static::log('error: can\'t detect latest compatible version');
                return;
            }

            if ($currentVersion === $latestGitVersion) {
                static::log("is the newest version ($currentVersion)");
            } else {
                static::log("current version $currentVersion, latest version $latestGitVersion, latest compatible version $latestCompatibleVersion");
                if ($currentVersion !== $latestCompatibleVersion) {
                    static::build($latestCompatibleVersion);
                }
            }

        } else {
            chdir(dirname(static::$buildDir));
            static::log('cloning Git repository');
            static::exec('git init');
            static::exec('git clone https://github.com/Arriven/db1000n');
            chdir(static::$buildDir);
            static::build(static::getLatestCompatibleVersion());
        }
    }

    private static function build($version)
    {
        global $NEW_DIR_ACCESS_MODE;

        if (! file_exists(static::$buildScript)) {
            static::log('build script not found');
            return;
        }

        $tag = 'v'. $version;
        static::exec('git merge origin main');
        static::exec("git checkout $tag");
        static::exec('git status');

        $buildBinFile = static::$buildDir . '/db1000n';
        @unlink($buildBinFile);
        static::exec('./install.sh');
        if (file_exists($buildBinFile)) {
            copy($buildBinFile, static::$distBinFile);
            chmod(static::$distBinFile, changeLinuxPermissions(0, 'rwx', 'rx', 'x'));
            static::log("updated to " . static::getCurrentVersion());
        } else {
            static::log("update failed");
        }
    }

    private static function getCurrentVersion() : string
    {
        $versionJson = static::exec(static::$distBinFile . ' -version');
        $versionObj = json_decode($versionJson);
        $version = $versionObj->version ?? false;
        return trim($version);
    }

    private static function getGitRepoLatestVersion() : string
    {
        static::exec('git fetch --tags');
        $r = static::exec('git tag');
        $tagRegExp = <<<PhpRegExp
                     #^v([\d\.]+)$#m
                     PhpRegExp;
        if (preg_match_all(trim($tagRegExp), $r, $matches) < 1) {
            return false;
        }
        $versions = $matches[1];
        natsort($versions);
        return trim(getArrayLastValue($versions));
    }

    private static function getLatestCompatibleVersion() : string
    {
        if (SelfUpdate::isDevelopmentVersion()) {
            $gitLatestVersion = static::getGitRepoLatestVersion();
            file_put_contents_secure(__DIR__ . '/' . db1000nAutoUpdater::latestCompatibleVersionFilename, $gitLatestVersion);
            return $gitLatestVersion;
        } else {
            $latestVersionUrl = 'https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/DB1000N/' . db1000nAutoUpdater::latestCompatibleVersionFilename;
            $latestVersion = httpDownload($latestVersionUrl);
            return $latestVersion  ?  trim($latestVersion) : false;
        }
    }

    private static function exec($command) : ?string
    {
        $ret = shell_exec($command . '   2>&1');
        //echo "\n\n────────────────────────────────────\n$command\n────────────────────────────────────\n$ret\n";
        return $ret;
    }

    private static function log($message)
    {
        echo static::class . ': ' . $message . "\n";
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