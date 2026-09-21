# CHIM - OSLAroused Bridge

**Lets CHIM's LLM move an NPC's real arousal, and lets that arousal shape how they talk to you.**

During conversation, CHIM can adjust an NPC's native **OSLAroused** arousal and this bridge's own
tracked **affinity**. When arousal runs far enough ahead of affinity, a configurable **Lust Override**
prompt is injected — an NPC who wants you more than they like you speaks differently from one who
feels both.

Written against CommonLibSSE-NG. Works standalone: **no dependency on SHARMAT or MinAI**, with an
opt-in compatibility mode for people who run them.

---

## How it actually works

Three components, and none of them is optional.

**The SKSE plugin** (`CHIM_OSLAroused_Bridge.dll`) tails HerikaServer's `output_to_plugin.log`,
parses `CHIM_ApplyDynamics@...` events and writes the arousal delta straight to native OSLAroused
through the Papyrus VM. **This is the only thing in the chain that moves the real arousal value.**

**The quest script** (`CHIM_OSLAroused_Bridge.psc`) reports back. Every 0.25 game-hours its
`OnUpdate` loop broadcasts each nearby actor's *actual* OSLAroused arousal to the server as
`oslaroused_state@Actor@arousal`, which keeps the stats and the Lust Override prompt honest rather
than guessing from what was last written.

> The same script also registers for the `CHIM_CommandReceived` mod event intending to apply
> dynamics itself — but AIAgent only ever broadcasts that event for its own `ExtCmd*` commands,
> never for `CHIM_ApplyDynamics`, so **that half never fires**. It is left in place deliberately and
> documented here so nobody spends an afternoon working out why it appears to do nothing.

**The HerikaServer extension** (`plugin/ext/oslaroused_bridge/`) registers the
`EvaluateEmotionalDynamics` action the LLM calls, enforces the WebUI's toggles, clamps and
cooldowns, and injects the stats and Lust Override context.

In short: **the plugin writes, the quest reports, the server decides.**

---

## Repository layout

```
├── src/  plugin.cpp  PCH.h  CMakeLists.txt   C++ source for the SKSE plugin
├── papyrus/                                  quest script source and compiled .pex
├── esp/                                      the Start-Game-Enabled quest plugin
├── plugin/ext/oslaroused_bridge/             HerikaServer extension
│   ├── functions.php          registers EvaluateEmotionalDynamics, applies toggles/clamps/cooldown
│   ├── preprocessing.php      ingests the quest's oslaroused_state@ broadcasts
│   ├── context_pre.php        injects stats and the Lust Override prompt
│   ├── json_response_custom.php   registers the action's fields with CHIM's structured-output
│   │                              schema (auto-loaded by CHIM core - must ship even though
│   │                              nothing here calls it directly)
│   ├── config.php             the WebUI
│   └── settings.json / manifest.json
├── tools/xedit/                              generates the quest ESP from scratch (one-off)
└── install.sh  compile.sh  verify.sh         build and deploy helpers, WSL-side
```

The server extension here is the copy that ships with the mod, kept in sync with what is installed.
**If you edit the live server copy directly, copy your changes back here before packaging a
release** — `install.sh` copies one way only.

---

## Requirements

| | |
| --- | --- |
| **SKSE64** | Required |
| **CHIM / HerikaServer** | Required |
| **OSLAroused** | Required — the arousal values are its own |
| SHARMAT (AIagentNSFW) | *Optional.* Only for the compatibility mode below |

---

## Install

**1. Deploy the HerikaServer extension** (from WSL):

```sh
bash "/mnt/<drive>/<path to this repo>/install.sh"
```

**2. Install the mod** in Mod Organizer 2 or Vortex, and enable the ESP **after** `AIAgent.esp` and
`OSLAroused.esp` in your load order.

**3. Start Skyrim.** The quest auto-starts, and the plugin logs to
`Documents/My Games/Skyrim Special Edition/SKSE/CHIM_OSLAroused_Bridge.log`.

The ESP and the compiled `.pex` are prebuilt — you only need `compile.sh` if you change the `.psc`.

---

## Building the SKSE plugin

Requires CMake, Ninja and a C++23 MSVC toolchain; dependencies come from vcpkg.

```sh
cmake --preset release
cmake --build build/release
```

Set `SKYRIM_MODS_FOLDER` (or `SKYRIM_FOLDER`) and the build drops the DLL straight into the mod's
`SKSE/Plugins/`, so the usual loop is *build, alt-tab, reload a save*.

---

## Configuration

`http://localhost:8081/HerikaServer/ext/oslaroused_bridge/config.php`

- **Dynamics Control** — stats injection, Lust Override threshold, per-dimension enable, clamps and
  affinity scaling
- **Cooldowns & Notifications** — per-actor gain cooldown, in-game notification and scene-skip
  toggles
- **Sharmat Compatibility** — lets an NPC's tracked arousal bypass SHARMAT's affinity and
  relationship-type gate for that NPC specifically. **Off by default**, and requires SHARMAT
  AIagentNSFW; without it this section does nothing at all

---

## Verification

```sh
bash   "<path to this repo>/verify.sh"
```

The plugin's log is deliberately talkative: it records every parsed event, the delta applied and the
actor it landed on. If an adjustment does not appear to happen, the log says why rather than failing
silently.

---

## Notes for contributors

**`state.json` is owned by the PHP extension.** The SKSE plugin reads it once at boot to seed its
in-memory cache and never writes it — writing from the game side would clobber the server's data.

**Payloads are pipe-delimited, not JSON:**
`CHIM_ApplyDynamics@Actor|DeltaArousal|DeltaAffinity|Tag|Reason`

**Logs written from WSL carry CRLF line endings.** Anything parsing them must trim `\r` as well as
`\n`.

---

## License

See [LICENSE](LICENSE). The scaffolding began as
[HelloWorld-using-CommonLibSSE-NG](https://github.com/SkyrimDev/HelloWorld-using-CommonLibSSE-NG);
the vendored DevBench API in `src/DevBench/` carries its own licence notice.
