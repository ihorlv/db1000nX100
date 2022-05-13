@echo off
setlocal EnableDelayedExpansion 
set localImage=0
set container=db1000nx100-container
set image=ihorlv/db1000nx100-image
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

:set /p     cpuCount=How many CPU core(s) to use  (max. limit) ?    Press ENTER for default value (1)  _
:if "!cpuCount!" equ "" set "cpuCount=1"

:set /p   memorySize=How many GiB of RAM to use   (max. limit) ?    Press ENTER for default value (4)  _
:if "!memorySize!" equ "" set "memorySize=4"

:set /p  vpnQuantity=How many parallel VPN connections to run ?     Press ENTER for default value (10)  _
:if "!vpnQuantity!" equ "" set "vpnQuantity=10"

set /p hardwareUsageLimit=How much of your computer's hardware to use (1-100%)  ?    Press ENTER for no limit _
if "!hardwareUsageLimit!" equ "" set "hardwareUsageLimit=0"

set /p  networkUsageLimit=Network bandwidth limit (in Mbits)                    ?    Press ENTER for no limit _
if "!networkUsageLimit!" equ "" set "networkUsageLimit=0"

:------------------------------------------------------------------------

if !hardwareUsageLimit! NEQ -1 (
    docker container stop !container!
    docker rm !container!
)

if !localImage! EQU 1 (
	cls
	echo "==========Using local container=========="
	timeout 5
	set image=db1000nx100-image-local
    docker load  --input "!CD!\!image!.tar"
) else (
    docker pull  !image!:latest
)

:------------------------------------------------------------------------

@echo on

docker create --volume "!CD!\put-your-ovpn-files-here":/media/put-your-ovpn-files-here  --privileged  --interactive  --name !container!  !image!
docker container start !container!

echo docker=1;cpuUsageLimit=!hardwareUsageLimit!;ramUsageLimit=!hardwareUsageLimit!;networkUsageLimit=!networkUsageLimit! > "!CD!\config.txt"

docker cp "!CD!\config.txt" !container!:/root/DDOS
del "!CD!\config.txt"

if !hardwareUsageLimit! EQU -1 (
	docker exec  --interactive  --tty  !container!  /usr/bin/mc
) else (
	docker exec  --interactive  --tty  !container!  /root/DDOS/x100-suid-run.elf
)

:------------------------------------------------------------------------

echo "Waiting 10 seconds"
timeout 10
docker container stop !container!
echo "Docker container stopped"