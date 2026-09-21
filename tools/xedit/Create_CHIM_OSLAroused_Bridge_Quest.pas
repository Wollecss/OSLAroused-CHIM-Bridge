unit Create_CHIM_OSLAroused_Bridge_Quest;

interface

implementation

uses xEditAPI, Classes, SysUtils;

//===========================================================================
// Find a loaded plugin by file name without relying on FileByName.
function GetPluginByName(const aName: string): IwbFile;
var
  i: integer;
  f: IwbFile;
begin
  Result := nil;
  for i := 0 to FileCount - 1 do begin
    f := FileByIndex(i);
    if Assigned(f) and SameText(GetFileName(f), aName) then begin
      Result := f;
      Exit;
    end;
  end;
end;

//===========================================================================
// Delete a file if it exists. Used to clear stale generated ESPs.
procedure DeleteIfExists(const aPath: string);
begin
  if FileExists(aPath) then begin
    AddMessage('Deleting existing file: ' + aPath);
    if not DeleteFile(aPath) then
      AddMessage('WARNING: Could not delete ' + aPath);
  end;
end;

//===========================================================================
// Save the newly created/updated plugin directly to the Data folder.
procedure SavePluginToData(aFile: IwbFile);
var
  fs: TFileStream;
  fileName: string;
begin
  fileName := wbDataPath + GetFileName(aFile);
  AddMessage('Saving plugin to: ' + fileName);
  fs := TFileStream.Create(fileName, fmCreate);
  try
    FileWriteToStream(aFile, fs, False);
  finally
    fs.Free;
  end;
  AddMessage('Saved. You can close SSEEdit.');
end;

function Initialize: integer;
var
  plugin: IwbFile;
  quest: IInterface;
  vmad: IInterface;
  scripts: IInterface;
  scriptEntry: IInterface;
  existing: IInterface;
  questGroup: IInterface;
  existingEspPath: string;
begin
  Result := 0;

  // Load or create the bridge ESP.
  plugin := GetPluginByName('CHIM_OSLAroused_Bridge.esp');
  if not Assigned(plugin) then begin
    // Remove any stale ESP files so AddNewFileName does not prompt.
    existingEspPath := wbDataPath + 'CHIM_OSLAroused_Bridge.esp';
    DeleteIfExists(existingEspPath);
    DeleteIfExists(existingEspPath + '.bak');
    DeleteIfExists(existingEspPath + '.old');

    plugin := AddNewFileName('CHIM_OSLAroused_Bridge.esp');
    if not Assigned(plugin) then begin
      AddMessage('ERROR: Could not create CHIM_OSLAroused_Bridge.esp');
      AddMessage('If a dialog asked about an existing file, click Yes/Overwrite next time.');
      Exit;
    end;
  end;

  // Make the plugin declare its dependencies so the game loads it after them.
  AddMessage('Adding required masters...');
  AddMasterIfMissing(plugin, 'Skyrim.esm');
  AddMasterIfMissing(plugin, 'Update.esm');
  AddMasterIfMissing(plugin, 'AIAgent.esp');
  AddMasterIfMissing(plugin, 'OSLAroused.esp');

  AddMessage('Working in plugin: ' + GetFileName(plugin));

  // Ensure the QUST group exists before searching for the record.
  questGroup := GroupBySignature(plugin, 'QUST');
  if not Assigned(questGroup) then
    questGroup := Add(plugin, 'QUST', True);

  existing := RecordByEditorID(questGroup, 'CHIM_OSLAroused_Bridge');
  if Assigned(existing) then begin
    quest := existing;
    AddMessage('Reusing existing quest record CHIM_OSLAroused_Bridge');
  end else begin
    quest := Add(questGroup, 'QUST', True);
    if not Assigned(quest) then begin
      AddMessage('ERROR: Could not create QUST record');
      Exit;
    end;
    AddMessage('Created new quest record');
  end;

  // Set the EditorID.
  SetElementEditValues(quest, 'EDID', 'CHIM_OSLAroused_Bridge');

  // Ensure Start Game Enabled is set so the quest script runs automatically.
  // For QUST records this flag lives in DNAM\Flags, not the record header.
  if not Assigned(ElementByPath(quest, 'DNAM')) then
    Add(quest, 'DNAM', True);
  SetElementNativeValues(quest, 'DNAM\Flags\Start Game Enabled', 1);

  // Add the quest script to the VMAD Virtual Machine Adapter.
  AddMessage('Attaching CHIM_OSLAroused_Bridge script...');
  vmad := ElementByPath(quest, 'VMAD');
  if not Assigned(vmad) then
    vmad := Add(quest, 'VMAD', True);

  scripts := ElementByPath(vmad, 'Scripts');
  if not Assigned(scripts) then
    scripts := Add(vmad, 'Scripts', True);

  // Clear any previous script entry so we don't stack duplicates.
  while ElementCount(scripts) > 0 do
    RemoveByIndex(scripts, 0, True);

  scriptEntry := ElementAssign(scripts, HighInteger, nil, False);
  // Ensure the Script Name element exists before setting it.
  // Some xEdit builds create a blank script entry without the name field.
  if not Assigned(ElementByPath(scriptEntry, 'Script Name')) then
    Add(scriptEntry, 'Script Name', True);
  SetElementEditValues(scriptEntry, 'Script Name', 'CHIM_OSLAroused_Bridge');

  // Verify it stuck so users don't end up with an empty script name again.
  if GetElementEditValues(scriptEntry, 'Script Name') <> 'CHIM_OSLAroused_Bridge' then
    AddMessage('WARNING: VMAD Script Name was not written. Verify manually in SSEEdit.');

  AddMessage('CHIM_OSLAroused_Bridge quest configured.');

  // Write the ESP immediately so the user only has to run this script.
  SavePluginToData(plugin);
end;

end.
