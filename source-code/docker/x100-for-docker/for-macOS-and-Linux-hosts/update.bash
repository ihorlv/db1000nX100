#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

if ! curl  --version; then
   echo "==========================================="
   echo "Curl not found. Can\'t update automatically"
   echo "==========================================="
   sleep 3
   exit
fi

function doUpdate() {
      echo "Fetching ${urlPath}${basename}"
      if ! curl  -L --fail  --max-time 30  "${urlPath}${basename}"  -o "$(pwd)/${basename}.update"; then
         rm "./${basename}.update"
         echo =================
         echo Failed to update
         echo =================
         sleep 3
         exit
      fi
      mv "./${basename}.update" "./${basename}"
      chmod a+x                 "./${basename}"
}

urlPath="https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/docker/x100-for-docker/for-macOS-and-Linux-hosts/"

basename="run.bash"
doUpdate

basename="run-and-auto-update.bash"
doUpdate

basename="stop.bash"
doUpdate

basename="uninstall.bash"
doUpdate

basename="update.bash"
doUpdate

basename="sysctl-settings.txt"
doUpdate

####

cd ../put-your-ovpn-files-here/FreeAndSlowVpn

urlPath="https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/docker/x100-for-docker/put-your-ovpn-files-here/FreeAndSlowVpn/"

basename="generate-vpngate.bash"
doUpdate

