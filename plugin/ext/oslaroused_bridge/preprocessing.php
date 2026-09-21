<?php
/**
 * CHIM - OSLAroused Bridge preprocessing hook.
 *
 * Catches state broadcasts from the bridge quest script:
 *   AIAgentFunctions.logMessage("oslaroused_state@ActorName@arousal", "status_msg")
 *
 * Writes the arousal value into the local state cache so context_pre.php can
 * inject it into the prompt on the next turn.
 */

$stateFile = __DIR__ . '/state.json';
$settingsFile = __DIR__ . '/settings.json';

function osl_load_state($path)
{
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function osl_save_state($path, $state)
{
    $state['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if (isset($GLOBALS["gameRequest"]) && is_array($GLOBALS["gameRequest"])) {
    $eventType = strtolower($GLOBALS["gameRequest"][0] ?? '');
    $payload = $GLOBALS["gameRequest"][3] ?? '';
    error_log("[CHIM-OSLAroused] preprocessing saw eventType=$eventType payload=" . substr($payload, 0, 120));


    if ($eventType === 'status_msg' && strpos($payload, 'oslaroused_state@') === 0) {
        $parts = explode('@', $payload);
        if (count($parts) >= 3) {
            $actor = trim($parts[1]);
            $arousal = (float)$parts[2];

            $settings = osl_load_state($settingsFile);
            $state = osl_load_state($stateFile);
            if (!isset($state['dynamics']) || !is_array($state['dynamics'])) {
                $state['dynamics'] = [];
            }
            if (!isset($state['dynamics'][$actor]) || !is_array($state['dynamics'][$actor])) {
                $state['dynamics'][$actor] = [];
            }
            $entry = &$state['dynamics'][$actor];
            $entry['arousal'] = $arousal;
            if (!isset($entry['affinity'])) {
                $entry['affinity'] = (float)($settings['affinity_default'] ?? 0);
            }
            $entry['updated_at'] = date('Y-m-d H:i:s');

            osl_save_state($stateFile, $state);
            error_log("[CHIM-OSLAroused] Stored state for $actor: arousal=$arousal affinity=" . ($entry['affinity'] ?? 0));
        }
    }
}
