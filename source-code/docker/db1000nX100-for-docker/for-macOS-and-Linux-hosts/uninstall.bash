#!/usr/bin/env bash

docker container stop db1000nx100-container
docker container stop db1000nx100-container-arm64v8

docker rm db1000nx100-container
docker rm db1000nx100-container-arm64v8

docker image rm --force ihorlv/db1000nx100-image
docker image rm --force ihorlv/db1000nx100-image-arm64v8

docker container ls
docker image ls
docker volume ls