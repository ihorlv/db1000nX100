@echo off
setlocal EnableDelayedExpansion
chcp 65001
title X100

find /c "dockerInteractiveConfiguration=1" "!CD!\put-your-ovpn-files-here\x100-config.txt" >NUL
if !errorlevel! EQU 0 (
      echo dockerInteractiveConfiguration=1 found in your x100-config.txt
      echo Automatic X100 run is not possible in this mode
      pause
      exit
)

:loop
  call .\update.cmd
  echo autoUpdate=1 > "!CD!\put-your-ovpn-files-here\x100-config-override.txt"
  call .\run.cmd
  echo X100 will be restarted after update
  echo Press Ctr+C now if you wish to stop infinite update cycle
  sleep 30
  call .\uninstall.cmd
goto loop

pause