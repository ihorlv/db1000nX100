#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
cd ../
distDir=$(pwd)

find "$distDir"               ! -path "${distDir}/puppeteer-ddos/node_modules*"  -print0   |  xargs -0 chown root
find "$distDir"               ! -path "${distDir}/puppeteer-ddos/node_modules*"  -print0   |  xargs -0 chgrp root
find "$distDir"  -type d  -a  ! -path "${distDir}/puppeteer-ddos/node_modules*"  -print0   |  xargs -0 chmod u=rwx,g=rx,o=rx
find "$distDir"  -type f  -a  ! -path "${distDir}/puppeteer-ddos/node_modules*"  -print0   |  xargs -0 chmod u=rw,g=r,o-rwx

chmod o+rx  /root
chmod o+rx  "${distDir}"

chmod u+x,g+x                               "${distDir}/open-vpn/on-open-vpn-up.cli.php"
chmod u+x,g+x                               "${distDir}/resources-consumption/track.cli.php"
chmod u+x,g+x                               "${distDir}/main.cli.php"
chmod u+x,g+x                               "${distDir}/open-vpn/wondershaper-1.1.sh"
chmod u+x,g+x                               "${distDir}/open-vpn/wondershaper-1.4.1.bash"
chmod u+x,g+x                               "${distDir}/scripts/fix-permissions.bash"

chmod u+x,g+x,o+rx                          "${distDir}/DB1000N/db1000n"
chmod u+xs,g+xs,o+rx                        "${distDir}/x100-suid-run.elf"


find            "${distDir}/puppeteer-ddos"           -print0   |  xargs -0 chown root
find            "${distDir}/puppeteer-ddos"           -print0   |  xargs -0 chgrp user
find            "${distDir}/puppeteer-ddos"  -type f  -print0   |  xargs -0 chmod g-w,o-rwx
chmod g+rwx     "${distDir}/puppeteer-ddos"
chmod u+x,g+x   "${distDir}/puppeteer-ddos/puppeteer-ddos.cli.js"
