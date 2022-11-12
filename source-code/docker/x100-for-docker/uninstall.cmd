docker container stop x100-container
docker container stop x100-container-arm64v8

docker rm x100-container
docker rm x100-container-arm64v8

docker image rm --force ihorlv/x100-image
docker image rm --force ihorlv/x100-image-arm64v8

docker container ls
docker image ls
docker volume ls

timeout 10