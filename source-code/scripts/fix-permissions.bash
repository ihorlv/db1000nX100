#!/usr/bin/env bash

distDir="/root/DDOS"

cd ../

find "./" -type d -print0                   | xargs -0 chmod u=rwx,g=rx,o=rx
find "./" -type f -print0                   | xargs -0 chmod u=rw,g=r,o=
find "./" -print0                           | xargs -0 chown root
find "./" -print0                           | xargs -0 chgrp root
chmod o+rx  /root
chmod o+rx  "${distDir}"

chmod u+x,g+x                               "${distDir}/open-vpn/on-open-vpn-up.cli.php"
chmod u+x,g+x                               "${distDir}/resources-consumption/track.cli.php"
chmod u+x,g+x                               "${distDir}/main.cli.php"
chmod u+x,g+x,o+rx                          "${distDir}/DB1000N/db1000n"
chmod u+xs,g+xs,o+rx                        "${distDir}/x100-suid-run.elf"
chmod u+x,g+x                               ./scripts/fix-permissions.bash