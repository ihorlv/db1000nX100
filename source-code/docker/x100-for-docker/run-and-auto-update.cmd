@echo off
setlocal EnableDelayedExpansion
chcp 65001
title X100

find /c "dockerInteractiveConfiguration=1" "!CD!\put-your-ovpn-files-here\x100-config.txt" >NUL
if !errorlevel! EQU 0 (
      echo ==============================================================
      echo dockerInteractiveConfiguration=1 found in your x100-config.txt
      echo Automatic X100 run is not possible in this mode
      echo ==============================================================
      pause
      exit
)

:loop
    call .\update.cmd

	echo 1 > "!CD!\put-your-ovpn-files-here\docker-auto-update.lock"
    call .\run.cmd

	find /c "2" "!CD!\put-your-ovpn-files-here\docker-auto-update.lock" >NUL
	if !errorlevel! NEQ 0 (
        goto ext
    )

    call .\uninstall.cmd
goto loop


:ext
del "!CD!\put-your-ovpn-files-here\docker-auto-update.lock"  >NUL

pause