#include "RE/Skyrim.h"
#include "SKSE/API.h"
#include "src/BridgeAPI.h"
#include "src/DevBench/DevBenchAPI.h"
#include <nlohmann/json.hpp>
#include <spdlog/sinks/basic_file_sink.h>
#include <atomic>
#include <chrono>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <mutex>
#include <sstream>
#include <string>
#include <string_view>
#include <thread>
#include <unordered_map>
#include <vector>

namespace
{
	void InitializeLogging()
	{
		auto path = SKSE::log::log_directory();
		if (!path) {
			return;
		}
		*path /= "HelloWorld.log";

		// Keep one previous session around. Truncating on boot destroys exactly the log you
		// need after a crash, since the next launch wipes it before you can read it.
		std::error_code ec;
		auto previous = *path;
		previous.replace_extension(".prev.log");
		std::filesystem::rename(*path, previous, ec);

		auto sink = std::make_shared<spdlog::sinks::basic_file_sink_mt>(path->string(), true);
		auto log = std::make_shared<spdlog::logger>("Global", std::move(sink));
		log->set_level(spdlog::level::info);
		log->flush_on(spdlog::level::info);
		spdlog::set_default_logger(std::move(log));
		// Millisecond precision matters here: correlating our own writes against OSLAroused's
		// internal recompute needs sub-second ordering to tell which one moved a value.
		spdlog::set_pattern("[%H:%M:%S.%e] [%l] %v");
	}
}

namespace
{
	namespace fs = std::filesystem;
	using json = nlohmann::json;

	constexpr auto STATE_FILE_PATH = R"(\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\ext\oslaroused_bridge\state.json)";
	constexpr auto SETTINGS_FILE_PATH = R"(\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\ext\oslaroused_bridge\settings.json)";

	// Read once at boot, same as state.json - the WebUI's PHP-side toggles apply to the next
	// launch, not live. Only the two settings that need native engine access live here; the
	// rest (deltas, cooldown, affinity scaling) are enforced entirely server-side in PHP.
	std::atomic<bool> g_notificationsEnabled{ true };
	std::atomic<bool> g_skipDuringScene{ true };

	struct ActorState
	{
		std::string name;
		float arousal = 0.0f;
		float affinity = 0.0f;
		std::string last_tag;
		std::string last_reason;
		std::string updated_at;
	};

	std::mutex g_stateMutex;
	std::unordered_map<std::string, ActorState> g_actors;
	std::atomic<bool> g_running{ true };

	// Set once a save is actually in the world and cleared on unload. Engine reads (actor
	// lists, names, the Papyrus VM) are meaningless and unsafe at the main menu, and
	// kDataLoaded fires there - so nothing may touch the game until this is true.
	std::atomic<bool> g_gameReady{ false };

	std::string CurrentTimestamp()
	{
		const auto now = std::chrono::system_clock::now();
		const auto time = std::chrono::system_clock::to_time_t(now);
		std::tm tm{};
		localtime_s(&tm, &time);
		std::ostringstream oss;
		oss << std::put_time(&tm, "%Y-%m-%dT%H:%M:%S");
		return oss.str();
	}

	constexpr auto PLUGIN_LOG_PATH = R"(\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\output_to_plugin.log)";
	std::atomic<std::streamoff> g_pluginLogPos{ 0 };

	std::string TrimCr(std::string a_str)
	{
		while (!a_str.empty() && (a_str.back() == '\r' || a_str.back() == '\n')) {
			a_str.pop_back();
		}
		return a_str;
	}

	std::vector<std::string> SplitPipe(const std::string& a_str)
	{
		std::vector<std::string> parts;
		std::stringstream ss(a_str);
		std::string part;
		while (std::getline(ss, part, '|')) {
			parts.push_back(part);
		}
		return parts;
	}

	std::string FormatNumber(float a_value)
	{
		std::ostringstream oss;
		if (a_value == static_cast<float>(static_cast<long long>(a_value))) {
			oss << static_cast<long long>(a_value);
		} else {
			oss << std::fixed << std::setprecision(1) << a_value;
		}
		return oss.str();
	}

	// Resolves a CHIM actor name to a live RE::Actor*, matching the player or any
	// currently high-process (nearby/active) actor by display name.
	RE::Actor* FindActorByName(const std::string& a_name)
	{
		if (a_name.empty()) {
			return nullptr;
		}

		if (auto* player = RE::PlayerCharacter::GetSingleton(); player) {
			if (const char* displayName = player->GetDisplayFullName(); displayName && a_name == displayName) {
				return player;
			}
		}

		auto* processLists = RE::ProcessLists::GetSingleton();
		if (!processLists) {
			return nullptr;
		}

		for (auto& handle : processLists->highActorHandles) {
			auto actorPtr = handle.get();
			if (!actorPtr) {
				continue;
			}
			if (const char* displayName = actorPtr->GetDisplayFullName(); displayName && a_name == displayName) {
				return actorPtr.get();
			}
		}

		return nullptr;
	}

	// OSLAroused's own "Arousal Rate of Change" MCM setting throttles ModifyArousal to a
	// slow hourly cap regardless of the requested delta or how many calls land in that
	// window, which made CHIM events barely move the needle. Reading the current value and
	// calling SetArousal with a clamped target bypasses that throttle for a predictable,
	// per-event bump instead.
	constexpr float kMaxArousalDeltaPerEvent = 15.0f;

	// Defined with the arousal-authority state further down.
	void NoteArousalWrite(const std::string& a_actorName, float a_value);

	std::string BuildDynamicsNotification(const std::string& a_actor, float a_deltaArousal,
		float a_currentArousal, float a_deltaAffinity, const std::string& a_tag)
	{
		std::ostringstream notification;
		notification << a_actor << ":";
		bool wroteClause = false;
		if (a_deltaArousal != 0.0f) {
			notification << " " << (a_deltaArousal > 0.0f ? "+" : "") << FormatNumber(a_deltaArousal)
						 << " Arousal [" << FormatNumber(a_currentArousal) << "/100]";
			wroteClause = true;
		}
		if (a_deltaAffinity != 0.0f) {
			notification << " (" << (a_deltaAffinity > 0.0f ? "+" : "") << FormatNumber(a_deltaAffinity) << " Affinity)";
			wroteClause = true;
		}
		notification << " \xE2\x80\x94 [" << a_tag << "]";
		return notification.str();
	}

	void DispatchNotification(std::string a_message)
	{
		if (!g_notificationsEnabled.load()) {
			return;
		}
		if (const auto* task = SKSE::GetTaskInterface(); task) {
			task->AddTask([msg = std::move(a_message)]() {
				RE::DebugNotification(msg.c_str());
			});
		}
	}

	// Captures the async GetArousalNoSideEffects result, then dispatches the clamped
	// SetArousal call. Runs on the VM's own thread, same as the initial dispatch.
	class ApplyClampedArousalCallback : public RE::BSScript::IStackCallbackFunctor
	{
	public:
		ApplyClampedArousalCallback(RE::Actor* a_actor, std::string a_actorName, float a_delta,
			float a_deltaAffinity, std::string a_tag) :
			_actor(a_actor), _actorName(std::move(a_actorName)), _tag(std::move(a_tag)),
			_delta(a_delta), _deltaAffinity(a_deltaAffinity)
		{}

		void operator()(RE::BSScript::Variable a_result) override
		{
			// If the read didn't come back as a real float (VM congestion, the actor briefly
			// going invalid, etc.), do NOT fall back to treating current arousal as 0 - that
			// would wipe out whatever it actually was down to just the delta on every hiccup.
			// Skip this event instead; the next one will read a fresh, valid value.
			if (!a_result.IsFloat()) {
				SKSE::log::warn("[OSLAroused] GetArousalNoSideEffects returned a non-float result; skipping this delta rather than resetting arousal.");
				return;
			}

			const float current = a_result.GetFloat();
			const float clampedDelta = std::clamp(_delta, -kMaxArousalDeltaPerEvent, kMaxArousalDeltaPerEvent);
			float target = std::clamp(current + clampedDelta, 0.0f, 100.0f);

			// _actorName was captured on the main thread at dispatch; this runs on the VM's
			// thread, where reading the actor's name out of the engine is not safe.
			SKSE::log::info("[OSLAroused] SetArousal {} read={:.2f} delta={:+.2f} -> target={:.2f}",
				_actorName, current, clampedDelta, target);

			// This is now the value we consider authoritative for this actor.
			NoteArousalWrite(_actorName, target);

			// Notify from here rather than at parse time: this is the only place the real
			// OSLAroused number is known, so the player sees the value the widget will show
			// and the delta we actually applied after clamping.
			DispatchNotification(BuildDynamicsNotification(_actorName, clampedDelta, target, _deltaAffinity, _tag));

			auto* vm = RE::BSScript::Internal::VirtualMachine::GetSingleton();
			if (!vm) {
				return;
			}
			auto callback = RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor>();
			auto* args = RE::MakeFunctionArguments(std::move(_actor), std::move(target));
			vm->DispatchStaticCall("OSLArousedNative", "SetArousal", args, callback);
		}

		bool CanSave() const override { return false; }
		void SetObject(const RE::BSTSmartPointer<RE::BSScript::Object>&) override {}

	private:
		RE::Actor* _actor;
		std::string _actorName;
		std::string _tag;
		float _delta;
		float _deltaAffinity;
	};

	// Kicks off the read-then-clamped-set chain. Split out so both the direct path and the
	// post-scene-check path (below) can reach it without duplicating the dispatch.
	void BeginArousalDeltaApplication(RE::Actor* a_actor, std::string a_actorName, float a_deltaArousal,
		float a_deltaAffinity, std::string a_tag)
	{
		auto* vm = RE::BSScript::Internal::VirtualMachine::GetSingleton();
		if (!vm) {
			return;
		}
		RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> callback =
			RE::make_smart<ApplyClampedArousalCallback>(a_actor, a_actorName, a_deltaArousal, a_deltaAffinity, a_tag);
		auto* args = RE::MakeFunctionArguments(std::move(a_actor));
		vm->DispatchStaticCall("OSLArousedNative", "GetArousalNoSideEffects", args, callback);
	}

	// Captures IsInScene's result, then either drops the event or continues into the normal
	// read-then-set chain. A scene's own framework (SexLab/OStim adapters) already drives
	// arousal hard for that actor, so applying our own delta on top would double-dip.
	class SceneCheckCallback : public RE::BSScript::IStackCallbackFunctor
	{
	public:
		SceneCheckCallback(RE::Actor* a_actor, std::string a_actorName, float a_deltaArousal,
			float a_deltaAffinity, std::string a_tag) :
			_actor(a_actor), _actorName(std::move(a_actorName)), _tag(std::move(a_tag)),
			_deltaArousal(a_deltaArousal), _deltaAffinity(a_deltaAffinity)
		{}

		void operator()(RE::BSScript::Variable a_result) override
		{
			if (a_result.IsBool() && a_result.GetBool()) {
				SKSE::log::info("[OSLAroused] {} is in an active scene; skipping this arousal delta.", _actorName);
				return;
			}
			BeginArousalDeltaApplication(_actor, std::move(_actorName), _deltaArousal, _deltaAffinity, std::move(_tag));
		}

		bool CanSave() const override { return false; }
		void SetObject(const RE::BSTSmartPointer<RE::BSScript::Object>&) override {}

	private:
		RE::Actor* _actor;
		std::string _actorName;
		std::string _tag;
		float _deltaArousal;
		float _deltaAffinity;
	};

	// Applies a CHIM arousal delta directly to native OSLAroused, since neither the
	// native AIAgent plugin nor the (currently dead) CHIM_OSLAroused_Bridge quest
	// ever forwards CHIM_ApplyDynamics as a CHIM_CommandReceived mod event.
	// Called from the log tailer's background thread, so the actual engine work is marshalled
	// onto the main thread - scanning ProcessLists and reading actor names off-thread races
	// with the game mutating those structures.
	void ApplyNativeArousalDelta(const std::string& a_actorName, float a_deltaArousal,
		float a_deltaAffinity, const std::string& a_tag)
	{
		if (a_deltaArousal == 0.0f || !g_gameReady.load()) {
			return;
		}

		const auto* task = SKSE::GetTaskInterface();
		if (!task) {
			return;
		}

		task->AddTask([actorName = a_actorName, a_deltaArousal, a_deltaAffinity, tag = a_tag]() {
			if (!g_gameReady.load()) {
				return;  // Game unloaded between queueing and running this task.
			}

			RE::Actor* actor = FindActorByName(actorName);
			if (!actor) {
				SKSE::log::warn("[OSLAroused] Could not find actor '{}' to apply native arousal delta.", actorName);
				return;
			}

			auto* vm = RE::BSScript::Internal::VirtualMachine::GetSingleton();
			if (!vm) {
				return;
			}

			if (g_skipDuringScene.load()) {
				RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> sceneCallback =
					RE::make_smart<SceneCheckCallback>(actor, actorName, a_deltaArousal, a_deltaAffinity, tag);
				auto* sceneArgs = RE::MakeFunctionArguments(std::move(actor));
				vm->DispatchStaticCall("OSLArousedNative", "IsInScene", sceneArgs, sceneCallback);
				return;
			}

			BeginArousalDeltaApplication(actor, actorName, a_deltaArousal, a_deltaAffinity, tag);
		});
	}

	// Arousal authority. Other CHIM add-ons (notably SHARMAT AIagentNSFW, via
	// ExtCmdSyncArousal) hard-SET OSLAroused arousal from their own server-side value and
	// never read back what's already there, so they flatten whatever this bridge applied.
	// When enabled, we restore our value after such a write. Turn this off to let other mods
	// win instead - or disable the sync at its source (SHARMAT's NSFW_OSLA_SYNC_ENABLED).
	// Off by default: SHARMAT exposes its own "Publish SHARMAT Arousal to OSL/OStim" switch in
	// the CHIM web UI, which stops the overwrite at its source. Prefer that. Turn this on only
	// to keep SHARMAT's sync (for its OStim tempo) while this bridge still owns the OSL value.
	constexpr bool kDefendArousalAuthority = false;

	// Only a drop this large is treated as an external hard-set. OSLAroused's own drift toward
	// an actor's baseline moves a point or two per tick and must be left alone.
	constexpr float kAuthorityCliffDrop = 10.0f;

	// An echo of our own write comes back through the same event; anything this close to what
	// we last wrote is us, not someone else.
	constexpr float kAuthorityEchoTolerance = 0.5f;

	// Hard ceiling on corrections per actor. Nothing observed should ever reach this, but a
	// bridge that can write in response to a write it caused must not be able to spin.
	constexpr auto kAuthorityMinInterval = std::chrono::seconds(2);

	std::mutex g_authorityMutex;
	struct AuthorityState
	{
		float expected = 0.0f;
		float lastWritten = 0.0f;
		std::chrono::steady_clock::time_point lastCorrection{};
	};
	std::unordered_map<std::string, AuthorityState> g_authority;

	void NoteArousalWrite(const std::string& a_actorName, float a_value)
	{
		std::lock_guard lock(g_authorityMutex);
		auto& state = g_authority[a_actorName];
		state.expected = a_value;
		state.lastWritten = a_value;
	}

	void ReassertArousal(const std::string& a_actorName, float a_value);

	// A drop this recent after a real orgasm is OSLAroused's own intended mechanic (SexLab/OStim
	// adapters apply their configured loss on scene end), not another mod stomping the value -
	// authority mode must not fight that. ~30 minutes of game time.
	constexpr float kOrgasmRespectWindowDays = 0.02f;

	// Captures GetDaysSinceLastOrgasm, then either respects a real post-orgasm drop or restores
	// our value. Split from DefendArousalAuthority because the check is itself an async VM call.
	class OrgasmCheckCallback : public RE::BSScript::IStackCallbackFunctor
	{
	public:
		OrgasmCheckCallback(std::string a_actorName, float a_restoreTo) :
			_actorName(std::move(a_actorName)), _restoreTo(a_restoreTo)
		{}

		void operator()(RE::BSScript::Variable a_result) override
		{
			if (a_result.IsFloat() && a_result.GetFloat() < kOrgasmRespectWindowDays) {
				SKSE::log::info("[Authority] {} orgasmed recently; respecting the drop instead of restoring.",
					_actorName);
				return;
			}
			SKSE::log::info("[Authority] {} was hard-set; restoring {:.2f}.", _actorName, _restoreTo);
			ReassertArousal(_actorName, _restoreTo);
		}

		bool CanSave() const override { return false; }
		void SetObject(const RE::BSTSmartPointer<RE::BSScript::Object>&) override {}

	private:
		std::string _actorName;
		float _restoreTo;
	};

	// Restores this bridge's value when another mod hard-sets an actor it tracks. Left alone:
	// actors we don't track, upward changes, and OSLAroused's own drift toward its baseline -
	// only a cliff-sized drop counts as someone overwriting us.
	void DefendArousalAuthority(const std::string& a_actorName, float a_observed, RE::Actor* a_actor)
	{
		{
			std::lock_guard lock(g_stateMutex);
			if (!g_actors.contains(a_actorName)) {
				return;
			}
		}

		float restoreTo = 0.0f;
		{
			std::lock_guard lock(g_authorityMutex);
			auto it = g_authority.find(a_actorName);
			if (it == g_authority.end()) {
				g_authority[a_actorName].expected = a_observed;  // First sighting: adopt it.
				return;
			}

			auto& state = it->second;
			if (std::abs(a_observed - state.lastWritten) <= kAuthorityEchoTolerance) {
				state.expected = a_observed;  // Our own write coming back.
				return;
			}

			if (a_observed >= state.expected - kAuthorityCliffDrop) {
				state.expected = a_observed;  // Drift, or someone raised it. Let it stand.
				return;
			}

			const auto now = std::chrono::steady_clock::now();
			if (now - state.lastCorrection < kAuthorityMinInterval) {
				state.expected = a_observed;  // Corrected too recently; yield rather than spin.
				return;
			}
			state.lastCorrection = now;
			restoreTo = state.expected;
		}

		auto* vm = RE::BSScript::Internal::VirtualMachine::GetSingleton();
		if (!vm || !a_actor) {
			ReassertArousal(a_actorName, restoreTo);  // Can't check; fall back to the old behavior.
			return;
		}
		RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> callback =
			RE::make_smart<OrgasmCheckCallback>(a_actorName, restoreTo);
		auto* args = RE::MakeFunctionArguments(std::move(a_actor));
		vm->DispatchStaticCall("OSLArousedNative", "GetDaysSinceLastOrgasm", args, callback);
	}

	// OSLAroused fires OSLA_ActorArousalUpdated from SetArousal for every change >= 1.0 - ours,
	// its own world-tick recompute, and any other mod's writes. That makes this the one place
	// that sees every change to an actor's arousal regardless of who caused it.
	class ArousalWatchSink : public RE::BSTEventSink<SKSE::ModCallbackEvent>
	{
	public:
		static ArousalWatchSink* GetSingleton()
		{
			static ArousalWatchSink singleton;
			return &singleton;
		}

		RE::BSEventNotifyControl ProcessEvent(const SKSE::ModCallbackEvent* a_event,
			RE::BSTEventSource<SKSE::ModCallbackEvent>*) override
		{
			if (!a_event || a_event->eventName != "OSLA_ActorArousalUpdated") {
				return RE::BSEventNotifyControl::kContinue;
			}

			RE::Actor* actor = a_event->sender ? a_event->sender->As<RE::Actor>() : nullptr;
			const char* name = actor ? actor->GetDisplayFullName() : nullptr;
			if (!name || !*name) {
				return RE::BSEventNotifyControl::kContinue;
			}

			const float observed = a_event->numArg;
			SKSE::log::info("[ArousalWatch] {} is now {:.2f}", name, observed);

			if constexpr (kDefendArousalAuthority) {
				if (g_gameReady.load()) {
					DefendArousalAuthority(name, observed, actor);
				}
			}
			return RE::BSEventNotifyControl::kContinue;
		}
	};

	// Restores a value another mod flattened. Marshalled to the main thread like every other
	// engine touch, and records the write so the resulting event is recognised as our echo.
	void ReassertArousal(const std::string& a_actorName, float a_value)
	{
		const auto* task = SKSE::GetTaskInterface();
		if (!task) {
			return;
		}

		task->AddTask([actorName = a_actorName, a_value]() {
			if (!g_gameReady.load()) {
				return;
			}
			RE::Actor* actor = FindActorByName(actorName);
			if (!actor) {
				return;
			}
			auto* vm = RE::BSScript::Internal::VirtualMachine::GetSingleton();
			if (!vm) {
				return;
			}

			NoteArousalWrite(actorName, a_value);

			float value = a_value;
			auto callback = RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor>();
			auto* args = RE::MakeFunctionArguments(std::move(actor), std::move(value));
			vm->DispatchStaticCall("OSLArousedNative", "SetArousal", args, callback);
		});
	}

	void StartArousalWatch()
	{
		auto* source = SKSE::GetModCallbackEventSource();
		if (!source) {
			SKSE::log::warn("[ArousalWatch] No mod callback event source; arousal changes will not be traced.");
			return;
		}
		source->AddEventSink(ArousalWatchSink::GetSingleton());
		SKSE::log::info("[ArousalWatch] Listening for OSLA_ActorArousalUpdated.");
	}

	void ProcessApplyDynamicsPayload(const std::string& a_payload)
	{
		auto parts = SplitPipe(a_payload);
		if (parts.size() < 4) {
			SKSE::log::warn("CHIM_ApplyDynamics payload has too few fields: {}", a_payload);
			return;
		}

		const auto& actor = parts[0];
		try {
			const float deltaArousal = std::stof(parts[1]);
			const float deltaAffinity = std::stof(parts[2]);
			const auto& tag = parts[3];

			std::string reason;
			for (size_t i = 4; i < parts.size(); ++i) {
				if (i > 4)
					reason += '|';
				reason += parts[i];
			}

			BridgeAPI::ApplyDynamics(actor, deltaArousal, deltaAffinity, tag, reason);
			SKSE::log::info("Applied CHIM_ApplyDynamics: actor={} arousal={} affinity={} tag={}", actor, deltaArousal, deltaAffinity, tag);

			// The arousal case notifies from inside the native write, where the real
			// OSLAroused value is known. An affinity-only event never gets there, so it
			// reports here instead - there's no arousal number to be wrong about.
			if (deltaArousal != 0.0f) {
				ApplyNativeArousalDelta(actor, deltaArousal, deltaAffinity, tag);
			} else if (deltaAffinity != 0.0f) {
				DispatchNotification(BuildDynamicsNotification(actor, 0.0f, 0.0f, deltaAffinity, tag));
			}
		} catch (const std::exception& ex) {
			SKSE::log::warn("CHIM_ApplyDynamics parse failed: {} | {}", a_payload, ex.what());
		}
	}

	void TailOutputToPluginLog()
	{
		const fs::path path(PLUGIN_LOG_PATH);
		std::error_code ec;
		const auto size = fs::file_size(path, ec);
		if (ec) {
			return;  // Log file does not exist yet.
		}

		std::streamoff lastPos = g_pluginLogPos.load();
		if (size < static_cast<std::uintmax_t>(lastPos)) {
			lastPos = 0;  // File was truncated/rotated.
		}

		if (static_cast<std::uintmax_t>(lastPos) == size) {
			return;  // No new data.
		}

		// At the main menu there's no world to apply anything to. Skip past whatever arrived
		// rather than queueing it, so it can't replay into the world on the next load.
		if (!g_gameReady.load()) {
			g_pluginLogPos.store(static_cast<std::streamoff>(size));
			return;
		}

		std::ifstream file(path, std::ios::in | std::ios::binary);
		if (!file.is_open()) {
			return;
		}

		file.seekg(lastPos, std::ios::beg);
		std::string line;
		while (std::getline(file, line)) {
			line = TrimCr(line);
			if (line.empty()) {
				continue;
			}

			// Real lines look like "<Actor>|rolecommand|CHIM_ApplyDynamics@<payload>", so the
			// marker is not necessarily at offset 0 - search for it anywhere in the line.
			constexpr std::string_view marker = "CHIM_ApplyDynamics@";
			if (const auto pos = line.find(marker); pos != std::string::npos) {
				std::string payload = line.substr(pos + marker.size());
				ProcessApplyDynamicsPayload(payload);
			}
		}

		// getline() leaves the stream at eof/failbit once it can't read another full line, and
		// tellg() on a failed stream returns -1 - NOT the real position. Storing that -1 would
		// get reinterpreted as a huge unsigned offset next poll (compared as uintmax_t below),
		// look like the file shrank, and reset lastPos to 0 - replaying the entire file forever.
		// We already know how far we actually read: up to `size`, the length fetched above.
		g_pluginLogPos.store(static_cast<std::streamoff>(size));
	}

	void StartPluginLogTailer()
	{
		std::thread([]() {
			SKSE::log::info("Plugin log tailer thread started.");

			// Skip existing history on the first reachable read, so we only process new events
			// from here on. This retries every poll instead of a one-shot attempt at thread start,
			// since the WSL network share may not be reachable yet at kDataLoaded - a transient
			// failure there used to leave g_pluginLogPos at 0, causing the same full-file replay
			// this comment is now next to a fix for.
			bool positionInitialized = false;
			while (g_running.load() && !positionInitialized) {
				std::error_code ec;
				const auto size = fs::file_size(fs::path(PLUGIN_LOG_PATH), ec);
				if (!ec) {
					g_pluginLogPos.store(static_cast<std::streamoff>(size));
					positionInitialized = true;
				} else {
					std::this_thread::sleep_for(std::chrono::milliseconds(250));
				}
			}

			while (g_running.load()) {
				TailOutputToPluginLog();
				std::this_thread::sleep_for(std::chrono::milliseconds(250));
			}
			SKSE::log::info("Plugin log tailer thread stopped.");
		}).detach();
	}

	// Reads the two settings.json toggles that need native engine access (everything else -
	// deltas, cooldown, affinity scaling - is enforced server-side in PHP and never reaches
	// here at all). Missing file or keys keep the defaults above, so a bare-minimum or absent
	// settings.json doesn't disable anything.
	void LoadSettings()
	{
		try {
			std::ifstream ifs(SETTINGS_FILE_PATH);
			if (!ifs.is_open()) {
				return;
			}
			json root;
			ifs >> root;
			g_notificationsEnabled.store(root.value("notifications_enabled", true));
			g_skipDuringScene.store(root.value("skip_during_scene_enabled", true));
			SKSE::log::info("Loaded settings: notifications_enabled={} skip_during_scene_enabled={}",
				g_notificationsEnabled.load(), g_skipDuringScene.load());
		} catch (const std::exception& e) {
			SKSE::log::warn("Failed to load settings.json: {}", e.what());
		}
	}

	// Lets a devbench MCP/REST client simulate a CHIM_ApplyDynamics event for testing,
	// without needing a real dialogue interaction to reach output_to_plugin.log.
	void DevBenchApplyDynamicsHandler(void* /*a_ctx*/, const char* a_argsJson, void* a_sink, DevBenchAPI::WriteFn a_write)
	{
		json result;
		try {
			const json args = json::parse(a_argsJson);
			const std::string actorName = args.value("actor", "");
			if (actorName.empty()) {
				result = { { "ok", false }, { "error", "actor is required" } };
			} else {
				const float deltaArousal = args.value("deltaArousal", 0.0f);
				const float deltaAffinity = args.value("deltaAffinity", 0.0f);
				const std::string tag = args.value("tag", "devbench");
				const std::string reason = args.value("reason", "devbench test");

				std::ostringstream payload;
				payload << actorName << "|" << deltaArousal << "|" << deltaAffinity << "|" << tag << "|" << reason;
				ProcessApplyDynamicsPayload(payload.str());

				result = { { "ok", true }, { "actor", actorName } };
			}
		} catch (const std::exception& ex) {
			result = { { "ok", false }, { "error", ex.what() } };
		}

		a_write(a_sink, result.dump().c_str());
	}

	void RegisterDevBenchTools()
	{
		auto* dvb = DevBenchAPI::GetDevBenchInterface001();
		if (!dvb) {
			SKSE::log::info("DevBench not detected; skipping tool registration.");
			return;
		}

		constexpr auto descriptor = R"({
			"description": "Simulates a CHIM_ApplyDynamics event as if tailed from output_to_plugin.log: updates the bridge's actor cache, applies the arousal delta to native OSLAroused, and fires the in-game notification.",
			"inputSchema": {
				"type": "object",
				"properties": {
					"actor": { "type": "string", "description": "Actor display name, e.g. Stenvar" },
					"deltaArousal": { "type": "number", "default": 0 },
					"deltaAffinity": { "type": "number", "default": 0 },
					"tag": { "type": "string", "default": "devbench" },
					"reason": { "type": "string", "default": "devbench test" }
				},
				"required": ["actor"]
			},
			"readOnly": false
		})";

		dvb->RegisterTool("oslaroused_bridge.apply_dynamics", descriptor, &DevBenchApplyDynamicsHandler, nullptr);
		SKSE::log::info("Registered DevBench tool: oslaroused_bridge.apply_dynamics");
	}

}  // namespace

namespace BridgeAPI
{
	// state.json is owned and written by the HerikaServer oslaroused_bridge PHP extension
	// (ext/oslaroused_bridge/functions.php), under a top-level "dynamics" object. The plugin
	// only reads it to seed its in-memory cache and must never write to it, or it will
	// clobber the PHP extension's data.
	void LoadState()
	{
		try {
			std::ifstream ifs(STATE_FILE_PATH);
			if (!ifs.is_open()) return;

			json root;
			ifs >> root;

			const auto dynamicsIt = root.find("dynamics");
			if (dynamicsIt == root.end() || !dynamicsIt->is_object()) {
				SKSE::log::warn("state.json has no \"dynamics\" object; nothing to load.");
				return;
			}

			std::lock_guard lock(g_stateMutex);
			for (auto it = dynamicsIt->begin(); it != dynamicsIt->end(); ++it) {
				auto& name = it.key();
				auto& val = it.value();

				ActorState st;
				st.name = name;
				st.arousal = val.value("arousal", 0.0f);
				st.affinity = val.value("affinity", 0.0f);
				st.last_tag = val.value("last_tag", "");
				st.last_reason = val.value("last_reason", "");
				st.updated_at = val.value("updated_at", CurrentTimestamp());

				g_actors[name] = st;
			}
			SKSE::log::info("Loaded state for {} actors.", g_actors.size());
		} catch (const std::exception& e) {
			SKSE::log::warn("Failed to load state.json: {}", e.what());
		}
	}

	json GetStateJson()
	{
		json root = json::object();
		std::lock_guard lock(g_stateMutex);
		for (const auto& [name, st] : g_actors) {
			root[name] = {
				{ "arousal", st.arousal },
				{ "affinity", st.affinity },
				{ "last_tag", st.last_tag },
				{ "last_reason", st.last_reason },
				{ "updated_at", st.updated_at }
			};
		}
		return root;
	}

	json GetActorJson(const std::string& a_name)
	{
		std::lock_guard lock(g_stateMutex);
		auto it = g_actors.find(a_name);
		if (it != g_actors.end()) {
			return {
				{ "arousal", it->second.arousal },
				{ "affinity", it->second.affinity },
				{ "last_tag", it->second.last_tag },
				{ "last_reason", it->second.last_reason },
				{ "updated_at", it->second.updated_at }
			};
		}
		return nullptr;
	}

	void ApplyDynamics(const std::string& a_actorName, float a_deltaArousal, float a_deltaAffinity, const std::string& a_tag, const std::string& a_reason)
	{
		std::lock_guard lock(g_stateMutex);
		auto& st = g_actors[a_actorName];
		if (st.name.empty()) {
			st.name = a_actorName;
		}
		
		st.arousal = std::clamp(st.arousal + a_deltaArousal, 0.0f, 100.0f);
		st.affinity = std::clamp(st.affinity + a_deltaAffinity, -100.0f, 100.0f);
		
		st.last_tag = a_tag;
		st.last_reason = a_reason;
		st.updated_at = CurrentTimestamp();

		SKSE::log::info("ApplyDynamics updated actor {} -> Arousal: {}, Affinity: {}, Tag: {}", 
						a_actorName, st.arousal, st.affinity, a_tag);
	}

	void ScanActors()
	{
		// stub for bridging with oslaroused natively
	}
}

SKSEPluginLoad(const SKSE::LoadInterface *skse) {
	SKSE::Init(skse);
	InitializeLogging();

	SKSE::GetMessagingInterface()->RegisterListener([](SKSE::MessagingInterface::Message *message) {
		if (message->type == SKSE::MessagingInterface::kPostLoad) {
			RegisterDevBenchTools();
		} else if (message->type == SKSE::MessagingInterface::kDataLoaded) {
			// state.json and settings.json live on a WSL network share; reading them here would
			// block the main thread during main-menu load if that share is slow to wake up.
			std::thread([]() {
				BridgeAPI::LoadState();
				LoadSettings();
			}).detach();

			StartArousalWatch();
			StartPluginLogTailer();

			if (auto* console = RE::ConsoleLog::GetSingleton(); console) {
				console->Print("HelloWorld bridge loaded.");
			}
		} else if (message->type == SKSE::MessagingInterface::kPostLoadGame ||
				   message->type == SKSE::MessagingInterface::kNewGame) {
			g_gameReady.store(true);
			SKSE::log::info("Game world ready; arousal updates enabled.");
		} else if (message->type == SKSE::MessagingInterface::kPreLoadGame) {
			g_gameReady.store(false);
			SKSE::log::info("Game world unloading; arousal updates paused.");
		}
	});

	return true;
}
