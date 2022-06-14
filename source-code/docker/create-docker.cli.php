#!/usr/bin/env php
<?php
// /media/sf_DDOS/src/source-code/docker/db1000nX100-for-docker/for-macOS-and-Linux-hosts/run.bash
// https://github.com/multiarch/qemu-user-static
// docker run --rm --privileged multiarch/qemu-user-static --reset -p yes
// This command enables multi arch Docker

require_once dirname(__DIR__) . '/common.php';

const LOCAL_IMAGE = 0;
$dockerSrcDir = __DIR__;
$dockerBuildDir = $dockerSrcDir . '/db1000nX100-for-docker';
$putYourOvpnFilesHereDir = $dockerBuildDir . '/put-your-ovpn-files-here';
$srcDir = dirname(__DIR__);
$scriptsDir = $srcDir . '/scripts';
$distDir = '/root/DDOS';
$vboxDistDir = '/media/sf_db1000nX100-for-virtual-box';
$suidLauncherDist = $distDir . '/x100-suid-run.elf';
$suidLauncherSrc  = $srcDir  . '/x100-suid-run.c';


$builds = [
    'x86_64_local' => [
        'enabled'     => LOCAL_IMAGE,
        'compiler'    => 'gcc',
        'sourceImage' => 'debian:latest',
        'container'   => 'db1000nx100-container-local',
        'image'       => 'db1000nx100-image-local',
        'tag'         => 'local',
        'login'       => false
    ],
    'arm32v7' => [
        'enabled'     => !LOCAL_IMAGE,
        'compiler'    => '/usr/bin/arm-linux-gnueabihf-gcc',   // atp install gcc-arm-linux-gnueabihf
        'sourceImage' => 'arm32v7/debian:latest',
        'container'   => 'db1000nx100-container-arm32v7',
        'image'       => 'db1000nx100-image-arm32v7',
        'tag'         => 'latest',
        'login'       => 'ihorlv'
    ],
    'arm64v8' => [
        'enabled'     => !LOCAL_IMAGE,
        'compiler'    => '/usr/bin/aarch64-linux-gnu-gcc',    //apt install gcc-aarch64-linux-gnu
        'sourceImage' => 'arm64v8/debian:latest',
        'container'   => 'db1000nx100-container-arm64v8',
        'image'       => 'db1000nx100-image-arm64v8',
        'tag'         => 'latest',
        'login'       => 'ihorlv'
    ],
    'x86_64' => [
        'enabled'     => !LOCAL_IMAGE,
        'compiler'    => 'gcc',
        'sourceImage' => 'debian:latest',
        'container'   => 'db1000nx100-container',
        'image'       => 'db1000nx100-image',
        'tag'         => 'latest',
        'login'       => 'ihorlv'
    ]
];

passthru('reset');
clean();
rmdirRecursive($distDir);
@unlink($srcDir                  . '/db1000nX100-config.txt');
@unlink($putYourOvpnFilesHereDir . '/db1000nX100-config.txt');
@unlink($putYourOvpnFilesHereDir . '/db1000nX100-config-override.txt');
@unlink($putYourOvpnFilesHereDir . '/db1000nX100-log.txt');
@unlink($putYourOvpnFilesHereDir . '/db1000nX100-log-short.txt');
chdir($scriptsDir);

passthru("date +%Y%m%d.%H%M > $srcDir/version.txt");
passthru('./install.bash');

if (! LOCAL_IMAGE) {
    passthru('docker run --rm --privileged multiarch/qemu-user-static --reset -p yes');
}

passthru('systemctl start    docker');
passthru('systemctl start    containerd');

foreach ($builds as $name => $opt) {
    if (! $opt['enabled']) {
        continue;
    }

    @unlink($suidLauncherDist);
    passthru("{$opt['compiler']}   -o $suidLauncherDist  $suidLauncherSrc");
    if (!file_exists($suidLauncherDist)) {
        echo "$suidLauncherDist compile failed";
        die();
    }
    chmod($suidLauncherDist, changeLinuxPermissions(0, 'rwxs', 'rxs', 'rx'));

    passthru('docker pull ' .  $opt['sourceImage']);
    passthru('docker create --interactive --name ' . $opt['container'] . ' ' .  $opt['sourceImage']);
    passthru('docker container start ' . $opt['container']);
    passthru("docker cp $distDir "     . $opt['container'] . ':' . $distDir);

    $containerCommands = [
        'apt -y  update',
        'apt -y  install  util-linux procps kmod iputils-ping mc htop php-cli php-mbstring php-curl curl openvpn',  // Install packages
        'ln  -sf /usr/share/zoneinfo/Europe/Kiev /etc/localtime',               // Set Timezone
        '/usr/sbin/useradd --system hack-app',                                  // Create hack-app user
        'chmod o+x /root',                                                      // Make /root available to chdir for all

        'curl -O https://install.speedtest.net/app/cli/install.deb.sh',         // Install Ookla speedtest CLI
        'chmod u+x ./install.deb.sh',
        './install.deb.sh',
        'apt -y install speedtest',
        'rm ./install.deb.sh',

        //!!!!!!  Just SKIP next line  !!!!!!
        '/usr/bin/env php ' . $distDir . '/DB1000N/db1000nAutoUpdater.php',
    ];

    foreach ($containerCommands as $containerCommand) {
        $exec = "docker exec {$opt['container']}   /bin/sh -c  '$containerCommand'";
        echo "$exec\n";
        passthru($exec);
    }

    passthru('docker container stop ' . $opt['container']);
    passthru('docker commit '         . $opt['container'] . ' ' . $opt['image']);

    if ($opt['login']) {
        passthru('docker login --username=' . $opt['login']);
        $hubImage         = $opt['login'] . '/' . $opt['image'] . ':' . $opt['tag'];
        $hubImagePrevious = $opt['login'] . '/' . $opt['image'] . ':previous';

        passthru("docker pull $hubImage");
        passthru("docker tag  $hubImage $hubImagePrevious");
        passthru("docker push $hubImagePrevious");
        passthru('docker tag ' . $opt['image'] . ' ' . $hubImage);
        passthru("docker push $hubImage");
    } else {
        $localTar = $dockerBuildDir . '/' . $opt['image'] . '.tar';
        @unlink($localTar);
        passthru("docker save  --output $localTar  " . $opt['image']);
    }

    clean();
    showJunk();
    sleep(5);
}

passthru('/usr/bin/env php ' . $distDir . '/DB1000N/db1000nAutoUpdater.php');
passthru('/usr/bin/env php ' . $distDir . '/Config.php');

#copy($putYourOvpnFilesHereDir . '/db1000nX100-config.txt', $srcDir . '/db1000nX100-config.txt-example');
copy($putYourOvpnFilesHereDir . '/db1000nX100-config.txt', $vboxDistDir . '/put-your-ovpn-files-here/db1000nX100-config.txt');

@unlink($vboxDistDir . '/put-your-ovpn-files-here/db1000nX100-log.txt');

copy($srcDir . '/scripts/microsoft-hypervisor/enable-hypervisor.cmd',  $vboxDistDir . '/scripts/enable-hypervisor.cmd');
copy($srcDir . '/scripts/microsoft-hypervisor/disable-hypervisor.cmd', $vboxDistDir . '/scripts/disable-hypervisor.cmd');

//---------------------------------------------------------------

function getDockerImageId($image)
{
    return shell_exec("docker images --filter=reference=$image --format \"{{.ID}}\"");
}

function showJunk()
{
    passthru('docker container ls');
    passthru('docker image ls');
    passthru('docker volume ls');
}

function clean()
{
    passthru('docker image   prune --all --force');
    passthru('docker system  prune --all --force');
    //passthru("docker container stop   " . $opt['container']);
    //passthru("docker rm               " . $opt['container']);
    //passthru("docker image rm --force " . $opt['image']);
    //passthru("docker image rm --force " . $opt['sourceImage']);
}


//         //"echo '*     -  nofile  65535' >> /etc/security/limits.conf",       // Increase Linux open files limit  (ulimit -n). This may be tricky