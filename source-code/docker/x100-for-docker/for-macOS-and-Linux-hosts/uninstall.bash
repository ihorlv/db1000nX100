#!/usr/bin/env bash

containers=$(docker container ls -q --filter 'name=x100-*')
images=$(docker image ls -q "ihorlv/x100*")

if [ $containers ]; then

  echo $containers
  docker container stop $containers
  docker rm $containers

fi

if [ $images ]; then

  echo $images
  docker image rm --force $images

fi
