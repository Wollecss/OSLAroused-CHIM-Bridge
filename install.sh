#!/usr/bin/env bash
# CHIM - OSLAroused Bridge installer.
# Copies the HerikaServer extension into the live Apache/PHP server.
# The Skyrim ESP/quest must be created separately with the xEdit script
# (tools/xedit/Create_CHIM_OSLAroused_Bridge_Quest.pas).
#
# Usage (from WSL):
#   bash "/mnt/h/Nolvus Awakening/MODS/mods/CHIM - OSLAroused Bridge/install.sh"

set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/plugin/ext/oslaroused_bridge"
DEST_DIR="/var/www/html/HerikaServer/ext/oslaroused_bridge"

echo "== CHIM - OSLAroused Bridge installer =="
echo "Source: $SRC_DIR"
echo "Dest:   $DEST_DIR"

if [ ! -d "$SRC_DIR" ]; then
    echo "ERROR: plugin source not found at $SRC_DIR" >&2
    exit 1
fi

mkdir -p "$DEST_DIR"

for f in functions.php context_pre.php preprocessing.php prerequest.php config.php manifest.json settings.json json_response_custom.php header.png; do
    if [ -f "$SRC_DIR/$f" ]; then
        cp -f "$SRC_DIR/$f" "$DEST_DIR/$f"
        echo "  installed $f"
    fi
done

# Seed state.json if missing so the config UI and context_pre.php have a file to read.
if [ ! -f "$DEST_DIR/state.json" ]; then
    echo '{}' > "$DEST_DIR/state.json"
    echo "  seeded state.json"
else
    echo "  kept existing state.json"
fi

chmod 664 "$DEST_DIR"/*.json 2>/dev/null || true

echo "== Install complete =="
echo "Config UI: http://localhost:8081/HerikaServer/ext/oslaroused_bridge/config.php"
echo "Next steps:"
echo "  1. Create CHIM_OSLAroused_Bridge.esp through Mod Organizer 2 (see readme.md for Method A/B)."
echo "  2. Build the Papyrus script with compile.sh."
echo "  3. Enable this mod in your mod manager - the ESP, Scripts/CHIM_OSLAroused_Bridge.pex, and"
echo "     SKSE/Plugins/CHIM_OSLAroused_Bridge.dll all live in this same mod folder now."
echo "  4. Start Skyrim; the quest will auto-start and the SKSE plugin will register."
