#pragma once

#include <nlohmann/json.hpp>
#include <string>

namespace BridgeAPI
{
	using json = nlohmann::json;

	// Returns the entire in-memory actor cache as JSON.
	json GetStateJson();

	// Returns a single actor's state, or null if not found.
	json GetActorJson(const std::string& a_name);

	// Applies the same logic as a CHIM_ApplyDynamics payload.
	void ApplyDynamics(const std::string& a_actorName,
		float a_deltaArousal,
		float a_deltaAffinity,
		const std::string& a_tag,
		const std::string& a_reason);

	// Triggers an OSLAroused actor scan now.
	void ScanActors();
}  // namespace BridgeAPI
