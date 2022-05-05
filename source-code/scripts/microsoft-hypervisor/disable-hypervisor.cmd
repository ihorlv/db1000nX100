@echo off
goto check_Permissions
:admin_permissions

bcdedit /set hypervisorlaunchtype off
if %errorlevel% NEQ 0 (
   echo =============================
   echo Failure: Something went wrong
   echo =============================
   pause
   exit
)

echo ======================================
echo Press any key to restart your computer
echo ======================================
pause
shutdown /r

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