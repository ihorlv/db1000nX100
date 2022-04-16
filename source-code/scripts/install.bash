#!/bin/bash
cd ../../

cp /root/DDOS/DB1000N/db1000n                 ./source-code/DB1000N
gcc -o ./source-code/hack-linux-runme.elf     ./source-code/hack-linux-runme.c

find /root/DDOS ! -path '*git*' -delete

mkdir /root/DDOS
mkdir /root/DDOS/DB1000N
mkdir /root/DDOS/open-vpn

cp ./source-code/DB1000N/db1000n                       /root/DDOS/DB1000N
cp ./source-code/DB1000N/db1000nAutoUpdater.php        /root/DDOS/DB1000N
cp ./source-code/open-vpn/on-open-vpn-up.cli.php       /root/DDOS/open-vpn
cp ./source-code/open-vpn/OpenVpnConfig.php            /root/DDOS/open-vpn
cp ./source-code/open-vpn/OpenVpnConnection.php        /root/DDOS/open-vpn
cp ./source-code/common.php                            /root/DDOS
cp ./source-code/Efficiency.php                        /root/DDOS
cp ./source-code/functions.php                         /root/DDOS
cp ./source-code/functions-mb-string.php               /root/DDOS
cp ./source-code/HackApplication.php                   /root/DDOS
cp ./source-code/init.php                              /root/DDOS
cp ./source-code/main.cli.php                          /root/DDOS
cp ./source-code/Term.php                              /root/DDOS
mv ./source-code/hack-linux-runme.elf                  /root/DDOS
#cp ./source-code/docker.config                        /root/DDOS

cd /root/DDOS
find "./" -type d -print0 | xargs -0 chmod u=rwx,g=rwx,o=
find "./" -type f -print0 | xargs -0 chmod u=rw,g=rw,o=
find "./" -print0 | xargs -0 chown root
find "./" -print0 | xargs -0 chgrp root
chmod o+rx  /root
chmod o+rx  /root/DDOS

chmod u=rwxs,g=rwxs,o=rx ./hack-linux-runme.elf
chmod u+x,g+x ./DB1000N/db1000n
chmod u+x,g+x ./open-vpn/on-open-vpn-up.cli.php
chmod u+x,g+x ./main.cli.php

systemctl enable rsyslog &>/dev/null



