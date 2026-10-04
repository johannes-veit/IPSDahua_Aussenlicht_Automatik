<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/NightWindow.php';

class AussenlichtAutomatik2 extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const VM_UPDATE_ID = 10603;
    private const IM_CHANGESTATUS_ID = 10505;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyString('CameraHost', '192.168.107.110');
        $this->RegisterPropertyInteger('CameraPort', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        // Nachtfreigabe ausschließlich über astronomische Zeitpunkte aus Location Control.
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);

        // Legacy-Eigenschaften aus 0.1.0 bleiben nur für ein sauberes Update registriert.
        // Sie werden nicht mehr ausgewertet und erscheinen nicht mehr im Formular.
        $this->RegisterPropertyInteger('DayVariableID', 0);
        $this->RegisterPropertyBoolean('DarkWhenDayVariableFalse', true);

        // Projektwerte Terrasse:
        // 48535 = Status der LCNLight-Instanz EG Terrasse (26459), über deren Aktion geschaltet wird.
        // 44137 = native LCN-Rückmeldung Ausgang 1 der LCN-Ausgangsinstanz 35859.
        $this->RegisterPropertyInteger('LightCommandVariableID', 48535);
        $this->RegisterPropertyInteger('LightFeedbackVariableID', 44137);
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

        // Sichtbare, read-only Diagnosevariablen. Keine Aktion freigeben.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('NightPermission', 'Nachtfreigabe', '~Switch', 20);

        $this->RegisterTimer('OffTimer', 0, 'ALA2_OffTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SunBoundaryTimer', 0, 'ALA2_SunBoundaryTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('HandshakeTimer', 0, 'ALA2_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'ALA2_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'ALA2_Watchdog($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Auch bei einem Update einer bereits vorhandenen Instanz anlegen.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('NightPermission', 'Nachtfreigabe', '~Switch', 20);
        $this->syncStatusVariables();

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
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

        // 0.1.0 verwendete noch "Ist es Tag". Diese Variable ist bewusst nicht mehr Teil der Logik.
        $legacyDayVar = $this->ReadPropertyInteger('DayVariableID');
        if ($this->isBooleanVariable($legacyDayVar)) {
            try {
                $this->UnregisterMessage($legacyDayVar, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
                // Bei einer Erstinstallation existiert keine alte Registrierung.
            }
        }

        $sunriseVar = $this->ReadPropertyInteger('SunriseVariableID');
        $sunsetVar = $this->ReadPropertyInteger('SunsetVariableID');
        if ($this->isIntegerVariable($sunriseVar)) {
            $this->RegisterMessage($sunriseVar, self::VM_UPDATE_ID);
        }
        if ($this->isIntegerVariable($sunsetVar)) {
            $this->RegisterMessage($sunsetVar, self::VM_UPDATE_ID);
        }

        $feedbackVar = $this->ReadPropertyInteger('LightFeedbackVariableID');
        if ($this->isBooleanVariable($feedbackVar)) {
            $this->RegisterMessage($feedbackVar, self::VM_UPDATE_ID);
            $current = (bool) GetValue($feedbackVar);
            $last = $this->ReadAttributeInteger('LastLightFeedback');
            if ($last !== -1 && $last !== (int) $current && $this->ReadAttributeBoolean('AutoOwned')) {
                // Während Symcon/Modul inaktiv war, wurde der reale Lichtzustand verändert.
                // Sicherheit: Eigentum der Automatik verwerfen, niemals blind später ausschalten.
                $this->WriteAttributeBoolean('AutoOwned', false);
                $this->WriteAttributeInteger('OffDue', 0);
            }
            $this->WriteAttributeInteger('LastLightFeedback', (int) $current);
        }

        $parentID = $this->getParentID();
        if ($parentID > 0) {
            $this->RegisterMessage($parentID, self::IM_CHANGESTATUS_ID);
        }

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
        return json_encode([
            'Host' => $this->ReadPropertyString('CameraHost'),
            'Port' => $this->ReadPropertyInteger('CameraPort'),
            'Open' => $this->ReadPropertyBoolean('Enabled')
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

            if ((int) $SenderID === $sunriseVar || (int) $SenderID === $sunsetVar) {
                $this->handleSunStateChange('Location Control');
                return;
            }

            if ((int) $SenderID === $feedbackVar) {
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
                $this->WriteAttributeBoolean('Streaming', false);
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
        $this->WriteAttributeBoolean('Streaming', false);
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
            'NightPermission' => $this->isNight(),
            'Sunrise' => $this->getSunTimestamp('SunriseVariableID'),
            'Sunset' => $this->getSunTimestamp('SunsetVariableID'),
            'PersonActive' => $this->ReadAttributeBoolean('PersonActive'),
            'AutoOwned' => $this->ReadAttributeBoolean('AutoOwned'),
            'ManualLockUntilPersonClear' => $this->ReadAttributeBoolean('ManualLockUntilPersonClear'),
            'LightFeedback' => $this->getLightFeedback(),
            'OffDue' => $this->ReadAttributeInteger('OffDue'),
            'Streaming' => $this->ReadAttributeBoolean('Streaming'),
            'AuthPending' => $this->ReadAttributeBoolean('AuthPending'),
            'AuthBlocked' => $this->ReadAttributeBoolean('AuthBlocked'),
            'SocketRestartStage' => $this->ReadAttributeInteger('SocketRestartStage'),
            'LastCameraRx' => $this->ReadAttributeInteger('LastCameraRx')
        ];
        $this->SendDebug('State', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    public function OffTimer(): void
    {
        $this->SetTimerInterval('OffTimer', 0);
        $this->WriteAttributeInteger('OffDue', 0);

        // Nachts hält eine noch aktive Person das Licht an. Nach Sonnenaufgang
        // gilt die UND-Bedingung nicht mehr und ein Automatiklicht darf aus.
        if ($this->ReadAttributeBoolean('PersonActive') && $this->isNight()) {
            return;
        }
        if (!$this->ReadAttributeBoolean('AutoOwned')) {
            return;
        }

        $light = $this->getLightFeedback();
        if ($light === false) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            return;
        }
        if ($light === null) {
            if (!$this->isNight()) {
                $this->debug('Licht', 'Tagesfreigabe: AUS zunächst verworfen, echte LCN-Rückmeldung nicht verfügbar; neuer Versuch in 15 s');
                $this->WriteAttributeInteger('OffDue', time() + 15);
                $this->SetTimerInterval('OffTimer', 15000);
            } else {
                $this->debug('Licht', 'AUS verworfen: echte LCN-Rückmeldung nicht verfügbar');
            }
            return;
        }

        if ($this->switchLight(false)) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->debug('Licht', $this->isNight() ? 'Automatik AUS nach Nachlauf' : 'Automatik AUS: Nachtfreigabe beendet');
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
            'User-Agent: IP-Symcon-AussenlichtAutomatik2/0.1.3',
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
            if ($this->ReadPropertyBoolean('DebugEvents')) {
                $this->SendDebug('DahuaEvent', $event['raw'], 0);
            }
            if (!$event['human']) {
                continue;
            }

            $action = strtolower($event['action']);
            if ($action === 'start' || $action === 'on') {
                $this->handlePersonStart($event['code']);
            } elseif ($action === 'stop' || $action === 'off') {
                $this->handlePersonStop($event['code']);
            } elseif ($action === 'pulse') {
                $this->handlePersonPulse($event['code']);
            }
        }
    }

    private function handlePersonStart(string $source): void
    {
        $this->setPersonActive(true);
        $this->WriteAttributeInteger('OffDue', 0);
        $this->SetTimerInterval('OffTimer', 0);
        $this->debug('Person', 'START via ' . $source);

        if (!$this->ReadPropertyBoolean('Enabled')) {
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
            $this->debug('Licht', 'EIN verworfen: echte LCN-Rückmeldung nicht verfügbar');
            return;
        }
        if ($light === true) {
            // Licht war bereits an -> nicht Eigentum der Automatik.
            if (!$this->ReadAttributeBoolean('AutoOwned')) {
                $this->debug('Licht', 'Bereits EIN; wird als manuell/fremd betrachtet und später nicht ausgeschaltet');
            }
            return;
        }

        if ($this->switchLight(true)) {
            $this->WriteAttributeBoolean('AutoOwned', true);
            $this->debug('Licht', 'Automatik EIN');
        }
    }

    private function handlePersonStop(string $source): void
    {
        $this->setPersonActive(false);
        $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
        $this->debug('Person', 'STOP via ' . $source);

        if ($this->ReadAttributeBoolean('AutoOwned')) {
            $this->scheduleOff();
        }
    }

    private function handlePersonPulse(string $source): void
    {
        $this->debug('Person', 'PULSE via ' . $source);
        $this->handlePersonStart($source);
        $this->setPersonActive(false);
        $this->WriteAttributeBoolean('ManualLockUntilPersonClear', false);
        if ($this->ReadAttributeBoolean('AutoOwned')) {
            $this->scheduleOff();
        }
    }

    private function scheduleOff(): void
    {
        $seconds = max(10, $this->ReadPropertyInteger('AfterRunSeconds'));
        $this->WriteAttributeInteger('OffDue', time() + $seconds);
        $this->SetTimerInterval('OffTimer', $seconds * 1000);
        $this->debug('Licht', 'Nachlauf gestartet: ' . $seconds . ' s');
    }

    private function restoreOffTimer(): void
    {
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

        $due = $this->ReadAttributeInteger('OffDue');
        if ($due <= 0) {
            return;
        }

        $remaining = max(1, $due - time());
        $this->SetTimerInterval('OffTimer', $remaining * 1000);
    }

    public function SunBoundaryTimer(): void
    {
        $this->SetTimerInterval('SunBoundaryTimer', 0);
        $this->handleSunStateChange('Zeitgrenze');
    }

    private function handleSunStateChange(string $source): void
    {
        $night = $this->isNight();
        $this->setStatusVariable('NightPermission', $night);
        $this->debug('Sonne', ($night ? 'Nachtfreigabe AKTIV' : 'Nachtfreigabe GESPERRT') . ' via ' . $source);
        $this->scheduleSunBoundaryTimer();

        if ($night) {
            // Wird eine Person bereits vor Sonnenuntergang erkannt und bleibt aktiv,
            // darf das Licht exakt ab Sonnenuntergang eingeschaltet werden.
            if ($this->ReadAttributeBoolean('PersonActive')) {
                $this->handlePersonStart('Sonnenuntergang');
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
            $this->WriteAttributeInteger('OffDue', time() + 15);
            $this->SetTimerInterval('OffTimer', 15000);
            return;
        }
        if ($this->switchLight(false)) {
            $this->WriteAttributeBoolean('AutoOwned', false);
            $this->debug('Licht', 'Automatik AUS: Sonnenaufgang beendet Nachtfreigabe');
        }
    }

    private function scheduleSunBoundaryTimer(): void
    {
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
        if (time() <= $selfUntil && $selfTarget === (int) $light) {
            $this->debug('Licht', 'LCN-Rückmeldung zum eigenen Automatikbefehl: ' . ($light ? 'EIN' : 'AUS'));
            return;
        }

        // Jede echte Zustandsänderung außerhalb des erwarteten Automatikbefehls hat Vorrang.
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

    private function switchLight(bool $on): bool
    {
        $commandVar = $this->ReadPropertyInteger('LightCommandVariableID');
        if (!$this->isBooleanVariable($commandVar)) {
            $this->debug('Licht', 'Schaltvariable ungültig: #' . $commandVar);
            return false;
        }

        $feedback = $this->getLightFeedback();
        if ($feedback !== null && $feedback === $on) {
            return true;
        }

        $this->WriteAttributeInteger('SelfCommandTarget', (int) $on);
        $this->WriteAttributeInteger('SelfCommandUntil', time() + 10);

        try {
            $ok = RequestAction($commandVar, $on);
            if (!$ok) {
                $this->debug('Licht', 'RequestAction #' . $commandVar . ' lieferte FALSE');
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->debug('Licht', 'Schalten fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    private function setPersonActive(bool $active): void
    {
        $this->WriteAttributeBoolean('PersonActive', $active);
        $this->setStatusVariable('PersonDetected', $active);
    }

    private function syncStatusVariables(): void
    {
        $this->setStatusVariable('PersonDetected', $this->ReadAttributeBoolean('PersonActive'));
        $this->setStatusVariable('NightPermission', $this->isNight());
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
        $varID = $this->ReadPropertyInteger('LightFeedbackVariableID');
        if (!$this->isBooleanVariable($varID)) {
            return null;
        }
        return (bool) GetValue($varID);
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
