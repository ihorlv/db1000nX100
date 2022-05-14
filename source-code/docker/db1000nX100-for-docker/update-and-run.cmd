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

set basename=run.cmd
set urlPath=https://raw.githubusercontent.com/ihorlv/db1000nX100/main/source-code/docker/db1000nX100-for-docker/
call :update
set basename=uninstall.cmd
call :update
.\run.cmd
exit

:update
    echo Fetching %urlPath%%basename%
    curl  --max-time 30  %urlPath%%basename%  -o .\%basename%.update
    if %errorlevel% NEQ 0 (
       del .\%basename%.update
       echo =================
       echo Failed to update
       echo =================
       pause
       exit
    )
    move .\%basename%.update .\%basename%.1
exit /B 0