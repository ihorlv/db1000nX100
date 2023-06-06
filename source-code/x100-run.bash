#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
ulimit -Sn 65535

today=$(date +%Y-%m-%d)
expirationDate="2023-06-19"

if [[ "$today" > "$expirationDate" ]]; then
    echo "This version of X100 has expired"
    cat ./version.txt
    echo
    echo "Please, update to the latest version"
    sleep 3600
    exit
fi

nice -n -1   /usr/bin/env php  ./main.cli.php

#while :
#do
#  nice -n -1   /usr/bin/env php  ./main.cli.php
#  echo "PHP script died"
#  sleep 1
#done