#!/usr/bin/env bash

docker container stop hack-linux-container
docker rm hack-linux-container
docker image rm --force ihorlv/hack-linux-image
docker container ls
docker image ls
docker volume ls