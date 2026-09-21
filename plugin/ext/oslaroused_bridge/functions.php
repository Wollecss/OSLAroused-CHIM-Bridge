<?php
/**
 * CHIM - OSLAroused Bridge
 * Adds an action-catalog tool (EvaluateDynamics) that lets the LLM adjust NPC
 * Arousal/Affinity in real time. A post-filter transforms that action into a
 * CHIM rolecommand sent to the Skyrim bridge quest script.
 */

$OSL_STATE_FILE = __DIR__ . '/state.json';
$OSL_SETTINGS_FILE = __DIR__ . '/settings.json';

error_log("[CHIM-OSLAroused] functions.php loaded");

// ---------------------------------------------------------------------------
// Modern CHIM: seed the core_action catalog so the action appears in the
// action editor and can be enabled/disabled like any other action.
// ---------------------------------------------------------------------------
if (isset($GLOBALS["db"]) && is_object($GLOBALS["db"])) {
    $table = "public.core_action";
    $codeName = "EvaluateEmotionalDynamics";
    $actionName = "EvaluateEmotionalDynamics";
    $description =
        "[ABSOLUTE PRIORITY] Use this action whenever the player says or does anything that would change " .
        "this NPC's physical arousal or emotional affinity toward them. This includes flirtation, compliments, " .
        "insults, intimacy, rejection, suggestive dialogue, touching, kissing, sexual remarks, or any charged moment. " .
        "You MUST call this action FIRST before replying with dialogue or picking a physical action such as ComeCloser, HoldHands, or Kiss. " .
        "When calling this action, output the JSON fields: actor (NPC name), delta_arousal (-25 to +25), " .
        "delta_affinity (-25 to +25), reaction_tag (flustered, teasing, receptive, wary, annoyed, eager, aroused), " .
        "and reason (private, never spoken). Assess the reaction and adjust arousal/affinity with small deltas. " .
        "Reason is private; do not speak it aloud.";
    $returnMessage = "#HERIKA_NAME#'s arousal and affinity were adjusted based on the interaction.";

    $parametersJson = json_encode([
        "type" => "object",
        "properties" => [
            "actor" => [
                "type" => "string",
                "description" => "Name of the NPC whose arousal/affinity is being adjusted."
            ],
            "delta_arousal" => [
                "type" => "number",
                "description" => "Change in physical arousal, typically -25 to +25."
            ],
            "delta_affinity" => [
                "type" => "number",
                "description" => "Change in emotional affinity, typically -25 to +25."
            ],
            "reaction_tag" => [
                "type" => "string",
                "description" => "Short label for the NPC's reaction, e.g. flustered, teasing, receptive, wary, annoyed, eager, aroused."
            ],
            "reason" => [
                "type" => "string",
                "description" => "Private reason for the adjustment. Do not speak this aloud."
            ]
        ],
        "required" => ["actor", "delta_arousal", "delta_affinity", "reaction_tag"]
    ]);

    $metadataJson = json_encode([
        "builtin" => false,
        "source" => "oslaroused_bridge"
    ]);

    try {
        $db = $GLOBALS["db"];
        $escCode = $db->escapeLiteral($codeName);
        $escAction = $db->escapeLiteral($actionName);
        $escDesc = $db->escapeLiteral($description);
        $escReturn = $db->escapeLiteral($returnMessage);
        $escParams = $db->escapeLiteral($parametersJson);
        $escMeta = $db->escapeLiteral($metadataJson);

        $sql = "INSERT INTO $table (
                code_name,
                action_name,
                description,
                return_message,
                available_to_npc,
                available_to_followers,
                available_to_narrator,
                is_activated,
                parameters_json,
                metadata,
                game_function,
                import_version,
                script_proxy_program
            ) VALUES (
                $escCode,
                $escAction,
                $escDesc,
                $escReturn,
                TRUE,
                TRUE,
                FALSE,
                TRUE,
                $escParams::jsonb,
                $escMeta::jsonb,
                TRUE,
                1,
                NULL
            )
            ON CONFLICT (code_name) DO UPDATE SET
                action_name = EXCLUDED.action_name,
                description = EXCLUDED.description,
                return_message = EXCLUDED.return_message,
                available_to_npc = EXCLUDED.available_to_npc,
                available_to_followers = EXCLUDED.available_to_followers,
                available_to_narrator = EXCLUDED.available_to_narrator,
                is_activated = EXCLUDED.is_activated,
                parameters_json = EXCLUDED.parameters_json,
                metadata = EXCLUDED.metadata,
                game_function = EXCLUDED.game_function,
                import_version = EXCLUDED.import_version,
                script_proxy_program = EXCLUDED.script_proxy_program,
                updated_at = NOW()";

        $db->execQuery($sql);
        error_log("[CHIM-OSLAroused] core_action seeded/updated: " . $codeName);
    } catch (Throwable $e) {
        error_log("[CHIM-OSLAroused] core_action seed failed: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Legacy / fallback registration for older CHIM builds that still read the
// global FUNCTIONS arrays directly.
// ---------------------------------------------------------------------------
$GLOBALS["F_NAMES"]["EvaluateDynamics"] = "EvaluateEmotionalDynamics";
$GLOBALS["F_TRANSLATIONS"]["EvaluateDynamics"] =
    "Use whenever the player says or does something that would change this NPC's physical arousal or emotional affinity toward them, " .
    "such as flirtation, a compliment, an insult, intimacy, rejection, or suggestive dialogue. " .
    "Assess the reaction and adjust arousal/affinity with small deltas (typical -25 to +25). " .
    "Provide a reaction_tag such as flustered, teasing, receptive, wary, annoyed, or eager. " .
    "Reason is private; do not speak it aloud.";
$GLOBALS["F_RETURNMESSAGES"]["EvaluateDynamics"] = "";

$GLOBALS["FUNCTIONS"][] = [
    "name" => $GLOBALS["F_NAMES"]["EvaluateDynamics"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["EvaluateDynamics"],
    "parameters" => [
        "type" => "object",
        "properties" => [
            "actor" => ["type" => "string", "description" => "Exact name of the NPC to adjust."],
            "delta_arousal" => ["type" => "number", "description" => "Arousal change (positive = more aroused). Typical -25 to +25."],
            "delta_affinity" => ["type" => "number", "description" => "Affinity change (positive = closer). Typical -25 to +25."],
            "reaction_tag" => ["type" => "string", "description" => "Emotional label, e.g. flustered, teasing, receptive, wary."],
            "reason" => ["type" => "string", "description" => "Private reason (do not speak aloud)."],
        ],
        "required" => ["actor", "delta_arousal", "delta_affinity", "reaction_tag"],
    ],
];

$GLOBALS["ENABLED_FUNCTIONS"][] = "EvaluateDynamics";

// ---------------------------------------------------------------------------
// Server-side funcret handler (only used if the Papyrus side sends a funcret
// return; the bridge quest currently does not, so this is a no-op fallback).
// ---------------------------------------------------------------------------
$GLOBALS["FUNCSERV"]["EvaluateDynamics"] = function () {
    error_log("[CHIM-OSLAroused] EvaluateDynamics funcret received: " . ($GLOBALS["gameRequest"][3] ?? ''));
};

// ---------------------------------------------------------------------------
// Shared helpers for the post-filter hook.
// ---------------------------------------------------------------------------
if (!function_exists('chim_osl_sanitize_payload_part')) {
    function chim_osl_sanitize_payload_part($value)
    {
        $value = strval($value);
        $value = str_replace(["|", "@", "
", "
"], " ", $value);
        return trim($value);
    }
}

if (!function_exists('chim_osl_load_state')) {
    function chim_osl_load_state($path)
    {
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}

if (!function_exists('chim_osl_save_state')) {
    function chim_osl_save_state($path, $state)
    {
        $state['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// ---------------------------------------------------------------------------
// SHARMAT COMPATIBILITY (optional, off by default): lets an NPC's OSLAroused
// arousal - as tracked by this bridge - stand in for SHARMAT's own Open Mode
// for that one NPC. SHARMAT calls this via function_exists(), so it stays
// completely inert if this extension isn't installed; nothing here reaches
// into SHARMAT's or CHIM's own data, it only reads our own state.json.
// ---------------------------------------------------------------------------
if (!function_exists('chim_osl_arousal_override_active')) {
    function chim_osl_arousal_override_active($npcName)
    {
        $settingsFile = __DIR__ . '/settings.json';
        $stateFile = __DIR__ . '/state.json';

        $settings = chim_osl_load_state($settingsFile);
        if (empty($settings['sharmat_override_enabled'])) {
            return false;
        }

        $threshold = (float)($settings['sharmat_override_threshold'] ?? 75);
        $state = chim_osl_load_state($stateFile);
        $arousal = (float)($state['dynamics'][$npcName]['arousal'] ?? 0);

        return $arousal >= $threshold;
    }
}

// ---------------------------------------------------------------------------
// Post-filter: turn the AI action into a CHIM rolecommand and update the
// server-side affinity value immediately.
// ---------------------------------------------------------------------------
$GLOBALS["action_post_process_fnct_ex"][] = function ($actions) use ($OSL_STATE_FILE, $OSL_SETTINGS_FILE) {
    foreach ($actions as $n => $action) {
        $actionParts = explode("|", $action);
        if (count($actionParts) < 3) {
            continue;
        }
        $actionParts2 = explode("@", $actionParts[2]);
        $actionCodeName = $actionParts2[0] ?? '';
        $resolvedCode = (function_exists('getFunctionCodeName'))
            ? getFunctionCodeName($actionCodeName)
            : $actionCodeName;
        if ($resolvedCode === false || $resolvedCode === '') {
            $resolvedCode = $actionCodeName;
        }
        if ($resolvedCode !== 'EvaluateDynamics' && $actionCodeName !== 'EvaluateEmotionalDynamics') {
            continue;
        }

        $rawParam = implode("@", array_slice($actionParts2, 1));
        $rawParam = trim($rawParam);
        if ($rawParam === '' || ($rawParam[0] !== '{' && $rawParam[0] !== '[')) {
            error_log("[CHIM-OSLAroused] Malformed EvaluateDynamics parameters: " . substr($rawParam, 0, 200));
            continue;
        }
        $paramData = json_decode($rawParam, true);
        if (!is_array($paramData)) {
            error_log("[CHIM-OSLAroused] Could not decode EvaluateDynamics parameters: " . substr($rawParam, 0, 200));
            continue;
        }

        $actor = chim_osl_sanitize_payload_part($paramData['actor'] ?? '');
        $deltaArousal = (float)($paramData['delta_arousal'] ?? 0);
        $deltaAffinity = (float)($paramData['delta_affinity'] ?? 0);
        $reactionTag = chim_osl_sanitize_payload_part($paramData['reaction_tag'] ?? '');
        $reason = chim_osl_sanitize_payload_part($paramData['reason'] ?? '');

        if ($actor === '') {
            error_log("[CHIM-OSLAroused] EvaluateDynamics missing actor");
            continue;
        }

        $settings = chim_osl_load_state($OSL_SETTINGS_FILE);
        $state = chim_osl_load_state($OSL_STATE_FILE);
        if (!isset($state['dynamics']) || !is_array($state['dynamics'])) {
            $state['dynamics'] = [];
        }
        if (!isset($state['dynamics'][$actor]) || !is_array($state['dynamics'][$actor])) {
            $state['dynamics'][$actor] = [];
        }
        $entry = &$state['dynamics'][$actor];
        $currentAffinity = isset($entry['affinity']) ? (float)$entry['affinity'] : (float)($settings['affinity_default'] ?? 0);

        // PER-ACTOR GAIN COOLDOWN: while an actor is still inside their cooldown window,
        // this whole event is a no-op - no state change, no rolecommand, no notification.
        // Prevents rapid-fire dialogue from maxing an actor out in a handful of turns.
        if (!empty($settings['gain_cooldown_enabled']) && !empty($entry['updated_at'])) {
            $cooldownSeconds = (float)($settings['gain_cooldown_seconds'] ?? 30);
            $elapsed = time() - strtotime($entry['updated_at']);
            if ($cooldownSeconds > 0 && $elapsed < $cooldownSeconds) {
                error_log("[CHIM-OSLAroused] $actor still in gain cooldown (" . round($cooldownSeconds - $elapsed) . "s left); dropping this update.");
                continue;
            }
        }

        // Per-dimension on/off. A disabled dimension never reaches the game or the stats file.
        if (empty($settings['arousal_enabled'] ?? true)) {
            $deltaArousal = 0.0;
        }
        if (empty($settings['affinity_enabled'] ?? true)) {
            $deltaAffinity = 0.0;
        }

        // AFFINITY-SCALED AROUSAL (optional): an NPC who likes you responds more; one who
        // doesn't, less. 0.5x at -100 affinity, 1.0x at 0, 1.5x at +100 - scales the LLM's
        // own delta rather than replacing it, so "how much" is still the model's call.
        if (!empty($settings['affinity_scaling_enabled']) && $deltaArousal != 0.0) {
            $scale = 0.5 + (($currentAffinity + 100.0) / 200.0);
            $deltaArousal *= $scale;
        }

        // Per-dimension min/max, applied last as the hard safety boundary regardless of what
        // the LLM asked for or affinity scaling produced.
        $arousalMin = (float)($settings['arousal_delta_min'] ?? -25);
        $arousalMax = (float)($settings['arousal_delta_max'] ?? 25);
        $deltaArousal = max($arousalMin, min($arousalMax, $deltaArousal));
        $affinityDeltaMin = (float)($settings['affinity_delta_min'] ?? -25);
        $affinityDeltaMax = (float)($settings['affinity_delta_max'] ?? 25);
        $deltaAffinity = max($affinityDeltaMin, min($affinityDeltaMax, $deltaAffinity));

        // Update server-side affinity for context injection.
        $entry['affinity'] = $currentAffinity + $deltaAffinity;
        $minAffinity = (float)($settings['affinity_clamp_min'] ?? -100);
        $maxAffinity = (float)($settings['affinity_clamp_max'] ?? 100);
        $entry['affinity'] = max($minAffinity, min($maxAffinity, $entry['affinity']));
        $entry['last_tag'] = $reactionTag;
        $entry['reason'] = $reason;
        $entry['updated_at'] = date('Y-m-d H:i:s');

        chim_osl_save_state($OSL_STATE_FILE, $state);

        if ($deltaArousal == 0.0 && $deltaAffinity == 0.0) {
            error_log("[CHIM-OSLAroused] $actor: both deltas zeroed out by settings; not sending a rolecommand.");
            continue;
        }

        // Build the rolecommand payload that the bridge quest script expects.
        $payload = implode("|", [
            $actor,
            $deltaArousal,
            $deltaAffinity,
            $reactionTag,
            $reason,
        ]);
        $actions[$n] = "$actionParts[0]|rolecommand|CHIM_ApplyDynamics@$payload";

        error_log("[CHIM-OSLAroused] Transformed EvaluateDynamics -> rolecommand|CHIM_ApplyDynamics for $actor (arousal $deltaArousal, affinity $deltaAffinity, tag $reactionTag)");
    }

    return $actions;
};
