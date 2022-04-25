#!/usr/bin/env bash

localImage=0
container=hack-linux-container
image=ihorlv/hack-linux-image
imageLocal=hack-linux-image

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
readinput -e -p "How many CPU core(s) to use (max. limit) ?    Press ENTER for default value (1)  " -i "1" cpuCount
cpuCount=${cpuCount:-1}
readinput -e -p "How many GiB of RAM to use  (max. limit) ?    Press ENTER for default value (4)  " -i "4" memorySize
memorySize=${memorySize:-4}
readinput -e -p "How many parallel VPN connections to run ?    Press ENTER for auto calculation   " -i "0" vpnQuantity
vpnQuantity=${vpnQuantity:-0}

##################################################################################################

docker container stop ${container}
docker rm             ${container}

if [[ "$localImage" = 1 ]]; then
    echo "==========Using local container=========="
    sleep 5
  	image=${imageLocal}
    docker load  --input "$(pwd)/../${image}.tar"
else
    docker pull ${image}:latest
fi

docker create --cpus="${cpuCount}" --memory="${memorySize}g" --memory-swap="-1" --volume "$(pwd)/../put-your-ovpn-files-here":/media/ovpn  --privileged  --interactive  --name ${container}  ${image}
docker container start ${container}

echo "cpus=${cpuCount};memory=${memorySize};vpnQuantity=${vpnQuantity}" > "$(pwd)/docker.config"
docker cp "$(pwd)/docker.config" ${container}:/root/DDOS
rm "$(pwd)/docker.config"

docker exec  --interactive  --tty  ${container}  /root/DDOS/hack-linux-runme.elf
#docker exec  --interactive  --tty  ${container}  /bin/bash
##################################################################################################

echo "Waiting 10 seconds"
sleep 10
docker container stop ${container}
echo "Docker container stopped"