@echo off
chcp 65001
title db1000nX100

curl  --version
if %errorlevel% NEQ 0 (
   echo ==========================================
   echo Curl not found. Can't update automatically
   echo ==========================================
   pause
   exit
)

set urlPath=https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/docker/db1000nX100-for-docker/

set basename=run.cmd
call :update

set basename=uninstall.cmd
call :update

set basename=install-hyper-v.cmd
call :update

.\run.cmd
exit

:update
    echo Fetching %urlPath%%basename%
    curl  --fail  --max-time 30  %urlPath%%basename%  -o .\%basename%.update
    if %errorlevel% NEQ 0 (
       del .\%basename%.update
       echo =================
       echo Failed to update
       echo =================
       pause
       exit
    )
    move .\%basename%.update .\%basename%
exit /B 0