#!/usr/bin/env bash

localImage=0
imageLocal=db1000nx100-image-local
cpuArch=$(uname -m)

if     [ "$cpuArch" == "aarch64" ] || [ "$cpuArch" == "arm64" ]; then
    image="ihorlv/db1000nx100-image-arm64v8"
    container="db1000nx100-container-arm64v8"
elif   [ "$cpuArch" == "x86_64" ]; then
    image="ihorlv/db1000nx100-image"
    container="db1000nx100-container"
else
  echo "No container for your CPU architecture $cpuArch"
  sleep 10
  exit
fi

if ! docker container ls; then
   echo ========================================================================
   echo Docker not running. Please, start Docker Desktop and restart this script
   echo ========================================================================
   sleep 3
   exit
fi

##################################################################################################

function readinput() {
  local CLEAN_ARGS=""
  while [[ $# -gt 0 ]]; do
    local i="$1"
    case "$i" in
      "-i")
        if read -i "default" 2>/dev/null <<< "test"; then
          CLEAN_ARGS="$CLEAN_ARGS -i \"$2\""
        fi
        shift
        shift
        ;;
      "-p")
        CLEAN_ARGS="$CLEAN_ARGS -p \"$2\""
        shift
        shift
        ;;
      *)
        CLEAN_ARGS="$CLEAN_ARGS $1"
        shift
        ;;
    esac
  done
  eval read $CLEAN_ARGS
}

reset
readinput -e -p "How much of your computer's hardware to use (1-100%)  ?    Press ENTER for no limit _" -i "0" hardwareUsageLimit
hardwareUsageLimit=${hardwareUsageLimit:0}
readinput -e -p "Network bandwidth limit (in Mbits)                    ?    Press ENTER for no limit _" -i "0" networkUsageLimit
networkUsageLimit=${networkUsageLimit:0}


##################################################################################################

if [ "$hardwareUsageLimit" != "-1" ]; then
  docker container stop ${container}
  docker rm             ${container}
fi

if [ "$localImage" = 1 ]; then
    echo "==========Using local container=========="
    sleep 5
  	image=${imageLocal}
    docker load  --input "$(pwd)/../${image}.tar"
else
    docker pull ${image}:latest
fi

pwd
docker create --volume "$(pwd)/../put-your-ovpn-files-here":/media/put-your-ovpn-files-here  --privileged  --interactive  --name ${container}  ${image}
docker container start ${container}

echo "docker=1;cpuUsageLimit=${hardwareUsageLimit};ramUsageLimit=${hardwareUsageLimit};networkUsageLimit=${networkUsageLimit}" > "$(pwd)/config.txt"
docker cp "$(pwd)/config.txt" ${container}:/root/DDOS
rm "$(pwd)/config.txt"

if [ "$hardwareUsageLimit" == "-1" ]; then
    docker exec  --interactive  --tty  ${container}  /usr/bin/mc
else
    docker exec  --interactive  --tty  ${container}  /root/DDOS/x100-suid-run.elf
fi

echo "Waiting 10 seconds"
sleep 10
docker container stop ${container}
echo "Docker container stopped"