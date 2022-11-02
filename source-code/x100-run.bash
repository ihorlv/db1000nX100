#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
ulimit -Sn 65535

while :
do
  /usr/bin/env php  ./main.cli.php
  echo "PHP script died"
  sleep 1
done