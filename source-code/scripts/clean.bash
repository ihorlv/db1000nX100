#!/usr/bin/env bash

docker container stop hack-linux-container
docker rm hack-linux-container
docker image rm --force hack-linux-image
docker image rm --force debian
docker image   prune --all --force
docker system  prune --all --force
docker container ls
docker image ls
docker volume ls

systemctl disable docker
systemctl disable containerd
systemctl disable rsyslog

systemctl enable systemd-tmpfiles-clean.timer
systemctl enable systemd-tmpfiles-clean
systemctl enable phpsessionclean.timer
systemctl enable phpsessionclean

echo '' > /root/.bash_history
echo '' > /home/user/.bash_history
rm -r /var/log/*
rm -r /var/mail/*
rm -r /tmp/*
rm -r /var/tmp/*