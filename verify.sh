#!/usr/bin/env bash
# CHIM - OSLAroused Bridge post-install verification.

EXT="/var/www/html/HerikaServer/ext/oslaroused_bridge"

echo "=== Extension files ==="
for f in functions.php context_pre.php preprocessing.php prerequest.php config.php manifest.json settings.json json_response_custom.php header.png state.json; do
    if [ -f "$EXT/$f" ]; then
        echo "OK   $f"
    else
        echo "MISS $f"
    fi
done

echo
echo "=== PHP lint ==="
for f in functions.php context_pre.php preprocessing.php prerequest.php config.php; do
    php -l "$EXT/$f"
done

echo
echo "=== state.json ==="
cat "$EXT/state.json" 2>/dev/null || echo "{}"

echo
echo "=== settings.json ==="
cat "$EXT/settings.json" 2>/dev/null || echo "{}"

echo
echo "=== Verify complete ==="
