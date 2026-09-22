# Changelog

## 1.0.1

Documented SLO Aroused NG compatibility. The plugin already worked with it — it dispatches by
Papyrus script name (`OSLArousedNative`), not by ESP, and SLOA ships a compatibility stub under
that same name. But that stub doesn't implement the scene-check function `skip_during_scene_enabled`
depends on, so leaving that setting on with SLOA installed silently stopped every arousal write.
The setting's tooltip and the README now say so directly. No code changed.

Thanks to Sarpu on Discord for catching this.

## 1.0.0

Initial release. SKSE plugin tails `output_to_plugin.log` for `CHIM_ApplyDynamics` events and
writes clamped arousal deltas straight to native OSLAroused; in-game notifications; per-actor
gain cooldown; independent arousal/affinity toggles with per-event delta clamps; affinity-scaled
arousal; Lust Override prompt injection; optional SHARMAT compatibility (arousal-driven per-NPC
bypass of its affinity/relationship-type gate, off by default); scene-awareness to avoid
double-dipping with SexLab/OStim's own arousal changes; full WebUI config.
