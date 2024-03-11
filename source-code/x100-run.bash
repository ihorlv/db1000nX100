#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
ulimit -Sn 65535

today=$(date +%Y%m%d)
expirationDate="20240325"

if [[ "$today" -ge "$expirationDate" ]]; then
    echo "This version of X100 has expired"
    cat ./version.txt
    echo
    echo "Please, update to the latest version"
    sleep 3600
    exit
fi

#export MEMPROF_PROFILE=1

nice -n -1   php   ./main.cli.php