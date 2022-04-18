#!/usr/bin/env bash

dockerSrcDir=$(pwd)
dockerBuildDir="${dockerSrcDir}/db1000nX100-for-docker"
srcDir="${dockerSrcDir%/*}"                                     #https://stackoverflow.com/questions/23162299/how-to-get-the-last-part-of-dirname-in-bash
scriptsDir="${srcDir}/scripts"
buildDir="/root/DDOS"
version=$(cat "${srcDir}/version.txt")
echo $version

read -e -p "Public build ? " -i "0" isPublicBuild

rm -r "${buildDir}"
/usr/bin/env php "${srcDir}/DB1000N/db1000nAutoUpdater.php"

cd "${scriptsDir}"
pwd
./install.bash

systemctl start    docker
systemctl start    containerd

cd ${dockerBuildDir}
pwd

docker pull debian
docker container stop hack-linux-container
docker rm hack-linux-container
docker create --interactive --name hack-linux-container debian
docker container start hack-linux-container

docker exec hack-linux-container   apt -y update
docker exec hack-linux-container   apt -y install  procps kmod iputils-ping curl php-cli php-mbstring php-curl openvpn git mc
docker cp /root/DDOS hack-linux-container:/root/DDOS

docker container stop hack-linux-container
docker commit hack-linux-container hack-linux-image

rm     ./hack-linux-image.tar

if [[ "$isPublicBuild" = 1 ]]; then

  docker tag hack-linux-image ihorlv/hack-linux-image:latest
  docker login --username=ihorlv
  docker push ihorlv/hack-linux-image:latest

  rm              "../*-db1000nX100-for-docker.zip"
  zip -r "../${version}-db1000nX100-for-docker.zip" ./

else

    #docker save --output ./hack-linux-image.tar hack-linux-image

fi

