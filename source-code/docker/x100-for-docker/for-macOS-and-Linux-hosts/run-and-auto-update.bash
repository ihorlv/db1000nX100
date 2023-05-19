#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

if grep -s -q dockerInteractiveConfiguration=1 "$(pwd)/../put-your-ovpn-files-here/x100-config.txt"; then
  echo "dockerInteractiveConfiguration=1 found in your x100-config.txt"
  echo "Automatic X100 run is not possible in this mode"
  exit
fi

while :
do
  ./update.bash
  echo "autoUpdate=1" > "$(pwd)/../put-your-ovpn-files-here/x100-config-override.txt"
  ./run.bash
  echo "X100 will be restarted after update"
  echo "Press Ctr+C now if you wish to stop infinite update cycle"
  sleep 30
  ./uninstall.bash
done