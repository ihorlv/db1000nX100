@echo off
setlocal EnableDelayedExpansion 
set localImage=0
set container=db1000nx100-container
set image=ihorlv/db1000nx100-image
chcp 65001
title db1000nX100

:------------------------------------------------------------------------

docker container ls
if !errorlevel! NEQ 0 (
   cls
   echo ========================================================================
   echo Docker not running. Please, start Docker Desktop and restart this script
   echo ========================================================================
   pause
   exit
)

docker info | find "WSL"
if %errorLevel% EQU 0 (
   echo ========================================
   echo Switch Docker from WSL to Hyper-V engine
   echo https://x100.vn.ua/hyper-v/
   echo ========================================
   pause
   exit
)

:------------------------------------------------------------------------

find /c "dockerInteractiveConfiguration=0" "!CD!\put-your-ovpn-files-here\db1000nX100-config.txt" >NUL
if !errorlevel! EQU 0 (
    set dockerInteractiveConfiguration=0
) else (
    set dockerInteractiveConfiguration=1
)

if !dockerInteractiveConfiguration! EQU 0 (
    del "!CD!\put-your-ovpn-files-here\db1000nX100-config-override.txt" 2>NUL
) else (
    cls
    set /p networkUsageLimit="How much of your network bandwidth to use (20-100%%)   ?   Press ENTER for 90%% limit _"
    if "!networkUsageLimit!" equ "" set "networkUsageLimit=90"
    echo dockerHost=Windows;networkUsageLimit=!networkUsageLimit! > "!CD!\put-your-ovpn-files-here\db1000nX100-config-override.txt"
)

:------------------------------------------------------------------------

if !networkUsageLimit! NEQ -1 (
    docker container stop !container!
    docker rm !container!
)

if !localImage! EQU 1 (
	cls
	echo "==========Using local container=========="
	timeout 3
	set image=db1000nx100-image-local
    docker load  --input "!CD!\!image!.tar"
) else (
    docker pull  !image!:latest
)

:------------------------------------------------------------------------

@echo on

docker create  --volume "!CD!\put-your-ovpn-files-here":/media/put-your-ovpn-files-here  --privileged  --interactive  --name !container!  !image!
docker container start !container!

if !networkUsageLimit! EQU -1 (
	docker exec  --interactive  --tty  !container!  /usr/bin/mc
) else (
	docker exec  --interactive  --tty  !container!  /root/DDOS/x100-suid-run.elf
)

:------------------------------------------------------------------------

timeout 5
docker container stop !container!
@echo off
echo ===================================================
echo Docker container stopped. You may close this window
echo =================================================== 
pause