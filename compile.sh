#!/usr/bin/env bash
set -euo pipefail

cd "/mnt/h/Nolvus Awakening/TOOLS"
./Caprica.exe "H:/Nolvus Awakening/MODS/mods/CHIM - OSLAroused Bridge/Source/Scripts/CHIM_OSLAroused_Bridge.psc" --game skyrim -f "H:/Nolvus Awakening/TOOLS/VanillaScripts/TESV_Papyrus_Flags.flg" -o "H:/Nolvus Awakening/MODS/mods/CHIM - OSLAroused Bridge/Scripts" -i "H:/Nolvus Awakening/MODS/mods/CHIM - OSLAroused Bridge/Source/Scripts;H:/Nolvus Awakening/MODS/mods/CHIM - AI Agent/Source/Scripts;H:/Nolvus Awakening/MODS/mods/OSL Aroused/Scripts/Source;H:/Nolvus Awakening/MODS/mods/Skyrim Script Extender/Scripts/Source;H:/Nolvus Awakening/TOOLS/VanillaScripts"

echo "== Compile finished =="
ls -la "/mnt/h/Nolvus Awakening/MODS/mods/CHIM - OSLAroused Bridge/Scripts/"
