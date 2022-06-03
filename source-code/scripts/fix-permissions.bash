#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
cd ../
distDir=$(pwd)

find "$distDir"  -type d   ! -path "${distDir}/puppeteer-ddos/*"  -print0   |  xargs -0 chmod u=rwx,g=rx,o=rx
find "$distDir"  -type f   ! -path "${distDir}/puppeteer-ddos/*"  -print0   |  xargs -0 chmod u=rw,g=r,o=
find "$distDir"  -print0                                                    |  xargs -0 chown root
find "$distDir"  -print0                                                    |  xargs -0 chgrp root
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

chmod u+x,g+x                               "${distDir}/puppeteer-ddos/puppeteer-ddos.cli.js"