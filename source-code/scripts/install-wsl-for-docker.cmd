@echo off
chcp 65001
goto check_Permissions
:admin_permissions

wsl --unregister Debian
debian.exe install --root
if %errorlevel% NEQ 0 (

    wsl --install --distribution Debian
    if %errorlevel% EQU 1 (
       echo =======================================================
       echo Please, restart your computer and run this script again
       echo =======================================================
       pause
       exit
    )
)

wsl --set-default-version 2
echo ====================================
echo Windows Subsystem Linux is installed
echo Next install Docker Desktop
echo ====================================


pause
exit
:check_Permissions
    net session >nul 2>&1
    if %errorLevel% == 0 (
        echo Administrative permissions confirmed
		goto admin_permissions
    ) else (
        echo =============================================================
		echo Administrative privileges are required to perform this action
		echo =============================================================
		pause
    )