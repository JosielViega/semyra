; Semyra MediaLab Preview installer
; INTERNAL HOMOLOGATION ONLY - DO NOT DISTRIBUTE PUBLICLY.
; Requires Inno Setup 6.3 or newer because x64compatible is used.

#ifndef ReleaseDir
  #define ReleaseDir "..\..\storage\cache\releases\semyra-desktop-0.15.0-preview.1-MediaLab-win-x64"
#endif
#ifndef InstallerOutputDir
  #define InstallerOutputDir "..\..\storage\cache\releases"
#endif
#ifndef SemyraVersion
  #define SemyraVersion "0.15.0-preview.1"
#endif

#define SemyraNumericVersion "0.15.0.1"
#define SemyraAppId "{{A6D30BE1-4E28-4A62-8D28-0DF4F8E18331}"
#define SemyraExe "Semyra.Desktop.exe"

[Setup]
AppId={#SemyraAppId}
AppName=Semyra MediaLab Preview
AppVerName=Semyra MediaLab Preview {#SemyraVersion}
AppVersion={#SemyraVersion}
AppPublisher=Semyra Project
AppPublisherURL=https://semyra.jzlcode.com.br/
AppSupportURL=https://semyra.jzlcode.com.br/
AppUpdatesURL=https://semyra.jzlcode.com.br/
AppComments=HOMOLOGACAO INTERNA COM MPV E GSTREAMER - NAO DISTRIBUIR PUBLICAMENTE.
VersionInfoVersion={#SemyraNumericVersion}
VersionInfoProductName=Semyra MediaLab Preview
VersionInfoProductVersion={#SemyraNumericVersion}
VersionInfoCompany=Semyra Project
VersionInfoDescription=Semyra MediaLab Preview - homologacao interna com runtimes de midia
VersionInfoCopyright=Copyright (C) 2026 Semyra contributors
DefaultDirName={localappdata}\Programs\Semyra-MediaLab-Preview
DefaultGroupName=Semyra MediaLab Preview
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0.17763
OutputDir={#InstallerOutputDir}
OutputBaseFilename=Semyra-{#SemyraVersion}-MediaLab-win-x64-setup
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
Uninstallable=yes
CreateUninstallRegKey=yes
UninstallDisplayName=Semyra MediaLab Preview {#SemyraVersion}
UninstallDisplayIcon={app}\{#SemyraExe}
CloseApplications=yes
CloseApplicationsFilter=Semyra.Desktop.exe,Semyra.MediaHost.exe,mpv.exe,gst-launch-1.0.exe
RestartApplications=no
RestartIfNeededByRun=no
ChangesAssociations=no
ChangesEnvironment=no
AllowNetworkDrive=no
AllowUNCPath=no
AllowRootDirectory=no
SetupLogging=yes
UsePreviousAppDir=yes
UsePreviousGroup=yes
UsePreviousTasks=yes

[Files]
Source: "{#ReleaseDir}\*.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#ReleaseDir}\*.dll"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#ReleaseDir}\*.json"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#ReleaseDir}\*.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#ReleaseDir}\*.resources.dll"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#ReleaseDir}\runtimes\win-x64\native\*.dll"; DestDir: "{app}\runtimes\win-x64\native"; Flags: ignoreversion
Source: "{#ReleaseDir}\runtime\mpv\*"; DestDir: "{app}\runtime\mpv"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#ReleaseDir}\runtime\gstreamer\*"; DestDir: "{app}\runtime\gstreamer"; Flags: ignoreversion recursesubdirs createallsubdirs

[Tasks]
Name: "desktopicon"; Description: "Criar atalho na Area de Trabalho"; GroupDescription: "Atalhos adicionais:"; Flags: unchecked

[Icons]
Name: "{group}\Semyra MediaLab Preview"; Filename: "{app}\{#SemyraExe}"; WorkingDir: "{app}"
Name: "{userdesktop}\Semyra MediaLab Preview"; Filename: "{app}\{#SemyraExe}"; WorkingDir: "{app}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#SemyraExe}"; Description: "Abrir Semyra MediaLab Preview"; WorkingDir: "{app}"; Flags: postinstall nowait skipifsilent

[Code]
const
  WebView2ClientKey = 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';

function IsUsableWebView2Version(const Version: String): Boolean;
begin
  Result := (Version <> '') and (CompareText(Version, '0.0.0.0') <> 0);
end;

function IsWebView2EvergreenInstalled: Boolean;
var
  Version: String;
begin
  Result := False;
  if RegQueryStringValue(HKCU, WebView2ClientKey, 'pv', Version) then
  begin
    if IsUsableWebView2Version(Version) then
    begin
      Result := True;
      Exit;
    end;
  end;
  Version := '';
  if RegQueryStringValue(HKLM32, WebView2ClientKey, 'pv', Version) then
    Result := IsUsableWebView2Version(Version);
end;

function InitializeSetup: Boolean;
begin
  if not IsWebView2EvergreenInstalled then
  begin
    MsgBox(
      'O Microsoft Edge WebView2 Runtime nao foi encontrado.' + #13#10 + #13#10 +
      'A instalacao pode continuar, mas o Semyra Desktop nao conseguira abrir ' +
      'ate que o WebView2 Evergreen Runtime seja instalado a partir da Microsoft.',
      mbInformation,
      MB_OK);
  end;
  Result := True;
end;

procedure InitializeWizard;
begin
  WizardForm.WelcomeLabel2.Caption :=
    WizardForm.WelcomeLabel2.Caption + #13#10 + #13#10 +
    'HOMOLOGACAO INTERNA: esta variante inclui MPV e GStreamer apenas para testes ' +
    'locais. Pendencias de GPL, x264, H.264 e proveniencia impedem distribuicao publica.';
end;
