<?php
/**
 * CHIM - OSLAroused Bridge: JSON response schema customization.
 *
 * Adds action-specific fields to CHIM's structured output schema so the LLM can
 * legally return the parameters required by EvaluateEmotionalDynamics.
 *
 * This file is auto-loaded by functions/json_response.php via
 * requireFilesRecursively(..., "json_response_custom.php").
 */

if (!isset($GLOBALS["CHIM_OSLAROUSED_JSON_CUSTOM_LOADED"])) {
    $GLOBALS["CHIM_OSLAROUSED_JSON_CUSTOM_LOADED"] = true;

    // Fields used by EvaluateEmotionalDynamics.
    $oslResponseFields = [
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
    ];

    // 1. Extend the structured-output JSON schema (used by OpenRouter/OpenAI providers).
    if (
        isset($GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]) &&
        is_array($GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"])
    ) {
        $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"] = array_merge(
            $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"],
            $oslResponseFields
        );
    }

    // 2. Extend the prompt example/template (used by providers that rely on prompt text).
    if (isset($GLOBALS["responseTemplate"]) && is_array($GLOBALS["responseTemplate"])) {
        foreach ($oslResponseFields as $fieldName => $fieldSchema) {
            if (!array_key_exists($fieldName, $GLOBALS["responseTemplate"])) {
                $GLOBALS["responseTemplate"][$fieldName] = $fieldSchema["description"];
            }
        }
    }
}
