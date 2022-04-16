<?php

class db1000nAutoUpdater {

    public static  $isStandAloneRun;

    private static $buildDir,
                   $buildScript,
                   $distBinFile;

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
            $latestTag = static::getGitRepoLatestTag();
            if (!$currentVersion  ||  !$latestTag) {
                static::log('error');
                return;
            }

            if ('v' . $currentVersion === $latestTag) {
                static::log("already up to date ($currentVersion)");
            } else {
                static::log("old version $currentVersion");
                static::build();
            }
        } else {
            chdir(dirname(static::$buildDir));
            static::exec('git init');
            static::exec('git clone https://github.com/Arriven/db1000n');
            chdir(static::$buildDir);
            static::build();
        }
    }

    private static function build()
    {
        global $NEW_DIR_ACCESS_MODE;

        if (! file_exists(static::$buildScript)) {
            static::log("build script not found");
            return;
        }

        $latestTag = static::getGitRepoLatestTag();
        static::exec('git merge origin main');
        static::exec("git checkout $latestTag");
        static::exec('git status');

        $buildBinFile = static::$buildDir . '/db1000n';
        @unlink($buildBinFile);
        static::exec('./install.sh');
        if (file_exists($buildBinFile)) {
            copy($buildBinFile, static::$distBinFile);
            chmod(static::$distBinFile, $NEW_DIR_ACCESS_MODE);
            static::log("updated to " . static::getCurrentVersion());
        } else {
            static::log("update failed");
            return;
        }
    }

    private static function getCurrentVersion()
    {
        $r = static::exec(static::$distBinFile . ' -version');
        $versionRegExp = <<<PhpRegExp
                         #\[Version:\s+([\d\.]+)\]#
                         PhpRegExp;
        if (preg_match(trim($versionRegExp), $r, $matches) === 1) {
            return $matches[1];
        } else {
            return false;
        }
    }

    private static function getGitRepoLatestTag()
    {
        static::exec('git fetch --tags');
        $r = static::exec('git tag');
        $tagRegExp = <<<PhpRegExp
                     #^v[\d\.]+$#m
                     PhpRegExp;
        if (preg_match_all(trim($tagRegExp), $r, $matches) < 1) {
            return false;
        }
        $tags = $matches[0];
        natsort($tags);
        $latestTag = getArrayLastValue($tags);
        return $latestTag;
    }

    private static function exec($command)
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