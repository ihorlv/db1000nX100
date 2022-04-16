#!/bin/bash

#rm /media/sf_DDOS/docker/docker-hack-linux-dist/hack-linux-image.tar
rm -r /root/DDOS
cd ../scripts
./install.bash

/usr/bin/env php ../DB1000N/db1000nAutoUpdater.php

systemctl start    docker
systemctl start    containerd

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
#docker save --output /media/sf_DDOS/docker/docker-hack-linux-dist/hack-linux-image.tar hack-linux-image

docker tag hack-linux-image ihorlv/hack-linux-image:latest
docker login --username=ihorlv
docker push ihorlv/hack-linux-image:latest

cd ../docker
#chmod a-x ./docker-hack-linux-dist/hack-linux-image.tar
rm ./docker-hack-linux-dist.zip
zip -r docker-hack-linux-dist.zip ./docker-hack-linux-dist