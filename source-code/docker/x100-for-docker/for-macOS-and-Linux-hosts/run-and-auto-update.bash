#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

if grep -s -q dockerInteractiveConfiguration=1 "$(pwd)/../put-your-ovpn-files-here/x100-config.txt"; then
  echo "=============================================================="
  echo "dockerInteractiveConfiguration=1 found in your x100-config.txt"
  echo "Automatic X100 run is not possible in this mode"
  echo "=============================================================="
  exit
fi

dockerAutoUpdateLockFile="$(pwd)/../put-your-ovpn-files-here/docker-auto-update.lock"

while :
do
  ./update.bash

  echo "1" > $dockerAutoUpdateLockFile
  ./run.bash

  if ! grep -s -q 2 $dockerAutoUpdateLockFile; then
      break
  fi

  ./uninstall.bash
done

rm $dockerAutoUpdateLockFile   2>/dev/null