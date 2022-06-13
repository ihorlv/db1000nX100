@echo off
call :checkAdministrativePermissions

bcdedit /set hypervisorlaunchtype auto
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