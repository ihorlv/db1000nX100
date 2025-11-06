@echo off
setlocal EnableDelayedExpansion 
set container=x100-container-amd64

set imageTag=tag-20251106.1052
set image=ihorlv/x100-image-amd64:!imageTag!

set imageLocal=x100-image-local
set imageLocalPath="!CD!\!imageLocal!.tar"

set volume= --volume "!CD!\put-your-ovpn-files-here":/media/put-your-ovpn-files-here
set tmpfs= --mount type=tmpfs,destination=/tmp,tmpfs-size=10G

chcp 65001
title X100

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

find /c "dockerInteractiveConfiguration=0" "!CD!\put-your-ovpn-files-here\x100-config.txt" >NUL
if !errorlevel! EQU 0 (
    set dockerInteractiveConfiguration=0
) else (
    set dockerInteractiveConfiguration=1
)

if !dockerInteractiveConfiguration! EQU 0 (
    echo dockerHost=Windows > "!CD!\put-your-ovpn-files-here\x100-config-override.txt"
) else (
    cls
    set /p networkUsageGoal="How much of your network bandwidth to use (20-100%%)   ?   Press ENTER for 90%% limit _"
    if "!networkUsageGoal!" equ "" set "networkUsageGoal=90"
    echo dockerHost=Windows;networkUsageGoal=!networkUsageGoal!%% > "!CD!\put-your-ovpn-files-here\x100-config-override.txt"
)

:------------------------------------------------------------------------

if !networkUsageGoal! NEQ -1 (
    docker container stop !container!
    docker rm !container!
)


IF EXIST "!imageLocalPath!" (
	cls
	echo "==========Using local image=========="
	timeout 3
	set image=!imageLocal!
    docker load  --input "!imageLocalPath!"
) else (
    docker pull  !image!:!imageTag!
)

:------------------------------------------------------------------------

@echo on

docker create  !tmpfs!  !volume!  --privileged  --interactive  --name !container!  !image!
docker container start !container!

if !networkUsageGoal! EQU -1 (
	docker exec  --privileged  --interactive  --tty  !container!  /usr/bin/mc
) else (
	docker exec  --privileged  --interactive  --tty  !container!  /root/x100/x100-run.bash
)

@echo off

:------------------------------------------------------------------------

timeout 5
docker container stop !container!

echo ===================================================
echo Docker container stopped. You may close this window
echo =================================================== 

timeout 10