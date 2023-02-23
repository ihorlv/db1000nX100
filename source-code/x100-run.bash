#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
ulimit -Sn 65535

today=$(date +%Y-%m-%d)
expirationDate="2023-03-08"

if [[ "$today" > "$expirationDate" ]]; then
    echo "This version of X100 has expired"
    echo "Please, update to the latest version"
    sleep 3600
    exit
fi

while :
do
  /usr/bin/env php  ./main.cli.php
  echo "PHP script died"
  sleep 1
done