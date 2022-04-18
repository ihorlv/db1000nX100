@echo off
setlocal EnableDelayedExpansion 
set container=hack-linux-container
set image=ihorlv/hack-linux-image
set localContainer=0
chcp 65001
title db1000nX100

docker container ls
if !errorlevel! NEQ 0 (
   cls
   echo ========================================================================
   echo Docker not running. Please, start Docker Desktop and restart this script
   echo ========================================================================
   pause
   exit
)

cls

:------------------------------------------------------------------------

set /p    cpuCount=How many CPU core(s) to use  (max. limit) ?    Press ENTER for default value (1)  _
if "!cpuCount!" equ "" set "cpuCount=1"

set /p  memorySize=How many GiB of RAM to use   (max. limit) ?    Press ENTER for default value (4)  _
if "!memorySize!" equ "" set "memorySize=4"

set /p vpnQuantity=How many parallel VPN connections to run ?     Press ENTER for auto calculation   _
if "!vpnQuantity!" equ "" set "vpnQuantity=0"

@echo on

:------------------------------------------------------------------------

docker container stop !container!
docker rm !container!

if !localContainer! EQU 1 (
	cls
	:echo "==========Using local container=========="
	:timeout 10
	set image=hack-linux-image
    docker load  --input "!CD!\!image!.tar"
) else (

    docker pull  !image!:latest
)

:------------------------------------------------------------------------

docker create --cpus="!cpuCount!" --memory="!memorySize!g" --memory-swap="20g" --volume "!CD!\put-your-ovpn-files-here":/media/ovpn  --privileged  --interactive  --name !container!  !image!
docker container start !container!

echo cpus=!cpuCount!;memory=!memorySize!;vpnQuantity=!vpnQuantity! > "!CD!\docker.config"
docker cp "!CD!\docker.config" !container!:/root/DDOS
del "!CD!\docker.config"

docker exec  --interactive  --tty  !container!  /root/DDOS/hack-linux-runme.elf
echo "Waiting 10 seconds"
timeout 10
docker container stop !container!
echo "Docker container stopped"