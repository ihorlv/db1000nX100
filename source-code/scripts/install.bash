#!/usr/bin/env bash

distDir="/root/DDOS"

cd ../
pwd
gcc -o ./db1000nx100-su-run.elf            ./db1000nx100-su-run.c
date +%Y%m%d.%H%M >                        ./version.txt

find "${distDir}" ! -path                  "${distDir}/DB1000N/db1000n" -delete

mkdir                                      "${distDir}"
mkdir                                      "${distDir}/DB1000N"
cp ./DB1000N/db1000nAutoUpdater.php        "${distDir}/DB1000N"
mkdir                                      "${distDir}/open-vpn"
cp ./open-vpn/on-open-vpn-up.cli.php       "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnConfig.php            "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnConnection.php        "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnProvider.php          "${distDir}/open-vpn"
cp ./common.php                            "${distDir}"
cp ./Efficiency.php                        "${distDir}"
cp ./functions.php                         "${distDir}"
cp ./functions-mb-string.php               "${distDir}"
mv ./db1000nx100-su-run.elf                "${distDir}"
cp ./HackApplication.php                   "${distDir}"
cp ./init.php                              "${distDir}"
cp ./main.cli.php                          "${distDir}"
cp ./ResourcesConsumption.php              "${distDir}"
cp ./SelfUpdate.php                        "${distDir}"
cp ./Term.php                              "${distDir}"
cp ./version.txt                           "${distDir}"
#cp ./source-code/docker.config            "${distDir}"

cd "${distDir}"
pwd

find "./" -type d -print0                   | xargs -0 chmod u=rwx,g=rx,o=rx
find "./" -type f -print0                   | xargs -0 chmod u=rw,g=r,o=
find "./" -print0                           | xargs -0 chown root
find "./" -print0                           | xargs -0 chgrp root
chmod o+rx  /root
chmod o+rx  "${distDir}"

chmod u+x,g+x                               "${distDir}/open-vpn/on-open-vpn-up.cli.php"
chmod u+x,g+x                               "${distDir}/main.cli.php"
chmod u+x,g+x,o+rx                          "${distDir}/DB1000N/db1000n"
chmod u+xs,g+xs,o+rx                        "${distDir}/db1000nx100-su-run.elf"

systemctl enable rsyslog &>/dev/null



