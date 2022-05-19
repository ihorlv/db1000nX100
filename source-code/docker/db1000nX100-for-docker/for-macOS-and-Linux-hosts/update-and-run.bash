#!/usr/bin/env bash

if [ ! -d "../put-your-ovpn-files-here" ]; then
   echo ====================================================
   echo Please, change your directory to update-and-run.bash
   echo ====================================================
   sleep 3
   exit
fi

if ! curl  --version; then
   echo ==========================================
   echo Curl not found. Can\'t update automatically
   echo ==========================================
   sleep 3
   exit
fi

function doUpdate() {
      echo "Fetching ${urlPath}${basename}"
      if ! curl  --fail  --max-time 30  "${urlPath}${basename}"  -o "$(pwd)/${basename}.update"; then
         rm "./${basename}.update"
         echo =================
         echo Failed to update
         echo =================
         sleep 3
         exit
      fi
      mv "./${basename}.update" "./${basename}"
}

basename="run.bash"
urlPath="https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/docker/db1000nX100-for-docker/for-macOS-and-Linux-hosts/"
doUpdate
basename="uninstall.bash"
doUpdate
ls
#./run.bash
exit