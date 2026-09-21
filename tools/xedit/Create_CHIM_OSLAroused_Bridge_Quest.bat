@echo off
setlocal

set "toolsDir=H:\Nolvus Awakening\TOOLS"
set "sseEditDir=%toolsDir%\SSE Edit"
set "scriptName=Create_CHIM_OSLAroused_Bridge_Quest.pas"
set "sourcePath=%~dp0%scriptName%"
set "editScriptsDir=%sseEditDir%\Edit Scripts"

if not exist "%sourcePath%" (
    echo ERROR: Cannot find xEdit script: %sourcePath%
    pause
    exit /b 1
)

if not exist "%editScriptsDir%" (
    echo ERROR: Cannot find SSEEdit Edit Scripts folder: %editScriptsDir%
    pause
    exit /b 1
)

copy /Y "%sourcePath%" "%editScriptsDir%\%scriptName%" >nul

echo Launching SSEEdit with %scriptName% ...
echo.
echo If you are running this through Mod Organizer 2, the module selection
echo window should show your managed mods. If it only lists vanilla plugins,
echo close SSEEdit and use Method B in readme.md ^(add SSEEdit.exe directly^).
echo.

"%sseEditDir%\SSEEdit.exe" -script:"%scriptName%"

echo.
echo SSEEdit closed. If you ran this through MO2, the ESP should be in
echo your Overwrite or active profile Data folder.
pause
