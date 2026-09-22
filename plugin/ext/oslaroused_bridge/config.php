<?php
$settingsFile = __DIR__ . '/settings.json';
$stateFile = __DIR__ . '/state.json';

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'enabled' => isset($_POST['enabled']),
        'inject_stats' => isset($_POST['inject_stats']),
        'stats_template' => trim($_POST['stats_template'] ?? "{npc_name}'s current arousal is {arousal} and affinity is {affinity}."),
        'lust_override_enabled' => isset($_POST['lust_override_enabled']),
        'lust_override_threshold' => (float)($_POST['lust_override_threshold'] ?? 20),
        'min_arousal_for_override' => (float)($_POST['min_arousal_for_override'] ?? 50),
        'lust_override_prompt' => trim($_POST['lust_override_prompt'] ?? ''),
        'affinity_default' => (float)($_POST['affinity_default'] ?? 0),
        'affinity_clamp_min' => (float)($_POST['affinity_clamp_min'] ?? -100),
        'affinity_clamp_max' => (float)($_POST['affinity_clamp_max'] ?? 100),
        'sharmat_override_enabled' => isset($_POST['sharmat_override_enabled']),
        'sharmat_override_threshold' => (float)($_POST['sharmat_override_threshold'] ?? 75),
        'arousal_enabled' => isset($_POST['arousal_enabled']),
        'affinity_enabled' => isset($_POST['affinity_enabled']),
        'arousal_delta_min' => (float)($_POST['arousal_delta_min'] ?? -25),
        'arousal_delta_max' => (float)($_POST['arousal_delta_max'] ?? 25),
        'affinity_delta_min' => (float)($_POST['affinity_delta_min'] ?? -25),
        'affinity_delta_max' => (float)($_POST['affinity_delta_max'] ?? 25),
        'affinity_scaling_enabled' => isset($_POST['affinity_scaling_enabled']),
        'gain_cooldown_enabled' => isset($_POST['gain_cooldown_enabled']),
        'gain_cooldown_seconds' => (float)($_POST['gain_cooldown_seconds'] ?? 30),
        'notifications_enabled' => isset($_POST['notifications_enabled']),
        'skip_during_scene_enabled' => isset($_POST['skip_during_scene_enabled']),
    ];
    file_put_contents($settingsFile, json_encode($newSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $saved = true;
}

$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$defaults = [
    'enabled' => true,
    'inject_stats' => true,
    'stats_template' => "{npc_name}'s current arousal is {arousal} and affinity is {affinity}.",
    'lust_override_enabled' => true,
    'lust_override_threshold' => 20,
    'min_arousal_for_override' => 50,
    'lust_override_prompt' => "Lust Override: {npc_name}'s arousal ({arousal}) clearly exceeds their affinity/comfort ({affinity}). Let this visibly affect the reply: they are distracted, flushed, and may act on attraction unless the player changes tone.",
    'affinity_default' => 0,
    'affinity_clamp_min' => -100,
    'affinity_clamp_max' => 100,
    'sharmat_override_enabled' => false,
    'sharmat_override_threshold' => 75,
    'arousal_enabled' => true,
    'affinity_enabled' => true,
    'arousal_delta_min' => -25,
    'arousal_delta_max' => 25,
    'affinity_delta_min' => -25,
    'affinity_delta_max' => 25,
    'affinity_scaling_enabled' => false,
    'gain_cooldown_enabled' => true,
    'gain_cooldown_seconds' => 30,
    'notifications_enabled' => true,
    'skip_during_scene_enabled' => true,
];
$settings = array_merge($defaults, is_array($settings) ? $settings : []);
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>CHIM - OSLAroused Bridge Config</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#16161a;color:#fffffe;padding:24px;margin:0}
.container{max-width:820px;margin:0 auto;background:#242629;border-radius:8px;padding:32px}
.page-header{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:8px}
h1{color:#7f5af0;margin-top:0;margin-bottom:0}
.header-art{height:280px;width:auto;display:block}
h3{color:#94a1b2;font-size:14px;text-transform:uppercase;margin-top:28px}
.alert{background:#2cb67d;color:#16161a;padding:12px;border-radius:4px;margin-bottom:20px;font-weight:bold}
.form-group{margin-bottom:18px}
label{display:block;font-weight:600;margin-bottom:8px}
.checkbox-label{display:inline-flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer}
input[type="text"],input[type="number"],textarea{width:100%;padding:10px;border-radius:4px;border:1px solid #72757e;background:#16161a;color:#fffffe;box-sizing:border-box}
textarea{resize:vertical;min-height:80px;font-family:inherit}
button{background:#7f5af0;color:#fff;border:none;padding:12px 24px;font-size:16px;font-weight:bold;border-radius:4px;cursor:pointer}
button:hover{background:#6b46c1}
.hint{font-size:13px;color:#94a1b2;margin-top:6px}
.status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
.status-card{background:#16161a;padding:12px;border-radius:6px}
.status-name{font-weight:bold;color:#7f5af0}
.status-val{font-size:13px;color:#94a1b2}
details.section{background:#16161a;border:1px solid #3a3d45;border-radius:6px;margin-bottom:18px}
details.section summary{cursor:pointer;padding:14px 16px;font-weight:bold;color:#7f5af0;list-style:none;user-select:none}
details.section summary::-webkit-details-marker{display:none}
details.section summary::before{content:'\25B8\00a0';color:#94a1b2}
details.section[open] summary::before{content:'\25BE\00a0'}
details.section .section-body{padding:0 16px 16px}
.tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#3a3d45;color:#94a1b2;font-size:11px;font-weight:bold;margin-left:6px;cursor:help;vertical-align:middle}
.tip:hover{background:#7f5af0;color:#fff}
</style></head>
<body>
<div class="container">
<div class="page-header">
<h1>CHIM - OSLAroused Bridge</h1>
<img class="header-art" src="header.png" alt="">
</div>
<?php if ($saved): ?><div class="alert">Settings saved.</div><?php endif; ?>

<h3>Live Dynamics State</h3>
<div class="status-grid">
<?php foreach (($state['dynamics'] ?? []) as $name => $data): ?>
<div class="status-card">
<div class="status-name"><?= htmlspecialchars($name) ?></div>
<div class="status-val">Arousal: <?= (float)($data['arousal'] ?? 0) ?></div>
<div class="status-val">Affinity: <?= (float)($data['affinity'] ?? 0) ?></div>
<div class="status-val">Tag: <?= htmlspecialchars($data['last_tag'] ?? '') ?></div>
</div>
<?php endforeach; ?>
<?php if (empty($state['dynamics'])): ?>
<div class="status-card"><div class="status-val">No state received yet.</div></div>
<?php endif; ?>
</div>

<form method="POST">
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="enabled" <?= !empty($settings['enabled']) ? 'checked' : '' ?>> Enable bridge context injection<span class="tip" title="Master switch for this extension's prompt injection (stats line + Lust Override). When off, EvaluateDynamics still runs and still moves the game's arousal/affinity - nothing about it is described to the LLM.">?</span></label></div>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="inject_stats" <?= !empty($settings['inject_stats']) ? 'checked' : '' ?>> Inject arousal/affinity stats into prompt<span class="tip" title="Adds a line like &quot;X's current arousal is N and affinity is M&quot; to the prompt using the template below, so the LLM knows the current numbers before deciding what to do next.">?</span></label></div>
<div class="form-group"><label for="stats_template">Stats Template</label><textarea name="stats_template" id="stats_template"><?= htmlspecialchars($settings['stats_template']) ?></textarea><div class="hint">Placeholders: {npc_name}, {arousal}, {affinity}, {tag}</div></div>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="lust_override_enabled" <?= !empty($settings['lust_override_enabled']) ? 'checked' : '' ?>> Enable Lust Override prompt<span class="tip" title="When an NPC's arousal exceeds their affinity by more than the threshold below (and arousal is at least the minimum below), injects a prompt telling the LLM to let that visibly affect the NPC's behavior.">?</span></label></div>
<div class="form-group"><label for="lust_override_threshold">Arousal - Affinity Threshold<span class="tip" title="How far arousal must exceed affinity before the Lust Override prompt fires. Example: threshold 20 means an NPC at 60 arousal / 30 affinity (a gap of 30) qualifies, but 60/50 (a gap of 10) does not.">?</span></label><input type="number" name="lust_override_threshold" id="lust_override_threshold" value="<?= (float)$settings['lust_override_threshold'] ?>"></div>
<div class="form-group"><label for="min_arousal_for_override">Minimum Arousal for Override<span class="tip" title="Floor on arousal itself, separate from the gap above. Stops the override firing for a barely-aroused NPC who simply has very low affinity.">?</span></label><input type="number" name="min_arousal_for_override" id="min_arousal_for_override" value="<?= (float)$settings['min_arousal_for_override'] ?>"></div>
<div class="form-group"><label for="lust_override_prompt">Lust Override Prompt</label><textarea name="lust_override_prompt" id="lust_override_prompt"><?= htmlspecialchars($settings['lust_override_prompt']) ?></textarea><div class="hint">Placeholders: {npc_name}, {arousal}, {affinity}, {tag}</div></div>
<div class="form-group"><label for="affinity_default">Default Affinity<span class="tip" title="Starting affinity for any actor this bridge hasn't tracked before. Only applies the first time; after that, each actor keeps their own value.">?</span></label><input type="number" name="affinity_default" id="affinity_default" value="<?= (float)$settings['affinity_default'] ?>"></div>
<div class="form-group"><label>Affinity Clamp Range<span class="tip" title="Hard floor and ceiling on an actor's total stored affinity. Different from the per-event Affinity Delta Range in Dynamics Control below, which limits how much a single event can move it.">?</span></label><input type="number" name="affinity_clamp_min" value="<?= (float)$settings['affinity_clamp_min'] ?>" style="width:48%;display:inline-block;"><input type="number" name="affinity_clamp_max" value="<?= (float)$settings['affinity_clamp_max'] ?>" style="width:48%;display:inline-block;float:right;"></div>

<details class="section" open>
<summary>Dynamics Control</summary>
<div class="section-body">
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="arousal_enabled" <?= !empty($settings['arousal_enabled']) ? 'checked' : '' ?>> Let the LLM adjust arousal</label></div>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="affinity_enabled" <?= !empty($settings['affinity_enabled']) ? 'checked' : '' ?>> Let the LLM adjust affinity</label></div>
<p class="hint">Turning either off doesn't stop the LLM from being asked - it just zeroes that half of its response before anything reaches the game or the stats file. The other dimension is unaffected.</p>
<div class="form-group"><label>Arousal Delta Range (per event)</label><input type="number" name="arousal_delta_min" value="<?= (float)$settings['arousal_delta_min'] ?>" style="width:48%;display:inline-block;"><input type="number" name="arousal_delta_max" value="<?= (float)$settings['arousal_delta_max'] ?>" style="width:48%;display:inline-block;float:right;"></div>
<div class="form-group"><label>Affinity Delta Range (per event)</label><input type="number" name="affinity_delta_min" value="<?= (float)$settings['affinity_delta_min'] ?>" style="width:48%;display:inline-block;"><input type="number" name="affinity_delta_max" value="<?= (float)$settings['affinity_delta_max'] ?>" style="width:48%;display:inline-block;float:right;"></div>
<p class="hint">Hard clamp on how much a single EvaluateDynamics call can move either stat, regardless of what the LLM asked for. Distinct from the Affinity Clamp Range above, which bounds the total stored value, not the per-event change.</p>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="affinity_scaling_enabled" <?= !empty($settings['affinity_scaling_enabled']) ? 'checked' : '' ?>> Scale arousal gain by current affinity</label></div>
<p class="hint">An NPC who likes you responds more; one who doesn't, less. 0.5x at -100 affinity, 1.0x at 0, 1.5x at +100 - scales the LLM's own arousal delta rather than replacing it.</p>
</div>
</details>

<details class="section" open>
<summary>Cooldowns &amp; Notifications</summary>
<div class="section-body">
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="gain_cooldown_enabled" <?= !empty($settings['gain_cooldown_enabled']) ? 'checked' : '' ?>> Enable per-actor gain cooldown</label></div>
<div class="form-group"><label for="gain_cooldown_seconds">Cooldown (seconds)</label><input type="number" name="gain_cooldown_seconds" id="gain_cooldown_seconds" value="<?= (float)$settings['gain_cooldown_seconds'] ?>"></div>
<p class="hint">While an actor is inside their own cooldown window, further EvaluateDynamics calls for them are dropped entirely - no state change, no notification, no arousal write. Keeps a rapid back-and-forth from maxing someone out in a handful of turns. Set to 0 to disable.</p>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="notifications_enabled" <?= !empty($settings['notifications_enabled']) ? 'checked' : '' ?>> Show in-game notifications</label></div>
<p class="hint">Read once by the SKSE plugin at game load. Toggling this here takes effect on your next Skyrim launch, not immediately.</p>
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="skip_during_scene_enabled" <?= !empty($settings['skip_during_scene_enabled']) ? 'checked' : '' ?>> Skip arousal writes while the actor is in an OStim/SexLab scene</label></div>
<p class="hint">Avoids double-dipping with SexLab/OStim's own arousal changes for the same scene. Also read once at game load.
<strong style="color:#e8c547">Using SLO Aroused NG instead of OSLAroused?</strong> Its compatibility stub doesn't implement the scene-check function this setting relies on, so leave this <strong>unchecked</strong> - otherwise arousal writes will silently stop being applied while this is on. (Real OSLAroused users: leave it checked as normal.)</p>
</div>
</details>

<details class="section">
<summary>Sharmat Compatibility</summary>
<div class="section-body">
<div class="form-group"><label class="checkbox-label"><input type="checkbox" name="sharmat_override_enabled" <?= !empty($settings['sharmat_override_enabled']) ? 'checked' : '' ?>> Let high arousal override SHARMAT's affinity/relationship-type gating</label></div>
<p class="hint">When an NPC's arousal (as tracked by this bridge) reaches the threshold below, that NPC gets SHARMAT's own "Open Mode" bypass applied just to them - no relationship-type check, no affinity floor - so a high-arousal Enemy, Rival, or Client can still progress ("enemy to lovers," a client's crush on a shopkeeper) without changing SHARMAT's global settings for everyone else. Off by default; has no effect at all if SHARMAT AIagentNSFW isn't installed. Requires a small, clearly-marked one-line edit to SHARMAT's functions.php (see plugin README) - if you update SHARMAT, re-check that the edit is still there.</p>
<div class="form-group"><label for="sharmat_override_threshold">Arousal Threshold<span class="tip" title="This actor's bridge-tracked arousal (0-100) must reach this value before SHARMAT's affinity floor and relationship-type gate are bypassed for them specifically.">?</span></label><input type="number" name="sharmat_override_threshold" id="sharmat_override_threshold" value="<?= (float)$settings['sharmat_override_threshold'] ?>"></div>
</div>
</details>

<button type="submit">Save Configuration</button>
</form>
</div>
</body>
</html>
