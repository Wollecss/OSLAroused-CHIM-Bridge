#Requires -Version 3.0
$ErrorActionPreference = 'Stop'

$toolsDir = 'H:/Nolvus Awakening/TOOLS'
$sseEditDir = Join-Path $toolsDir 'SSE Edit'
$scriptName = 'Create_CHIM_OSLAroused_Bridge_Quest.pas'
$sourcePath = Join-Path $PSScriptRoot $scriptName

if (!(Test-Path $sourcePath)) {
    throw "Cannot find xEdit script: $sourcePath"
}

$editScriptsDir = Join-Path $sseEditDir 'Edit Scripts'
if (!(Test-Path $editScriptsDir)) {
    throw "Cannot find SSEEdit Edit Scripts folder: $editScriptsDir"
}

Copy-Item $sourcePath -Destination (Join-Path $editScriptsDir $scriptName) -Force

$sseEditExe = Join-Path $sseEditDir 'SSEEdit.exe'
$arguments = '-script:"Create_CHIM_OSLAroused_Bridge_Quest.pas"'

Write-Host "Launching SSEEdit with $scriptName ..."
Write-Host "If you are running this through Mod Organizer 2, the module selection window"
Write-Host "should show your managed mods. If it only lists vanilla plugins, close SSEEdit"
Write-Host "and add SSEEdit.exe directly as an MO2 executable (see readme.md)."

$process = Start-Process -FilePath $sseEditExe -ArgumentList $arguments -PassThru -Wait

Write-Host "SSEEdit closed. If you ran this through MO2, the ESP should be in your Overwrite or active profile Data folder."
