#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"
cd ../

imageTag="tag-20230720.1138"
imageLocal=x100-image-local
imageLocalPath="$(pwd)/${imageLocal}.tar"

cpuArch=$(uname -m)
dockerHost=$(uname)

volume=" --volume "$(pwd)/put-your-ovpn-files-here":/media/put-your-ovpn-files-here"
tmpfs=" --mount type=tmpfs,destination=/tmp,tmpfs-size=10G"

if   [ "$cpuArch" == "arm64" ]; then
     # Apple M1
     image="ihorlv/x100-image-arm64v8"
     container="x100-container-arm64v8"
elif [ "$cpuArch" == "aarch64" ]; then
     #Linux arm64v8
     image="ihorlv/x100-image-arm64v8"
     container="x100-container-arm64v8"
elif [ "$cpuArch" == "armv7l" ]; then
     #Linux arm32v7
     image="ihorlv/x100-image-arm32v7"
     container="x100-container-arm32v7"
elif [ "$cpuArch" == "x86_64" ]; then
     image="ihorlv/x100-image"
     container="x100-container"
else
  echo "No container for your CPU architecture $cpuArch"
  sleep 10
  exit
fi

image="${image}:${imageTag}"

if ! docker container ls   1>/dev/null   2>/dev/null; then
   echo ========================================================================
   echo Docker not running. Please, start Docker Desktop and restart this script
   echo ========================================================================
   sleep 3
   exit
fi

if [ -t 0 ]; then
  ttyArgument="--tty"
else
  ttyArgument=""
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

### ### ###

if grep -s -q dockerInteractiveConfiguration=0 "$(pwd)/put-your-ovpn-files-here/x100-config.txt"; then
  dockerInteractiveConfiguration=0
else
  dockerInteractiveConfiguration=1
fi

if [ "$dockerInteractiveConfiguration" == 0 ]; then
  echo "dockerHost=${dockerHost}" > "$(pwd)/put-your-ovpn-files-here/x100-config-override.txt"
else

  reset

  if [ "$dockerHost" == "Linux" ]; then
    readinput -e -p "How much of your computer's CPU to use (10-100%)  ?   Press ENTER for 50% limit _" -i "50" cpuUsageGoal
    cpuUsageGoal=${cpuUsageGoal:=50}
    readinput -e -p "How much of your computer's RAM to use (10-100%)  ?   Press ENTER for 50% limit _" -i "50" ramUsageGoal
    ramUsageGoal=${ramUsageGoal:=50}
  fi

  readinput -e -p "How much of your network bandwidth to use (20-100%)     ?   Press ENTER for 90% limit _" -i "90" networkUsageGoal
  networkUsageGoal=${networkUsageGoal:=90}

  echo "dockerHost=${dockerHost};cpuUsageGoal=${cpuUsageGoal}%;ramUsageGoal=${ramUsageGoal}%;networkUsageGoal=${networkUsageGoal}%" > "$(pwd)/put-your-ovpn-files-here/x100-config-override.txt"

fi

##################################################################################################

if [ "$networkUsageGoal" != "-1" ]; then
  docker container stop ${container}   2>/dev/null
  docker rm             ${container}   2>/dev/null
fi

if [ -f "${imageLocalPath}" ]; then
    echo "==========Using local container=========="
    sleep 2
  	image=${imageLocal}
    docker load  --input "${imageLocalPath}"
else
    docker pull ${image}
fi

docker create  ${tmpfs}  ${volume}  --privileged  --interactive  --name ${container}  ${image}
docker container start ${container}

if [ "$networkUsageGoal" == "-1" ]; then
    docker exec  --privileged  --interactive  ${ttyArgument}  ${container}  /usr/bin/mc
else
    docker exec  --privileged  --interactive  ${ttyArgument}  ${container}  /root/x100/x100-run.bash
fi

echo "Waiting 10 seconds"
sleep 10
docker container stop ${container}
echo "Docker container stopped"