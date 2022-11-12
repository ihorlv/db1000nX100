@echo off
chcp 65001
rem Dism /online /Get-Features

call :checkAdministrativePermissions
call :checkWindowsHomeEdition

set rebootRequired=0

set feature=Microsoft-Hyper-V
call :dsimInstall

set feature=HypervisorPlatform
call :dsimInstall

if %rebootRequired% EQU 0 (
    echo =============================================
    echo Installation completed. Reboot is not required
    echo =============================================
    pause
    exit
) else (
    call :rebootWindows
)
exit

:checkAdministrativePermissions
    net session >nul 2>&1
    if %errorLevel% EQU 0 (
        echo Administrative permissions confirmed
    ) else (
        echo =============================================================
		echo Administrative privileges are required to perform this action
		echo =============================================================
		pause
		exit
    )
exit /B 0

:dsimInstall
    Dism  /online  /Enable-Feature  /FeatureName:%feature%  /All  /NoRestart
    if %errorLevel% EQU 3010 (
        set rebootRequired=1
        exit /B 0
    )
    if %errorLevel% NEQ 0 (
        echo =============================================================================================
		echo Failed to install %feature%. Please, read this manual
		echo https://docs.microsoft.com/ru-ru/virtualization/hyper-v-on-windows/quick-start/enable-hyper-v
		echo =============================================================================================
		pause
		exit
    )
exit /B 0

:checkWindowsHomeEdition
    wmic os get name | find /i "Home"
    if %errorLevel% EQU 0 (
       echo ==========================================================================
       echo You Windows distributive is HOME but required PRO, ENTERPRISE or EDUCATION
       echo Please use VirtualBox version of X100
       echo ==========================================================================
       pause
       exit
    )
exit /B 0

:rebootWindows
    echo ======================================
    echo Press any key to restart your computer
    echo ======================================
    pause
    shutdown /r
exit /B 0