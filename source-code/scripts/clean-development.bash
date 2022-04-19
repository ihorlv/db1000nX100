#!/usr/bin/env bash

dockerContainer="hack-linux-container"
dockerImageLocal="hack-linux-image"
dockerImageRemote="ihorlv/hack-linux-image"

docker container stop   $dockerContainer
docker rm               $dockerContainer
docker image rm --force $dockerImageRemote
docker image rm --force $dockerImageLocal
docker image rm --force debian
docker image   prune --all --force
docker system  prune --all --force

docker container ls
docker image ls
docker volume ls

systemctl stop    docker
systemctl disable docker
systemctl stop    containerd
systemctl disable containerd
systemctl stop    rsyslog
systemctl disable rsyslog

#systemctl enable systemd-tmpfiles-clean.timer
#systemctl enable systemd-tmpfiles-clean
#systemctl enable phpsessionclean.timer
#systemctl enable phpsessionclean

