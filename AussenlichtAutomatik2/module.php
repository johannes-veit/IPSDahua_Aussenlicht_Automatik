<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/DahuaHumanTracker.php';
require_once dirname(__DIR__) . '/libs/NightWindow.php';

class AussenlichtAutomatik2 extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const VM_UPDATE_ID = 10603;
    private const IM_CHANGESTATUS_ID = 10505;
    private const LIGHT_CONFIRM_SECONDS = 60;
    private const LIGHT_RECHECK_SECONDS = 15;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyString('CameraHost', '');
        $this->RegisterPropertyInteger('CameraPort', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('LightAutomationEnabled', true);

        // Nachtfreigabe ausschließlich über astronomische Zeitpunkte aus Location Control.
        // Wird nur ausgewertet, wenn LightAutomationEnabled = true.
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);

        // Legacy-Eigenschaften aus 0.1.0 bleiben nur für ein sauberes Update registriert.
        // Sie werden nicht mehr ausgewertet und erscheinen nicht mehr im Formular.
        $this->RegisterPropertyInteger('DayVariableID', 0);
        $this->RegisterPropertyBoolean('DarkWhenDayVariableFalse', true);

        // Optionale Lichtautomatik: Schaltvariable mit Aktion + separate echte Rückmeldung.
        $this->RegisterPropertyInteger('LightCommandVariableID', 0);
        $this->RegisterPropertyInteger('LightFeedbackVariableID', 0);
        $this->RegisterPropertyInteger('AfterRunSeconds', 180);
        $this->RegisterPropertyBoolean('DebugEvents', false);

        $this->RegisterAttributeBoolean('PersonActive', false);
        $this->RegisterAttributeBoolean('AutoOwned', false);
        $this->RegisterAttributeBoolean('ManualLockUntilPersonClear', false);
        $this->RegisterAttributeInteger('LastLightFeedback', -1);
        $this->RegisterAttributeInteger('SelfCommandUntil', 0);
        $this->RegisterAttributeInteger('SelfCommandTarget', -1);
        $this->RegisterAttributeInteger('OffDue', 0);
        $this->RegisterAttributeInteger('LastCameraRx', 0);
        $this->RegisterAttributeInteger('LastHttpRequest', 0);
        $this->RegisterAttributeBoolean('Streaming', false);
        $this->RegisterAttributeInteger('DigestNC', 0);
        $this->RegisterAttributeString('DigestChallenge', '{}');
        $this->RegisterAttributeBoolean('AuthPending', false);
        $this->RegisterAttributeBoolean('LastRequestAuthenticated', false);
        $this->RegisterAttributeBoolean('AuthBlocked', false);
        $this->RegisterAttributeInteger('AuthFailureCount', 0);
        $this->RegisterAttributeInteger('SocketRestartStage', 0);
        $this->RegisterAttributeInteger('LastSocketRestart', 0);
        $this->RegisterAttributeString('ActiveHumanEvents', '{}');
        $this->RegisterAttributeInteger('HumanPulseUntil', 0);
        $this->RegisterAttributeString('LastHumanEvent', '');
        $this->RegisterAttributeInteger('RegisteredSunriseID', 0);
        $this->RegisterAttributeInteger('RegisteredSunsetID', 0);
        $this->RegisterAttributeInteger('RegisteredFeedbackID', 0);
        $this->RegisterAttributeInteger('RegisteredCommandID', 0);
        $this->RegisterAttributeInteger('RegisteredParentID', 0);

        // Sichtbare, read-only Diagnosevariablen. Keine Aktion freigeben.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('NightPermission', 'Nachtfreigabe', '~Switch', 20);

        $this->RegisterTimer('OffTimer', 0, 'ALA2_OffTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SunBoundaryTimer', 0, 'ALA2_SunBoundaryTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('HandshakeTimer', 0, 'ALA2_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'ALA2_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('PersonStateTimer', 0, 'ALA2_PersonStateTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'ALA2_Watchdog($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Auch bei einem Update einer bereits vorhandenen Instanz anlegen.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('NightPermission', 'Nachtfreigabe', '~Switch', 20);

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('PersonStateTimer', 0);
        $this->SetTimerInterval('OffTimer', 0);
        $this->SetTimerInterval('SunBoundaryTimer', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->WriteAttributeBoolean('Streaming', false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('SocketRestartStage', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->clearSelfCommand();

        // Ein Modulupdate/ApplyChanges trennt den Eventstream. Aktive Human-Zustände
        // dürfen deshalb nicht aus einem alten Stream übernommen werden.
        $wasPersonActive = $this->ReadAttributeBoolean('PersonActive');
        $this->WriteAttributeString('ActiveHumanEvents', '{}');
        $this->WriteAttributeInteger('HumanPulseUntil', 0);
        $this->setPersonActive(false);
        if ($wasPersonActive) {
            $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
            if ($this->lightAutomationEnabled() && $this->ReadAttributeBoolean('AutoOwned')) {
                $this->scheduleOff();
            }
        }

        // 0.1.0 verwendete noch "Ist es Tag". Diese Variable ist bewusst nicht mehr Teil der Logik.
        $legacyDayVar = $this->ReadPropertyInteger('DayVariableID');
        if ($this->isBooleanVariable($legacyDayVar)) {
            try {
                $this->UnregisterMessage($legacyDayVar, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
                // Bei einer Erstinstallation existiert keine alte Registrierung.
            }
        }

        $sunriseVar = 0;
        $sunsetVar = 0;
        $feedbackVar = 0;
        $commandVar = 0;
        if ($this->lightAutomationEnabled()) {
            $candidate = $this->ReadPropertyInteger('SunriseVariableID');
            if ($this->isIntegerVariable($candidate)) {
                $sunriseVar = $candidate;
            }
            $candidate = $this->ReadPropertyInteger('SunsetVariableID');
            if ($this->isIntegerVariable($candidate)) {
                $sunsetVar = $candidate;
            }
            $candidate = $this->ReadPropertyInteger('LightCommandVariableID');
            if ($this->isBooleanVariable($candidate) && HasAction($candidate)) {
                $commandVar = $candidate;
            }
            $candidate = $this->ReadPropertyInteger('LightFeedbackVariableID');
            if ($this->isNumericVariable($candidate)) {
                $feedbackVar = $candidate;
            }

            // Wird auf eine andere Lampe / andere Rückmeldung umkonfiguriert, darf
            // Eigentum der alten Lampe niemals auf die neue Auswahl übertragen werden.
            $oldCommand = $this->ReadAttributeInteger('RegisteredCommandID');
            $oldFeedback = $this->ReadAttributeInteger('RegisteredFeedbackID');
            $ioChanged = ($oldCommand > 0 && $oldCommand !== $commandVar)
                || ($oldFeedback > 0 && $oldFeedback !== $feedbackVar);
            if ($ioChanged) {
                $this->WriteAttributeBoolean('AutoOwned', false);
                $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
                $this->WriteAttributeInteger('OffDue', 0);
                $this->SetTimerInterval('OffTimer', 0);
                $this->clearSelfCommand();
                $this->debug('Licht', 'Licht-I/O geändert; altes Automatik-Eigentum sicher verworfen');
            }

            if ($feedbackVar > 0) {
                $current = ((float) GetValue($feedbackVar)) > 0.0;
                $last = $this->ReadAttributeInteger('LastLightFeedback');
                if ($last !== -1 && $last !== (int) $current && $this->ReadAttributeBoolean('AutoOwned')) {
                    // Während Symcon/Modul inaktiv war, wurde der reale Lichtzustand verändert.
                    // Sicherheit: Eigentum der Automatik verwerfen, niemals blind später ausschalten.
                    $this->WriteAttributeBoolean('AutoOwned', false);
                    $this->WriteAttributeInteger('OffDue', 0);
                }
                $this->WriteAttributeInteger('LastLightFeedback', (int) $current);
            }
        } else {
            // Reine Personenerkennung: niemals Lichtbefehle oder Sonnen-Timer ausführen.
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
            $this->WriteAttributeInteger('OffDue', 0);
            $this->clearSelfCommand();
        }

        $this->WriteAttributeInteger('RegisteredCommandID', $commandVar);
        $this->updateVariableSubscription('RegisteredSunriseID', $sunriseVar);
        $this->updateVariableSubscription('RegisteredSunsetID', $sunsetVar);
        $this->updateVariableSubscription('RegisteredFeedbackID', $feedbackVar);
        $this->updateParentSubscription($this->getParentID());

        $this->syncStatusVariables();
        $this->restoreOffTimer();
        $this->scheduleSunBoundaryTimer();

        if ($this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
            // Kein Lichtbefehl in ApplyChanges(). Für den Dahua-Digest-Handshake wird
            // bewusst mit einer frischen TCP-Verbindung gestartet.
            $this->scheduleSocketRestart(500);
        }
    }

    public function GetConfigurationForParent(): string
    {
        $host = trim($this->ReadPropertyString('CameraHost'));
        $port = $this->ReadPropertyInteger('CameraPort');
        return json_encode([
            'Host' => $host,
            'Port' => $port,
            'Open' => $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()
        ]);
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode((string) $JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }

        $chunk = (string) $data['Buffer'];
        if ($chunk === '') {
            return '';
        }

        $this->WriteAttributeInteger('LastCameraRx', time());

        if ($this->ReadAttributeBoolean('Streaming')) {
            $this->processEventData($chunk);
            return '';
        }

        $http = $this->GetBuffer('HttpBuffer') . $chunk;
        if (strlen($http) > 262144) {
            $http = substr($http, -131072);
        }

        $headerEnd = strpos($http, "\r\n\r\n");
        if ($headerEnd === false) {
            $this->SetBuffer('HttpBuffer', $http);
            return '';
        }

        $header = substr($http, 0, $headerEnd + 4);
        $body = substr($http, $headerEnd + 4);
        $this->SetBuffer('HttpBuffer', '');

        if (!preg_match('#^HTTP/\d\.\d\s+(\d{3})#i', $header, $m)) {
            $this->debug('HTTP', 'Ungültiger Antwortkopf: ' . $this->singleLine($header));
            return '';
        }

        $status = (int) $m[1];
        if ($status === 401) {
            $challenge = $this->extractDigestChallenge($header);
            if ($challenge === []) {
                $this->debug('Dahua', '401 ohne auswertbare Digest-Challenge');
                return '';
            }

            $this->WriteAttributeString('DigestChallenge', json_encode($challenge));
            $wasAuthenticated = $this->ReadAttributeBoolean('LastRequestAuthenticated');

            if (!$wasAuthenticated) {
                // Dahua antwortet auf die erste Anfrage mit Digest-Challenge und
                // "Connection: close". Der authentifizierte Request MUSS deshalb
                // über eine neue TCP-Verbindung gesendet werden.
                $this->WriteAttributeBoolean('AuthPending', true);
                $this->debug('Dahua', 'Digest-Challenge empfangen; öffne frischen Socket für authentifizierten Eventstream');
                $this->scheduleSocketRestart(100);
                return '';
            }

            $stale = strtolower((string) ($challenge['stale'] ?? 'false')) === 'true';
            $failures = $this->ReadAttributeInteger('AuthFailureCount') + 1;
            $this->WriteAttributeInteger('AuthFailureCount', $failures);

            if ($stale && $failures <= 2) {
                $this->WriteAttributeBoolean('AuthPending', true);
                $this->debug('Dahua', 'Digest-Nonce ist stale; einmaliger Neuaufbau mit neuer Challenge');
                $this->scheduleSocketRestart(100);
                return '';
            }

            // Keine Endlosschleife mit falschem Passwort: Dahua-Konten können nach
            // wiederholten Fehlversuchen temporär gesperrt werden. Erst Speichern oder
            // der Diagnose-Button hebt diese Sperre im Modul wieder auf.
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('AuthBlocked', true);
            $this->debug('Dahua', 'Digest-Anmeldung abgewiesen. Weitere Anmeldeversuche gestoppt; Benutzername/Passwort prüfen und danach Verbindung neu anstoßen.');
            return '';
        }

        if ($status === 200) {
            $this->WriteAttributeBoolean('Streaming', true);
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('AuthBlocked', false);
            $this->WriteAttributeInteger('AuthFailureCount', 0);
            $this->debug('Dahua', 'Eventstream verbunden (HTTP 200, codes=[All])');
            if ($body !== '') {
                $this->processEventData($body);
            }
            return '';
        }

        $this->debug('HTTP', 'Unerwarteter Status ' . $status . ': ' . $this->singleLine($header));
        return '';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ((int) $Message === self::VM_UPDATE_ID) {
            $sunriseVar = $this->ReadPropertyInteger('SunriseVariableID');
            $sunsetVar = $this->ReadPropertyInteger('SunsetVariableID');
            $feedbackVar = $this->ReadPropertyInteger('LightFeedbackVariableID');

            if ($this->lightAutomationEnabled() && ((int) $SenderID === $sunriseVar || (int) $SenderID === $sunsetVar)) {
                $this->handleSunStateChange('Location Control');
                return;
            }

            if ($this->lightAutomationEnabled() && (int) $SenderID === $feedbackVar) {
                $changed = true;
                if (is_array($Data) && array_key_exists(1, $Data)) {
                    $changed = (bool) $Data[1];
                }
                if ($changed) {
                    $this->handleLightFeedbackChange();
                }
                return;
            }
        }

        if ((int) $Message === self::IM_CHANGESTATUS_ID && (int) $SenderID === $this->getParentID()) {
            $status = 0;
            if (is_array($Data) && isset($Data[0])) {
                $status = (int) $Data[0];
            } elseif (IPS_InstanceExists((int) $SenderID)) {
                $status = (int) IPS_GetInstance((int) $SenderID)['InstanceStatus'];
            }

            if ($status === 102 && $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
                $this->WriteAttributeBoolean('Streaming', false);
                $this->SetBuffer('HttpBuffer', '');
                $this->SetBuffer('EventCarry', '');
                if (!$this->ReadAttributeBoolean('AuthBlocked')) {
                    // Nach der 401-Challenge steht AuthPending=true. Auf dem frisch
                    // geöffneten Socket wird dann direkt der Digest-Request gesendet.
                    $this->SetTimerInterval('HandshakeTimer', 250);
                }
            } elseif ($status !== 102) {
                $wasStreaming = $this->ReadAttributeBoolean('Streaming');
                $this->WriteAttributeBoolean('Streaming', false);
                if ($wasStreaming) {
                    $this->clearHumanTracking('Dahua-Stream getrennt');
                }
            }
        }
    }

    public function HandshakeTimer(): void
    {
        $this->SetTimerInterval('HandshakeTimer', 0);
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }

        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID) || (int) IPS_GetInstance($parentID)['InstanceStatus'] !== 102) {
            return;
        }
        $this->beginHandshake();
    }

    public function SocketRestartTimer(): void
    {
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            return;
        }

        $stage = $this->ReadAttributeInteger('SocketRestartStage');
        if ($stage === 1) {
            // Phase 1: die von Dahua nach 401 geschlossene/sterbende Verbindung
            // auch in Symcon sauber verwerfen.
            try {
                IPS_SetProperty($parentID, 'Open', false);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->WriteAttributeInteger('SocketRestartStage', 0);
                $this->debug('Socket', 'Schließen fehlgeschlagen: ' . $e->getMessage());
                return;
            }
            $this->WriteAttributeInteger('SocketRestartStage', 2);
            $this->SetTimerInterval('SocketRestartTimer', 300);
            return;
        }

        if ($stage === 2) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
                return;
            }
            try {
                IPS_SetProperty($parentID, 'Host', $this->ReadPropertyString('CameraHost'));
                IPS_SetProperty($parentID, 'Port', $this->ReadPropertyInteger('CameraPort'));
                IPS_SetProperty($parentID, 'Open', true);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->debug('Socket', 'Öffnen fehlgeschlagen: ' . $e->getMessage());
                return;
            }
            $this->WriteAttributeInteger('LastSocketRestart', time());
            // Fallback, falls IM_CHANGESTATUS bei sehr schnellem Verbindungsaufbau
            // nicht mehr beobachtet wird. HandshakeTimer prüft Status 102 selbst.
            $this->SetTimerInterval('HandshakeTimer', 1000);
        }
    }

    public function Watchdog(): void
    {
        // Sonnenstatus zusätzlich zyklisch prüfen. Damit bleibt die Freigabe auch dann
        // korrekt, wenn ein Location-Control-Update oder ein Grenz-Timer verpasst wurde.
        if ($this->lightAutomationEnabled()) {
            $nightID = $this->GetIDForIdent('NightPermission');
            $actualNight = $this->isNight();
            if ($nightID > 0 && IPS_VariableExists($nightID) && GetValueBoolean($nightID) !== $actualNight) {
                $this->handleSunStateChange('Watchdog');
            }
            $this->checkPendingLightCommand();
        }

        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }

        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return;
        }

        $instance = IPS_GetInstance($parentID);
        $now = time();
        if ((int) $instance['InstanceStatus'] !== 102) {
            if ($this->ReadAttributeBoolean('Streaming')) {
                $this->WriteAttributeBoolean('Streaming', false);
                $this->clearHumanTracking('Client Socket nicht aktiv');
            }
            if ($this->ReadAttributeInteger('SocketRestartStage') === 0
                && ($now - $this->ReadAttributeInteger('LastSocketRestart')) >= 20) {
                $this->debug('Watchdog', 'Client Socket nicht aktiv; kontrollierter Neuaufbau');
                $this->scheduleSocketRestart(100);
            }
            return;
        }

        $lastRx = $this->ReadAttributeInteger('LastCameraRx');
        $lastReq = $this->ReadAttributeInteger('LastHttpRequest');
        $streaming = $this->ReadAttributeBoolean('Streaming');

        if ($streaming && $lastRx > 0 && ($now - $lastRx) > 25) {
            // Niemals einen zweiten HTTP-Request in einen bestehenden/halb toten
            // Eventstream schreiben. Stattdessen TCP sauber neu aufbauen.
            $this->debug('Watchdog', 'Kein Dahua-Heartbeat >25 s; Eventstream wird mit frischem Socket neu aufgebaut');
            $this->WriteAttributeBoolean('Streaming', false);
            $this->clearHumanTracking('Dahua-Heartbeat verloren');
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
            $this->WriteAttributeInteger('DigestNC', 0);
            $this->WriteAttributeString('DigestChallenge', '{}');
            $this->scheduleSocketRestart(100);
            return;
        }

        if (!$streaming && ($lastReq === 0 || ($now - $lastReq) > 12)
            && $this->ReadAttributeInteger('SocketRestartStage') === 0) {
            // Timeout während Challenge/Auth ebenfalls nur über eine neue TCP-Verbindung.
            $this->debug('Watchdog', 'Dahua-Handshake ohne Antwort; frischer Socket wird aufgebaut');
            $this->scheduleSocketRestart(100);
        }
    }

    public function Reconnect(): void
    {
        $wasStreaming = $this->ReadAttributeBoolean('Streaming');
        $this->WriteAttributeBoolean('Streaming', false);
        if ($wasStreaming || $this->ReadAttributeBoolean('PersonActive')) {
            $this->clearHumanTracking('Manueller Neuaufbau');
        }
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('LastCameraRx', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->scheduleSocketRestart(100);
    }

    public function DumpState(): void
    {
        $state = [
            'Enabled' => $this->ReadPropertyBoolean('Enabled'),
            'LightAutomationEnabled' => $this->lightAutomationEnabled(),
            'NightPermission' => $this->isNight(),
            'Sunrise' => $this->getSunTimestamp('SunriseVariableID'),
            'Sunset' => $this->getSunTimestamp('SunsetVariableID'),
            'PersonActive' => $this->ReadAttributeBoolean('PersonActive'),
            'AutoOwned' => $this->ReadAttributeBoolean('AutoOwned'),
            'ManualLockUntilPersonClear' => $this->ReadAttributeBoolean('ManualLockUntilPersonClear'),
            'LightCommandVariableID' => $this->ReadPropertyInteger('LightCommandVariableID'),
            'LightFeedbackVariableID' => $this->ReadPropertyInteger('LightFeedbackVariableID'),
            'LightFeedbackRaw' => $this->getLightFeedbackRaw(),
            'LightFeedback' => $this->getLightFeedback(),
            'OffDue' => $this->ReadAttributeInteger('OffDue'),
            'Streaming' => $this->ReadAttributeBoolean('Streaming'),
            'AuthPending' => $this->ReadAttributeBoolean('AuthPending'),
            'AuthBlocked' => $this->ReadAttributeBoolean('AuthBlocked'),
            'SocketRestartStage' => $this->ReadAttributeInteger('SocketRestartStage'),
            'LastCameraRx' => $this->ReadAttributeInteger('LastCameraRx'),
            'LastCameraRxAge' => $this->ReadAttributeInteger('LastCameraRx') > 0 ? max(0, time() - $this->ReadAttributeInteger('LastCameraRx')) : null,
            'ActiveHumanEvents' => $this->getActiveHumanEvents(),
            'HumanPulseUntil' => $this->ReadAttributeInteger('HumanPulseUntil'),
            'LastHumanEvent' => $this->ReadAttributeString('LastHumanEvent'),
            'ParentID' => $this->getParentID(),
            'ParentStatus' => ($this->getParentID() > 0 && IPS_InstanceExists($this->getParentID())) ? (int) IPS_GetInstance($this->getParentID())['InstanceStatus'] : null
        ];
        $this->SendDebug('State', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    public function OffTimer(): void
    {
        $this->SetTimerInterval('OffTimer', 0);

        if (!$this->lightAutomationEnabled()) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->WriteAttributeInteger('OffDue', 0);
            return;
        }

        // Solange nachts noch eine Person aktiv ist, darf nicht ausgeschaltet werden.
        // Ein eventuell alter Timer wird verworfen; beim Übergang auf INAKTIV wird
        // der Nachlauf durch refreshPersonAggregate() neu gestartet.
        if ($this->ReadAttributeBoolean('PersonActive') && $this->isNight()) {
            $this->WriteAttributeInteger('OffDue', 0);
            return;
        }
        if (!$this->ReadAttributeBoolean('AutoOwned')) {
            $this->WriteAttributeInteger('OffDue', 0);
            return;
        }

        $light = $this->getLightFeedback();
        if ($light === false) {
            // Die echte LCN-Intensity bestätigt AUS. Erst jetzt ist der
            // Ausschaltvorgang abgeschlossen und das Automatik-Eigentum endet.
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->WriteAttributeInteger('OffDue', 0);
            $this->debug('Licht', 'Automatik AUS durch echte Intensity bestätigt');
            return;
        }

        if ($light === null) {
            // Wichtiger Fix 0.3.1: Auch nachts darf ein vorübergehend nicht lesbarer
            // Istwert den Ausschaltvorgang nicht endgültig abbrechen. Solange die
            // Automatik Eigentümer ist, wird die Prüfung kontrolliert wiederholt.
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            $this->debug('Licht', 'AUS wartet: echte LCN-Intensity momentan nicht verfügbar; erneute Prüfung in 15 s');
            return;
        }

        // Das Licht ist laut echter Rückmeldung weiterhin EIN. AUS anfordern.
        // AutoOwned bleibt absichtlich TRUE, bis die echte Intensity 0 meldet.
        // RequestAction(TRUE/FALSE) bestätigt nur die Annahme der Aktion, nicht den
        // tatsächlichen Hardwarezustand.
        if ($this->switchLight(false)) {
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            $this->debug('Licht', $this->isNight()
                ? 'Automatik AUS angefordert; warte auf echte Intensity=0'
                : 'Automatik AUS wegen Ende der Nachtfreigabe angefordert; warte auf echte Intensity=0');
        } else {
            // Auch ein temporär fehlgeschlagener Request darf das Licht nicht dauerhaft
            // eingeschaltet lassen. Kontrolliert erneut versuchen.
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            $this->debug('Licht', 'AUS-Befehl fehlgeschlagen; neuer Versuch in 15 s');
        }
    }

    private function beginHandshake(): void
    {
        if ($this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }
        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->sendEventRequest($this->ReadAttributeBoolean('AuthPending'));
    }

    private function sendEventRequest(bool $authenticated): void
    {
        $uri = '/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5';
        $host = $this->ReadPropertyString('CameraHost');
        $port = $this->ReadPropertyInteger('CameraPort');
        $hostHeader = $port === 80 ? $host : ($host . ':' . $port);

        $headers = [
            'GET ' . $uri . ' HTTP/1.1',
            'Host: ' . $hostHeader,
            'User-Agent: IP-Symcon-AussenlichtAutomatik2/0.3.2',
            'Accept: multipart/x-mixed-replace, */*',
            'Connection: keep-alive'
        ];

        if ($authenticated) {
            $challenge = json_decode($this->ReadAttributeString('DigestChallenge'), true);
            if (!is_array($challenge)) {
                $challenge = [];
            }
            $nc = $this->ReadAttributeInteger('DigestNC') + 1;
            $this->WriteAttributeInteger('DigestNC', $nc);
            $cnonce = substr(hash('sha256', $this->InstanceID . ':' . microtime(true) . ':' . mt_rand()), 0, 16);

            try {
                $authorization = DahuaDigest::buildAuthorization(
                    $this->ReadPropertyString('Username'),
                    $this->ReadPropertyString('Password'),
                    'GET',
                    $uri,
                    $challenge,
                    $nc,
                    $cnonce
                );
            } catch (Throwable $e) {
                $this->debug('Digest', $e->getMessage());
                return;
            }
            $headers[] = 'Authorization: ' . $authorization;
        }

        $request = implode("\r\n", $headers) . "\r\n\r\n";
        $payload = json_encode([
            'DataID' => self::SOCKET_TX_GUID,
            'Buffer' => $request
        ]);

        $this->WriteAttributeBoolean('LastRequestAuthenticated', $authenticated);
        $this->WriteAttributeInteger('LastHttpRequest', time());
        try {
            $this->SendDataToParent($payload);
            $this->debug('HTTP', $authenticated ? 'Digest-GET gesendet' : 'Initiales GET gesendet');
        } catch (Throwable $e) {
            $this->debug('HTTP', 'Senden fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function scheduleSocketRestart(int $delayMs = 100): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
            return;
        }
        if ($this->ReadAttributeInteger('SocketRestartStage') !== 0) {
            return;
        }
        $this->WriteAttributeInteger('SocketRestartStage', 1);
        $this->SetTimerInterval('SocketRestartTimer', max(50, $delayMs));
    }

    /**
     * @return array<string,string>
     */
    private function extractDigestChallenge(string $header): array
    {
        if (!preg_match('/^WWW-Authenticate:\s*(Digest\s+.+)$/im', $header, $m)) {
            return [];
        }
        return DahuaDigest::parseChallenge(trim((string) $m[1]));
    }

    private function processEventData(string $chunk): void
    {
        $carry = $this->GetBuffer('EventCarry');
        $events = DahuaEventParser::feed($chunk, $carry);
        $this->SetBuffer('EventCarry', $carry);

        foreach ($events as $event) {
            $action = strtolower(trim((string) $event['action']));
            $classification = $event['classification'] ?? null;

            if ($this->ReadPropertyBoolean('DebugEvents')) {
                $summary = [
                    'code' => $event['code'],
                    'action' => $event['action'],
                    'index' => $event['index'],
                    'human' => $event['human'],
                    'classification' => $classification,
                    'eventId' => $event['eventId'],
                    'ruleId' => $event['ruleId'],
                    'groupId' => $event['groupId'],
                    'objectId' => $event['objectId']
                ];
                $this->SendDebug('DahuaEvent', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' | ' . $this->singleLine($event['raw']), 0);
            }

            if (in_array($action, ['start', 'on'], true)) {
                if (!$event['human']) {
                    continue;
                }
                $active = DahuaHumanTracker::apply($this->getActiveHumanEvents(), $event, time());
                $this->setActiveHumanEvents($active);
                $this->rememberHumanEvent($event);
                $this->refreshPersonAggregate('START ' . $event['code']);
                continue;
            }

            if (in_array($action, ['stop', 'off'], true)) {
                // Ein explizit als Vehicle/Animal/etc. klassifizierter STOP darf keinen
                // parallelen Human-Zustand derselben IVS-Regel löschen. Fehlt die
                // Klassifizierung vollständig, darf der Tracker dagegen einen zuvor
                // bekannten Human-START anhand Code/Index/IDs beenden.
                if ($classification !== null && !$event['human']) {
                    continue;
                }
                $before = $this->getActiveHumanEvents();
                $after = DahuaHumanTracker::apply($before, $event, time());
                if ($after !== $before) {
                    $this->setActiveHumanEvents($after);
                    $this->rememberHumanEvent($event);
                    $this->refreshPersonAggregate('STOP ' . $event['code']);
                }
                continue;
            }

            if ($action === 'pulse' && $event['human']) {
                $until = max($this->ReadAttributeInteger('HumanPulseUntil'), time() + 5);
                $this->WriteAttributeInteger('HumanPulseUntil', $until);
                $this->SetTimerInterval('PersonStateTimer', max(1000, ($until - time()) * 1000));
                $this->rememberHumanEvent($event);
                $this->refreshPersonAggregate('PULSE ' . $event['code']);
            }
        }
    }

    public function PersonStateTimer(): void
    {
        $until = $this->ReadAttributeInteger('HumanPulseUntil');
        if ($until > time()) {
            $this->SetTimerInterval('PersonStateTimer', max(1000, ($until - time()) * 1000));
            return;
        }

        $this->SetTimerInterval('PersonStateTimer', 0);
        if ($until > 0) {
            $this->WriteAttributeInteger('HumanPulseUntil', 0);
            $this->refreshPersonAggregate('PULSE abgelaufen');
        }
    }

    private function refreshPersonAggregate(string $source): void
    {
        $active = $this->getActiveHumanEvents();
        $pulseActive = $this->ReadAttributeInteger('HumanPulseUntil') > time();
        $newState = $active !== [] || $pulseActive;
        $oldState = $this->ReadAttributeBoolean('PersonActive');
        $this->setPersonActive($newState);

        if ($oldState === $newState) {
            return;
        }

        if ($newState) {
            $this->WriteAttributeInteger('OffDue', 0);
            $this->SetTimerInterval('OffTimer', 0);
            if ($this->ReadAttributeInteger('SelfCommandTarget') === 0) {
                // Ein alter noch unbestätigter AUS-Befehl ist bei neuer Person nicht
                // mehr das gewünschte Ziel. Er wird verworfen; nach dem nächsten
                // Personenende darf genau ein neuer AUS-Befehl gesendet werden.
                $this->clearSelfCommand();
            }
            $this->debug('Person', 'AKTIV via ' . $source . ' | aktive Human-Events: ' . count($active));
            $this->attemptAutomaticOn($source);
            return;
        }

        $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
        $this->debug('Person', 'INAKTIV via ' . $source);
        if ($this->lightAutomationEnabled() && $this->ReadAttributeBoolean('AutoOwned')) {
            $this->scheduleOff();
        }
    }

    private function attemptAutomaticOn(string $source): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->ReadAttributeBoolean('PersonActive')) {
            return;
        }
        if (!$this->lightAutomationEnabled()) {
            return;
        }
        if ($this->ReadAttributeBoolean('ManualLockUntilPersonClear')) {
            $this->debug('Licht', 'Keine Automatik-EIN: manuelle Sperre bis Ende der aktuellen Personenerkennung');
            return;
        }
        if (!$this->isNight()) {
            $this->debug('Licht', 'Keine Automatik-EIN: Tageszeit außerhalb Sonnenuntergang/Sonnenaufgang');
            return;
        }

        $light = $this->getLightFeedback();
        if ($light === null) {
            $this->debug('Licht', 'EIN verworfen: echte LCN-Intensity-Rückmeldung nicht verfügbar');
            return;
        }
        if ($light === true) {
            // Licht war bereits an -> nicht Eigentum der Automatik.
            if (!$this->ReadAttributeBoolean('AutoOwned')) {
                $this->debug('Licht', 'Bereits EIN; wird als manuell/fremd betrachtet und später nicht ausgeschaltet');
            }
            return;
        }

        // Eigentum vor dem Request setzen: Rückmeldungen können in IP-Symcon
        // synchron während RequestAction eintreffen. So kann eine sehr schnelle oder
        // sehr späte Intensity-Rückmeldung das Eigentum nicht versehentlich verlieren.
        $this->WriteAttributeBoolean('AutoOwned', true);
        if ($this->switchLight(true)) {
            $this->debug('Licht', 'Automatik EIN via ' . $source);
        } else {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->debug('Licht', 'Automatik EIN fehlgeschlagen; Eigentum verworfen');
        }
    }

    private function clearHumanTracking(string $source): void
    {
        $hadState = $this->ReadAttributeBoolean('PersonActive')
            || $this->getActiveHumanEvents() !== []
            || $this->ReadAttributeInteger('HumanPulseUntil') > 0;

        $this->WriteAttributeString('ActiveHumanEvents', '{}');
        $this->WriteAttributeInteger('HumanPulseUntil', 0);
        $this->SetTimerInterval('PersonStateTimer', 0);
        $this->setPersonActive(false);

        if (!$hadState) {
            return;
        }

        $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
        $this->debug('Person', 'INAKTIV via ' . $source . ' (Streamzustand verworfen)');
        if ($this->lightAutomationEnabled() && $this->ReadAttributeBoolean('AutoOwned')) {
            $this->scheduleOff();
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function getActiveHumanEvents(): array
    {
        $decoded = json_decode($this->ReadAttributeString('ActiveHumanEvents'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,array<string,mixed>> $events */
    private function setActiveHumanEvents(array $events): void
    {
        $encoded = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString('ActiveHumanEvents', $encoded === false ? '{}' : $encoded);
    }

    /** @param array<string,mixed> $event */
    private function rememberHumanEvent(array $event): void
    {
        $value = [
            'time' => time(),
            'code' => $event['code'] ?? '',
            'action' => $event['action'] ?? '',
            'index' => $event['index'] ?? 0,
            'human' => $event['human'] ?? false,
            'classification' => $event['classification'] ?? null,
            'eventId' => $event['eventId'] ?? null,
            'ruleId' => $event['ruleId'] ?? null,
            'groupId' => $event['groupId'] ?? null,
            'objectId' => $event['objectId'] ?? null
        ];
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString('LastHumanEvent', $encoded === false ? '' : $encoded);
    }

    private function scheduleOff(): void
    {
        if (!$this->lightAutomationEnabled()) {
            return;
        }

        $seconds = max(10, $this->ReadPropertyInteger('AfterRunSeconds'));
        $this->WriteAttributeInteger('OffDue', time() + $seconds);
        $this->SetTimerInterval('OffTimer', $seconds * 1000);
        $this->debug('Licht', 'Nachlauf gestartet: ' . $seconds . ' s');
    }

    private function restoreOffTimer(): void
    {
        if (!$this->lightAutomationEnabled()) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->WriteAttributeInteger('OffDue', 0);
            $this->SetTimerInterval('OffTimer', 0);
            return;
        }

        if (!$this->ReadAttributeBoolean('AutoOwned')) {
            return;
        }

        // Nach einem Neustart am Tag darf ein zuvor von der Automatik eingeschaltetes
        // Licht nicht bis zum nächsten Sonnenereignis anbleiben. Der Hardwarebefehl
        // erfolgt bewusst erst über den Timer und niemals direkt in ApplyChanges().
        if (!$this->isNight()) {
            $this->WriteAttributeInteger('OffDue', time() + 1);
            $this->SetTimerInterval('OffTimer', 1000);
            return;
        }

        if ($this->ReadAttributeBoolean('PersonActive')) {
            return;
        }

        $light = $this->getLightFeedback();
        if ($light === false) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->WriteAttributeInteger('OffDue', 0);
            return;
        }

        $due = $this->ReadAttributeInteger('OffDue');
        if ($due <= 0) {
            // Sicherheitsnetz nach Neustart/Update: Automatik-Eigentum darf nie ohne
            // Ausschaltzeitpunkt bestehen, wenn keine Person mehr aktiv ist.
            $seconds = max(10, $this->ReadPropertyInteger('AfterRunSeconds'));
            $due = time() + $seconds;
            $this->WriteAttributeInteger('OffDue', $due);
        }

        $remaining = max(1, $due - time());
        $this->SetTimerInterval('OffTimer', $remaining * 1000);
    }

    public function SunBoundaryTimer(): void
    {
        $this->SetTimerInterval('SunBoundaryTimer', 0);
        if (!$this->lightAutomationEnabled()) {
            return;
        }
        $this->handleSunStateChange('Zeitgrenze');
    }

    private function handleSunStateChange(string $source): void
    {
        if (!$this->lightAutomationEnabled()) {
            $this->setStatusVariable('NightPermission', false);
            $this->SetTimerInterval('SunBoundaryTimer', 0);
            return;
        }

        $night = $this->isNight();
        $this->setStatusVariable('NightPermission', $night);
        $this->debug('Sonne', ($night ? 'Nachtfreigabe AKTIV' : 'Nachtfreigabe GESPERRT') . ' via ' . $source);
        $this->scheduleSunBoundaryTimer();

        if ($night) {
            // Wird eine Person bereits vor Sonnenuntergang erkannt und bleibt aktiv,
            // darf das Licht exakt ab Sonnenuntergang eingeschaltet werden.
            if ($this->ReadAttributeBoolean('PersonActive')) {
                $this->attemptAutomaticOn('Sonnenuntergang');
            }
            return;
        }

        // Sonnenaufgang entzieht die Freigabe. Nur ein von der Automatik selbst
        // eingeschaltetes Licht darf abgeschaltet werden; manuelles Licht bleibt unangetastet.
        if (!$this->ReadAttributeBoolean('AutoOwned')) {
            return;
        }

        $this->WriteAttributeInteger('OffDue', 0);
        $this->SetTimerInterval('OffTimer', 0);
        $light = $this->getLightFeedback();
        if ($light === false) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            return;
        }
        if ($light === null) {
            $this->debug('Licht', 'Sonnenaufgang: AUS zunächst verworfen, echte LCN-Rückmeldung nicht verfügbar; neuer Versuch in 15 s');
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            return;
        }
        if ($this->switchLight(false)) {
            // Eigentum erst nach echter Intensity=0 aufgeben. So bleibt auch bei
            // verzögerter/fehlender Hardwareausführung eine Nachkontrolle aktiv.
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            $this->debug('Licht', 'Automatik AUS wegen Sonnenaufgang angefordert; warte auf echte Intensity=0');
        } else {
            $this->WriteAttributeInteger('OffDue', time() + self::LIGHT_RECHECK_SECONDS);
            $this->SetTimerInterval('OffTimer', self::LIGHT_RECHECK_SECONDS * 1000);
            $this->debug('Licht', 'Sonnenaufgang: AUS-Befehl fehlgeschlagen; neuer Versuch in 15 s');
        }
    }

    private function scheduleSunBoundaryTimer(): void
    {
        if (!$this->lightAutomationEnabled()) {
            $this->SetTimerInterval('SunBoundaryTimer', 0);
            return;
        }

        $sunrise = $this->getSunTimestamp('SunriseVariableID');
        $sunset = $this->getSunTimestamp('SunsetVariableID');
        if ($sunrise === null || $sunset === null) {
            $this->SetTimerInterval('SunBoundaryTimer', 0);
            return;
        }

        $next = NightWindow::nextBoundary(time(), $sunrise, $sunset);
        if ($next === null) {
            $this->SetTimerInterval('SunBoundaryTimer', 0);
            return;
        }

        $milliseconds = max(1000, ($next - time()) * 1000);
        $this->SetTimerInterval('SunBoundaryTimer', $milliseconds);
    }

    private function handleLightFeedbackChange(): void
    {
        if (!$this->lightAutomationEnabled()) {
            return;
        }

        $light = $this->getLightFeedback();
        if ($light === null) {
            return;
        }

        $old = $this->ReadAttributeInteger('LastLightFeedback');
        $this->WriteAttributeInteger('LastLightFeedback', (int) $light);
        if ($old === (int) $light) {
            return;
        }

        $selfUntil = $this->ReadAttributeInteger('SelfCommandUntil');
        $selfTarget = $this->ReadAttributeInteger('SelfCommandTarget');
        if ($selfUntil > 0 && $selfTarget === (int) $light) {
            $this->debug('Licht', 'LCN-Rückmeldung zum eigenen Automatikbefehl: ' . ($light ? 'EIN' : 'AUS')
                . (time() > $selfUntil ? ' (verspätet)' : ''));
            $this->clearSelfCommand();

            // AUS ist erst mit echter Intensity=0 abgeschlossen. Wird diese Bestätigung
            // empfangen, kann das Eigentum sofort und eindeutig beendet werden.
            if (!$light && $this->ReadAttributeBoolean('AutoOwned')) {
                $this->WriteAttributeBoolean('AutoOwned', false);
                $this->WriteAttributeInteger('OffDue', 0);
                $this->SetTimerInterval('OffTimer', 0);
                $this->debug('Licht', 'Automatik AUS durch eigene echte Intensity=0 bestätigt');
            }
            return;
        }

        // Kritischer Fix 0.3.1:
        // Eine verspätete Intensity-Rückmeldung EIN darf ein von der Automatik
        // eingeschaltetes Licht niemals als "manuell" umklassifizieren. Bei LCN kann
        // die echte Rückmeldung je nach Rampen-/Buslaufzeit deutlich nach dem
        // RequestAction eintreffen. Solange AutoOwned bereits TRUE ist und der reale
        // Zustand EIN meldet, bleibt das Eigentum erhalten.
        if ($light && $this->ReadAttributeBoolean('AutoOwned')) {
            $this->debug('Licht', 'LCN-Rückmeldung EIN bestätigt Automatiklicht; Eigentum bleibt erhalten');
            return;
        }

        // Eine echte AUS-Änderung außerhalb des eigenen AUS-Befehls ist dagegen eine
        // klare manuelle/externe Übersteuerung. Ebenso ist ein EIN bei AutoOwned=FALSE
        // fremdes/manuelles Licht und darf später nicht von der Automatik ausgeschaltet werden.
        $this->WriteAttributeBoolean('AutoOwned', false);
        $this->WriteAttributeInteger('OffDue', 0);
        $this->SetTimerInterval('OffTimer', 0);

        if (!$light && $this->ReadAttributeBoolean('PersonActive')) {
            // Benutzer hat trotz laufender Personenerkennung ausgeschaltet.
            // Nicht wieder einschalten, bis diese Erkennung vollständig beendet war.
            $this->WriteAttributeBoolean('ManualLockUntilPersonClear', true);
        }

        $this->debug('Licht', 'Externe/manuelle LCN-Änderung erkannt: ' . ($light ? 'EIN' : 'AUS') . '; Automatik-Eigentum verworfen');
    }

    private function checkPendingLightCommand(): void
    {
        $target = $this->ReadAttributeInteger('SelfCommandTarget');
        $until = $this->ReadAttributeInteger('SelfCommandUntil');
        if (($target !== 0 && $target !== 1) || $until <= 0 || time() <= $until) {
            return;
        }

        $feedback = $this->getLightFeedback();
        if ($feedback !== null && (int) $feedback === $target) {
            $this->debug('Licht', 'Verspätete echte Intensity bestätigt Schaltziel ' . ($target === 1 ? 'EIN' : 'AUS'));
            $this->clearSelfCommand();
            if ($target === 0 && $this->ReadAttributeBoolean('AutoOwned')) {
                $this->WriteAttributeBoolean('AutoOwned', false);
                $this->WriteAttributeInteger('OffDue', 0);
                $this->SetTimerInterval('OffTimer', 0);
            }
            return;
        }

        if ($target === 1) {
            // Ein akzeptierter EIN-Befehl ohne reale EIN-Rückmeldung darf nicht
            // unbegrenzt Eigentum behalten. Sonst könnte ein später manuell
            // eingeschaltetes Licht fälschlich als Automatiklicht gelten und später
            // ausgeschaltet werden. Es wird NICHT automatisch erneut getoggelt.
            $this->clearSelfCommand();
            if ($this->ReadAttributeBoolean('AutoOwned')) {
                $this->WriteAttributeBoolean('AutoOwned', false);
                $this->WriteAttributeInteger('OffDue', 0);
                $this->SetTimerInterval('OffTimer', 0);
            }
            $this->debug('Licht', 'EIN-Befehl nach ' . self::LIGHT_CONFIRM_SECONDS . ' s nicht durch echte Intensity bestätigt; Eigentum sicher verworfen, kein erneutes Toggle');
            return;
        }

        // Bei AUS hat Sicherheit vor Wiederholung Vorrang: ein erneut gesendeter
        // Toggle könnte ein bereits physisch ausgeschaltetes Licht wieder einschalten,
        // wenn nur die Rückmeldung hängt. Deshalb weiter prüfen, aber nicht erneut senden.
        $this->WriteAttributeInteger('SelfCommandUntil', time() + self::LIGHT_CONFIRM_SECONDS);
        $this->debug('Licht', 'AUS-Bestätigung überfällig; kein erneutes Toggle, echte Intensity wird weiter überwacht');
    }

    private function switchLight(bool $on): bool
    {
        if (!$this->lightAutomationEnabled()) {
            return false;
        }

        $commandVar = $this->ReadPropertyInteger('LightCommandVariableID');
        if (!$this->isBooleanVariable($commandVar)) {
            $this->debug('Licht', 'Schaltvariable ungültig: #' . $commandVar);
            return false;
        }
        if (!HasAction($commandVar)) {
            $this->debug('Licht', 'Schaltvariable hat keine Aktion: #' . $commandVar);
            return false;
        }

        $feedback = $this->getLightFeedback();
        if ($feedback !== null && $feedback === $on) {
            $this->clearSelfCommand();
            return true;
        }

        // RequestAction kann bei LCN-Light/Memory-Konzepten intern ein Toggle auslösen.
        // Derselbe akzeptierte Befehl darf deshalb NICHT alle 15 s erneut gesendet werden,
        // nur weil die echte Intensity noch nicht nachgezogen hat. Während des
        // Bestätigungsfensters wird ausschließlich auf Rückmeldung gewartet.
        $pendingTarget = $this->ReadAttributeInteger('SelfCommandTarget');
        $pendingUntil = $this->ReadAttributeInteger('SelfCommandUntil');
        if ($pendingTarget === (int) $on && $pendingUntil > 0) {
            $overdue = time() > $pendingUntil;
            $this->debug('Licht', 'Schaltbefehl ' . ($on ? 'EIN' : 'AUS')
                . ' bereits gesendet; warte auf echte Intensity-Rückmeldung'
                . ($overdue ? ' (Bestätigung überfällig, kein erneutes Toggle)' : ''));
            return true;
        }

        $this->WriteAttributeInteger('SelfCommandTarget', (int) $on);
        $this->WriteAttributeInteger('SelfCommandUntil', time() + self::LIGHT_CONFIRM_SECONDS);

        try {
            $ok = RequestAction($commandVar, $on);
            if (!$ok) {
                $this->clearSelfCommand();
                $this->debug('Licht', 'RequestAction #' . $commandVar . ' lieferte FALSE');
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->clearSelfCommand();
            $this->debug('Licht', 'Schalten fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    private function clearSelfCommand(): void
    {
        $this->WriteAttributeInteger('SelfCommandTarget', -1);
        $this->WriteAttributeInteger('SelfCommandUntil', 0);
    }

    private function setPersonActive(bool $active): void
    {
        $this->WriteAttributeBoolean('PersonActive', $active);
        $this->setStatusVariable('PersonDetected', $active);
    }

    private function lightAutomationEnabled(): bool
    {
        return $this->ReadPropertyBoolean('LightAutomationEnabled');
    }

    private function syncStatusVariables(): void
    {
        $this->setStatusVariable('PersonDetected', $this->ReadAttributeBoolean('PersonActive'));
        $this->setStatusVariable('NightPermission', $this->lightAutomationEnabled() ? $this->isNight() : false);

        try {
            $nightID = $this->GetIDForIdent('NightPermission');
            if ($nightID > 0 && IPS_VariableExists($nightID)) {
                IPS_SetHidden($nightID, !$this->lightAutomationEnabled());
            }
        } catch (Throwable $e) {
            $this->debug('Status', 'Sichtbarkeit Nachtfreigabe konnte nicht gesetzt werden: ' . $e->getMessage());
        }
    }

    private function setStatusVariable(string $ident, bool $value): void
    {
        try {
            $variableID = $this->GetIDForIdent($ident);
            if ($variableID > 0 && IPS_VariableExists($variableID)) {
                SetValueBoolean($variableID, $value);
            }
        } catch (Throwable $e) {
            $this->debug('Status', $ident . ' konnte nicht aktualisiert werden: ' . $e->getMessage());
        }
    }

    private function isNight(): bool
    {
        if (!$this->lightAutomationEnabled()) {
            return false;
        }

        $sunrise = $this->getSunTimestamp('SunriseVariableID');
        $sunset = $this->getSunTimestamp('SunsetVariableID');
        if ($sunrise === null || $sunset === null) {
            return false;
        }

        return NightWindow::isNight(time(), $sunrise, $sunset);
    }

    private function getSunTimestamp(string $property): ?int
    {
        $varID = $this->ReadPropertyInteger($property);
        if (!$this->isIntegerVariable($varID)) {
            return null;
        }
        $value = (int) GetValue($varID);
        return $value > 0 ? $value : null;
    }

    private function getLightFeedback(): ?bool
    {
        if (!$this->lightAutomationEnabled()) {
            return null;
        }

        $varID = $this->ReadPropertyInteger('LightFeedbackVariableID');
        if (!$this->isNumericVariable($varID)) {
            return null;
        }

        // Die echte Ausgangsrückmeldung ist die native LCN-Intensität.
        // 0 bedeutet AUS, jeder Wert > 0 bedeutet EIN. Unterstützt werden
        // Integer und Float, damit unterschiedliche LCN-/Symcon-Instanzen
        // ohne Sonderfall verwendet werden können.
        return ((float) GetValue($varID)) > 0.0;
    }

    private function getLightFeedbackRaw(): int|float|null
    {
        $varID = $this->ReadPropertyInteger('LightFeedbackVariableID');
        if (!$this->isNumericVariable($varID)) {
            return null;
        }

        $variable = IPS_GetVariable($varID);
        $value = GetValue($varID);
        return ((int) $variable['VariableType'] === 1) ? (int) $value : (float) $value;
    }

    private function isBooleanVariable(int $id): bool
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        return isset($variable['VariableType']) && (int) $variable['VariableType'] === 0;
    }

    private function isIntegerVariable(int $id): bool
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        return isset($variable['VariableType']) && (int) $variable['VariableType'] === 1;
    }

    private function isNumericVariable(int $id): bool
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        if (!isset($variable['VariableType'])) {
            return false;
        }
        $type = (int) $variable['VariableType'];
        return $type === 1 || $type === 2;
    }

    private function updateVariableSubscription(string $attribute, int $newID): void
    {
        $oldID = $this->ReadAttributeInteger($attribute);
        if ($oldID > 0 && $oldID !== $newID) {
            try {
                $this->UnregisterMessage($oldID, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
                // Alte Variable kann inzwischen gelöscht worden sein.
            }
        }

        if ($newID > 0) {
            try {
                $this->RegisterMessage($newID, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
                $this->debug('Subscription', 'Variable #' . $newID . ' konnte nicht registriert werden: ' . $e->getMessage());
                $newID = 0;
            }
        }
        $this->WriteAttributeInteger($attribute, $newID);
    }

    private function updateParentSubscription(int $newID): void
    {
        $oldID = $this->ReadAttributeInteger('RegisteredParentID');
        if ($oldID > 0 && $oldID !== $newID) {
            try {
                $this->UnregisterMessage($oldID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
                // Alte Parent-Instanz kann inzwischen entfernt worden sein.
            }
        }

        if ($newID > 0 && IPS_InstanceExists($newID)) {
            try {
                $this->RegisterMessage($newID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
                $this->debug('Subscription', 'Parent #' . $newID . ' konnte nicht registriert werden: ' . $e->getMessage());
                $newID = 0;
            }
        } else {
            $newID = 0;
        }
        $this->WriteAttributeInteger('RegisteredParentID', $newID);
    }

    private function cameraConfigurationReady(): bool
    {
        return trim($this->ReadPropertyString('CameraHost')) !== ''
            && $this->ReadPropertyInteger('CameraPort') > 0
            && trim($this->ReadPropertyString('Username')) !== '';
    }

    private function getParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int) ($instance['ConnectionID'] ?? 0);
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function debug(string $topic, string $message): void
    {
        $this->SendDebug($topic, $message, 0);
    }
}
