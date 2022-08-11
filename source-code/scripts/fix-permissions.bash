#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
cd ../
distDir=$(pwd)

find "$distDir"               ! -path "${distDir}/puppeteer-ddos*"  -print0   |  xargs -0 chown root
find "$distDir"               ! -path "${distDir}/puppeteer-ddos*"  -print0   |  xargs -0 chgrp root
find "$distDir"  -type d  -a  ! -path "${distDir}/puppeteer-ddos*"  -print0   |  xargs -0 chmod u=rwx,g=rx,o=rx
find "$distDir"  -type f  -a  ! -path "${distDir}/puppeteer-ddos*"  -print0   |  xargs -0 chmod u=rw,g=r,o-rwx

chmod o+rx  /root
chmod o+rx  "${distDir}"

chmod u+x,g+x                               "${distDir}/open-vpn/on-open-vpn-up.cli.php"
chmod u+x,g+x                               "${distDir}/resources-consumption/track.cli.php"
chmod u+x,g+x                               "${distDir}/main.cli.php"
chmod u+x,g+x                               "${distDir}/open-vpn/wondershaper-1.1.sh"
chmod u+x,g+x                               "${distDir}/open-vpn/wondershaper-1.4.1.bash"
chmod u+x,g+x                               "${distDir}/scripts/install-x100slim-components-into-linux.bash"
chmod u+x,g+x                               "${distDir}/scripts/fix-permissions.bash"

chmod u+x,g+x,o+rx                          "${distDir}/DB1000N/db1000n"
chmod u+x,g+x,o+rx                          "${distDir}/x100-run.bash"


find   "${distDir}/puppeteer-ddos"   -print0                                                                   |  xargs -0 chown user
find   "${distDir}/puppeteer-ddos"   -print0                                                                   |  xargs -0 chgrp user
find   "${distDir}/puppeteer-ddos"   -type d   -a ! -path "${distDir}/puppeteer-ddos/node_modules/*"  -print0  |  xargs -0 chmod u=rwx,g=rx,o=rx
find   "${distDir}/puppeteer-ddos"   -type f   -a ! -path "${distDir}/puppeteer-ddos/node_modules/*"  -print0  |  xargs -0 chmod u=rw,g=r,o-rwx

chmod  u+x,g+x   "${distDir}/puppeteer-ddos/puppeteer-ddos-dist.cli.js"   2>/dev/null
chmod  u+x,g+x   "${distDir}/puppeteer-ddos/brain-server-dist.cli.js"     2>/dev/null
