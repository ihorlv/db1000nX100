#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

export dockerAutoUpdateLockFile="$(pwd)/../put-your-ovpn-files-here/docker-auto-update.lock"

while :
do

  trimDisksScript="./trim-disks.bash"
  if [ -f "$trimDisksScript" ]; then
      $trimDisksScript
  fi


  ./update.bash


  echo "1" > "$dockerAutoUpdateLockFile"
  ./run.bash


  if ! grep -s -q 2 "$dockerAutoUpdateLockFile"; then
      break
  fi


  ./uninstall.bash

done

rm "$dockerAutoUpdateLockFile"          2>/dev/null
export dockerAutoUpdateLockFile=""