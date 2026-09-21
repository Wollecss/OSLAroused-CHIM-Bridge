<?php
/**
 * CHIM - OSLAroused Bridge context injection hook.
 *
 * Runs before the system prompt is frozen and injects:
 *  - The current speaker's arousal/affinity stats (optional).
 *  - A "Lust Override" prompt when arousal exceeds affinity by the threshold.
 *  - A reminder to call EvaluateEmotionalDynamics for charged dialogue.
 */

$stateFile = __DIR__ . '/state.json';
$settingsFile = __DIR__ . '/settings.json';

$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
if (!($settings['enabled'] ?? true)) {
    error_log("[CHIM-OSLAroused] context_pre: bridge disabled");
    return;
}

$npcName = $GLOBALS["HERIKA_NAME"] ?? null;
if (!$npcName || strcasecmp($npcName, 'The Narrator') === 0) {
    error_log("[CHIM-OSLAroused] context_pre: narrator or no NPC name");
    return;
}

$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

$dynamics = $state['dynamics'][$npcName] ?? null;
if (!is_array($dynamics)) {
    $dynamics = null;
}

$arousal = (float)($dynamics['arousal'] ?? 0);
$affinity = (float)($dynamics['affinity'] ?? ($settings['affinity_default'] ?? 0));
$tag = trim($dynamics['last_tag'] ?? '');

$injections = [];

if (!empty($settings['inject_stats']) && $dynamics !== null) {
    $template = $settings['stats_template']
        ?? "{npc_name}'s current arousal is {arousal} and affinity is {affinity}.";
    $injections[] = str_replace(
        ['{npc_name}', '{arousal}', '{affinity}', '{tag}'],
        [$npcName, $arousal, $affinity, $tag],
        $template
    );
}

$threshold = (float)($settings['lust_override_threshold'] ?? 20);
$minArousal = (float)($settings['min_arousal_for_override'] ?? 50);
if (!empty($settings['lust_override_enabled']) && $dynamics !== null && $arousal > ($affinity + $threshold) && $arousal >= $minArousal) {
    $template = $settings['lust_override_prompt']
        ?? "Lust Override: {npc_name}'s arousal ({arousal}) clearly exceeds their affinity/comfort ({affinity}). "
         . "Let this visibly affect the reply: they are distracted, flushed, and may act on attraction unless the player changes tone.";
    $injections[] = str_replace(
        ['{npc_name}', '{arousal}', '{affinity}', '{tag}'],
        [$npcName, $arousal, $affinity, $tag],
        $template
    );
}

// Reminder to actually use EvaluateEmotionalDynamics for charged dialogue.
$injections[] = "{{ABSOLUTE PRIORITY}} Whenever the player says or does anything that would change your physical arousal or emotional affinity toward them "
              . "(flirtation, a compliment, an insult, intimacy, rejection, suggestive dialogue, touching, kissing, or sexual remarks), "
              . "you MUST call the EvaluateEmotionalDynamics action FIRST. "
              . "Do not reply with only dialogue. Do not use ComeCloser, HoldHands, or another physical action instead. "
              . "EvaluateEmotionalDynamics is mandatory for charged moments. After calling it, you may also speak or choose another action.";

if (!empty($injections)) {
    $block = implode("

", $injections);
    if (!empty($GLOBALS["COMMAND_PROMPT"])) {
        $GLOBALS["COMMAND_PROMPT"] .= "

" . $block;
    } else {
        $GLOBALS["COMMAND_PROMPT"] = $block;
    }
    error_log("[CHIM-OSLAroused] context_pre injected " . count($injections) . " block(s) for $npcName");
}
