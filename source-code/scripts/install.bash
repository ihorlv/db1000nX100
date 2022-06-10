#!/usr/bin/env bash

distDir="/root/DDOS"

cd "$(dirname "$BASH_SOURCE")"
cd ../

gcc -o ./x100-suid-run.elf                 ./x100-suid-run.c

find "${distDir}"   ! -path "${distDir}/DB1000N/db1000n" \
                 -a ! -path "${distDir}/puppeteer-ddos*" \
                 -delete                                                  2>/dev/null

mkdir                                      "${distDir}"                   2>/dev/null
mkdir                                      "${distDir}/DB1000N"           2>/dev/null
cp ./DB1000N/db1000nApplication.php        "${distDir}/DB1000N"
cp ./DB1000N/db1000nAutoUpdater.php        "${distDir}/DB1000N"
cp ./DB1000N/latest-compatible-version.txt "${distDir}/DB1000N/development-latest-compatible-version.txt"
mkdir                                      "${distDir}/open-vpn"
cp ./open-vpn/on-open-vpn-up.cli.php       "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnConfig.php            "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnConnection.php        "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnProvider.php          "${distDir}/open-vpn"
cp ./open-vpn/OpenVpnStatistics.php        "${distDir}/open-vpn"
cp ./open-vpn/wondershaper-1.1.sh          "${distDir}/open-vpn"
cp ./open-vpn/wondershaper-1.4.1.bash      "${distDir}/open-vpn"
mkdir                                      "${distDir}/puppeteer-ddos"    2>/dev/null
#cp ./puppeteer-ddos  -r                    "${distDir}"
mkdir                                                  "${distDir}/resources-consumption"
cp ./resources-consumption/ResourcesConsumption.php    "${distDir}/resources-consumption"
cp ./resources-consumption/track.cli.php               "${distDir}/resources-consumption"
mkdir                                      "${distDir}/scripts"
cp ./scripts/fix-permissions.bash          "${distDir}/scripts"
cp ./common.php                            "${distDir}"
cp ./Config.php                            "${distDir}"
cp ./Efficiency.php                        "${distDir}"
cp ./functions.php                         "${distDir}"
cp ./functions-mb-string.php               "${distDir}"
mv ./x100-suid-run.elf                     "${distDir}"
cp ./HackApplication.php                   "${distDir}"
cp ./init.php                              "${distDir}"
cp ./main.cli.php                          "${distDir}"
cp ./MainLog.php                           "${distDir}"
cp ./SelfUpdate.php                        "${distDir}"
cp ./Term.php                              "${distDir}"
cp ./version.txt                           "${distDir}"

cd "${distDir}/scripts"
./fix-permissions.bash


systemctl enable rsyslog &>/dev/null



