#!/usr/bin/env php
<?php
// cd /media/sf_DDOS/src/source-code/docker/db1000nX100-for-docker/for-macOS-and-Linux-hosts ; /media/sf_DDOS/src/source-code/docker/db1000nX100-for-docker/for-macOS-and-Linux-hosts/run.bash
// https://github.com/multiarch/qemu-user-static
// docker run --rm --privileged multiarch/qemu-user-static --reset -p yes
// This command enables multi arch Docker

$dockerSrcDir = __DIR__;
$dockerBuildDir = $dockerSrcDir . '/db1000nX100-for-docker';
$srcDir = dirname(__DIR__);
$scriptsDir = $srcDir . '/scripts';
$distDir = '/root/DDOS';

$builds = [
    'x86_64_local' => [
        'enabled'     => false,
        'sourceImage' => 'debian:latest',
        'container'   => 'db1000nx100-container-local',
        'image'       => 'db1000nx100-image-local',
        'tag'         => 'local',
        'login'       => false
    ],
    'arm64v8' => [
        'enabled'     => true,
        'sourceImage' => 'arm64v8/debian:latest',
        'container'   => 'db1000nx100-container-arm64v8',
        'image'       => 'db1000nx100-image-arm64v8',
        'tag'         => 'latest',
        'login'       => 'ihorlv'
    ],
    'x86_64' => [
        'enabled'     => true,
        'sourceImage' => 'debian:latest',
        'container'   => 'db1000nx100-container',
        'image'       => 'db1000nx100-image',
        'tag'         => 'latest',
        'login'       => 'ihorlv'
    ]
];

clean();
rmdirRecursive($distDir);
chdir($scriptsDir);
passthru('./install.bash');
passthru('docker run --rm --privileged multiarch/qemu-user-static --reset -p yes');

passthru('systemctl start    docker');
passthru('systemctl start    containerd');

foreach ($builds as $name => $opt) {
    if (! $opt['enabled']) {
        continue;
    }

    passthru('docker pull ' .  $opt['sourceImage']);
    passthru('docker create --interactive --name ' . $opt['container'] . ' ' .  $opt['sourceImage']);
    passthru('docker container start ' . $opt['container']);
    passthru("docker cp $distDir "     . $opt['container'] . ':' . $distDir);

    $containerCommands = [
        "rm $distDir/x100-sudo-run.elf",
        'apt -y  update',
        'apt -y  install  util-linux procps kmod iputils-ping php-cli php-mbstring php-curl curl openvpn git mc',
        'ln  -sf /usr/share/zoneinfo/Europe/Kiev /etc/localtime',
        '/usr/sbin/useradd hack-app',
        'chmod o+x /root',
        '/usr/bin/env php ' . $distDir . '/DB1000N/db1000nAutoUpdater.php'
    ];

    foreach ($containerCommands as $containerCommand) {
        echo "$containerCommand\n";
        passthru('docker exec ' . $opt['container'] . '   ' . $containerCommand);
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
        #passthru("docker image rm --force $hubImagePrevious");
        #passthru("docker image rm --force $hubImage");

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

function rmdirRecursive(string $dir) : bool
{
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dir);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}