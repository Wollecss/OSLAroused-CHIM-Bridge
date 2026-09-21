Scriptname CHIM_OSLAroused_Bridge extends Quest

; CHIM - OSLAroused Bridge quest script.
float Property _pollIntervalGameTime = 0.25 Auto

Event OnInit()
    Debug.Trace("[CHIM-OSLAroused] Bridge quest initialized")
    RegisterForCommandEvents()
    RegisterForSingleUpdateGameTime(_pollIntervalGameTime)
EndEvent

Function RegisterForCommandEvents()
    UnRegisterForModEvent("CHIM_CommandReceived")
    RegisterForModEvent("CHIM_CommandReceived", "CommandManager")
    Debug.Trace("[CHIM-OSLAroused] Registered for CHIM_CommandReceived")
EndFunction

Function CommandManager(String npcname, String command, String parameter)
    Debug.Trace("[CHIM-OSLAroused] CommandManager: <" + npcname + "> <" + command + "> <" + parameter + ">")

    if (command != "CHIM_ApplyDynamics")
        return
    endif

    ApplyDynamics(npcname, parameter)
EndFunction

Function ApplyDynamics(String defaultActorName, String parameter)
    String actorName = ""
    float deltaArousal = 0.0
    float deltaAffinity = 0.0
    String reactionTag = ""
    String reason = ""

    int p1 = StringUtil.Find(parameter, "|")
    if (p1 >= 0)
        actorName = StringUtil.Substring(parameter, 0, p1)
        int p2 = StringUtil.Find(parameter, "|", p1 + 1)
        if (p2 >= 0)
            String arousalStr = StringUtil.Substring(parameter, p1 + 1, p2 - p1 - 1)
            deltaArousal = arousalStr as float
            int p3 = StringUtil.Find(parameter, "|", p2 + 1)
            if (p3 >= 0)
                String affinityStr = StringUtil.Substring(parameter, p2 + 1, p3 - p2 - 1)
                deltaAffinity = affinityStr as float
                int p4 = StringUtil.Find(parameter, "|", p3 + 1)
                if (p4 >= 0)
                    reactionTag = StringUtil.Substring(parameter, p3 + 1, p4 - p3 - 1)
                    reason = StringUtil.Substring(parameter, p4 + 1)
                else
                    reactionTag = StringUtil.Substring(parameter, p3 + 1)
                endif
            endif
        endif
    endif

    if (actorName == "")
        actorName = defaultActorName
    endif

    Actor target = FindActorByName(actorName)
    if (!target)
        Debug.Trace("[CHIM-OSLAroused] Could not find actor: " + actorName)
        return
    endif

    float newArousal = OSLArousedNative.ModifyArousal(target, deltaArousal)

    String notif = "[CHIM-OSLAroused] " + actorName + ": arousal " + deltaArousal
    if (deltaAffinity != 0.0)
        notif += ", affinity " + deltaAffinity
    endif
    if (reactionTag != "")
        notif += " (" + reactionTag + ")"
    endif
    Debug.Notification(notif)

    BroadcastArousal(actorName, target)
EndFunction

Actor Function FindActorByName(String name)
    if (name == "")
        return None
    endif

    Actor player = Game.GetPlayer()
    if (player)
        String playerName = player.GetDisplayName()
        if (playerName == "")
            playerName = player.GetName()
        endif
        if (playerName == name)
            return player
        endif
    endif

    Actor[] nearby = AIAgentFunctions.findAllNearbyAgents()
    int i = 0
    while i < nearby.Length
        if (nearby[i])
            String displayName = nearby[i].GetDisplayName()
            if (displayName == "")
                displayName = nearby[i].GetName()
            endif
            if (displayName == name)
                return nearby[i]
            endif
        endif
        i += 1
    endwhile

    return None
EndFunction

Function BroadcastArousal(String actorName, Actor target)
    if (!target)
        return
    endif

    float arousal = OSLArousedNative.GetArousalNoSideEffects(target)
    String payload = "oslaroused_state@" + actorName + "@" + arousal
    AIAgentFunctions.logMessage(payload, "status_msg")
    Debug.Trace("[CHIM-OSLAroused] Broadcast: " + payload)
EndFunction

Event OnUpdate()
    Actor player = Game.GetPlayer()
    if (player)
        String playerName = player.GetDisplayName()
        if (playerName == "")
            playerName = player.GetName()
        endif
        if (playerName != "")
            BroadcastArousal(playerName, player)
        endif
    endif

    Actor[] nearby = AIAgentFunctions.findAllNearbyAgents()
    int i = 0
    while i < nearby.Length
        if (nearby[i])
            String displayName = nearby[i].GetDisplayName()
            if (displayName == "")
                displayName = nearby[i].GetName()
            endif
            if (displayName != "")
                BroadcastArousal(displayName, nearby[i])
            endif
        endif
        i += 1
    endwhile

    RegisterForSingleUpdateGameTime(_pollIntervalGameTime)
EndEvent
