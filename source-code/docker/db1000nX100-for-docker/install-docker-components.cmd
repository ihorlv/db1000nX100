@echo off
goto check_Permissions
:admin_permissions

rem dism.exe /online /get-features

dism.exe  /online  /enable-feature  /featurename:VirtualMachinePlatform             /all  /norestart
if %errorlevel% NEQ 0 (
   echo =============================================================
   echo Failed to install Windows component: Virtual Machine Platform
   echo =============================================================
   pause
   exit
)

dism.exe  /online  /enable-feature  /featurename:Microsoft-Windows-Subsystem-Linux  /all  /norestart
if %errorlevel% NEQ 0 (
   echo ============================================================
   echo Failed to install Windows component: Windows Subsystem Linux
   echo ============================================================
   pause
   exit
)

dism.exe  /online  /enable-Feature  /featureName:Microsoft-Hyper-V-All              /all  /norestart
if %errorlevel% NEQ 0 (
   echo ======================================================
   echo Failed to install Windows component: Microsoft Hyper-V
   echo ======================================================
   pause
   exit
)

echo ========================================================
echo Installation completed successfully
echo Please, restart your computer to finish the installation
echo ========================================================



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