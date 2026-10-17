<?php

declare(strict_types=1);

/*
 * Vollständige Laufzeitsimulation ohne IP-Symcon-Kernel.
 * Simuliert insbesondere eine toggle-/Memory-basierte Lichtaktion, bei der ein
 * doppelt gesendetes AUS das Licht wieder EIN schalten würde.
 */

$GLOBALS['simVars'] = [];
$GLOBALS['simInstances'] = [900 => ['ConnectionID' => 0, 'InstanceStatus' => 102, 'props' => []]];
$GLOBALS['simActions'] = [];
$GLOBALS['simHardwareLight'] = false;
$GLOBALS['simActionMode'] = 'toggle';
$GLOBALS['simHidden'] = [];

class IPSModule
{
    public int $InstanceID = 1000;
    public array $props = [];
    public array $attrs = [];
    public array $buffers = [];
    public array $timers = [];
    public array $messages = [];
    public array $idents = [];
    public array $debug = [];

    public function __construct()
    {
        $GLOBALS['simInstances'][$this->InstanceID] = ['ConnectionID' => 900, 'InstanceStatus' => 102, 'props' => []];
    }

    public function Create(): void {}
    public function ApplyChanges(): void {}

    public function RegisterPropertyBoolean(string $name, bool $default): void { if (!array_key_exists($name, $this->props)) $this->props[$name] = $default; }
    public function RegisterPropertyString(string $name, string $default): void { if (!array_key_exists($name, $this->props)) $this->props[$name] = $default; }
    public function RegisterPropertyInteger(string $name, int $default): void { if (!array_key_exists($name, $this->props)) $this->props[$name] = $default; }
    public function ReadPropertyBoolean(string $name): bool { return (bool) $this->props[$name]; }
    public function ReadPropertyString(string $name): string { return (string) $this->props[$name]; }
    public function ReadPropertyInteger(string $name): int { return (int) $this->props[$name]; }

    public function RegisterAttributeBoolean(string $name, bool $default): void { if (!array_key_exists($name, $this->attrs)) $this->attrs[$name] = $default; }
    public function RegisterAttributeInteger(string $name, int $default): void { if (!array_key_exists($name, $this->attrs)) $this->attrs[$name] = $default; }
    public function RegisterAttributeString(string $name, string $default): void { if (!array_key_exists($name, $this->attrs)) $this->attrs[$name] = $default; }
    public function ReadAttributeBoolean(string $name): bool { return (bool) $this->attrs[$name]; }
    public function ReadAttributeInteger(string $name): int { return (int) $this->attrs[$name]; }
    public function ReadAttributeString(string $name): string { return (string) $this->attrs[$name]; }
    public function WriteAttributeBoolean(string $name, bool $value): void { $this->attrs[$name] = $value; }
    public function WriteAttributeInteger(string $name, int $value): void { $this->attrs[$name] = $value; }
    public function WriteAttributeString(string $name, string $value): void { $this->attrs[$name] = $value; }

    public function RegisterVariableBoolean(string $ident, string $name, string $profile, int $position): void
    {
        if (!isset($this->idents[$ident])) {
            $id = 2000 + count($this->idents);
            $this->idents[$ident] = $id;
            $GLOBALS['simVars'][$id] = ['type' => 0, 'value' => false, 'action' => false];
        }
    }

    public function GetIDForIdent(string $ident): int { return $this->idents[$ident] ?? 0; }
    public function RegisterTimer(string $name, int $interval, string $script): void { $this->timers[$name] = $interval; }
    public function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    public function RequireParent(string $guid): void {}
    public function SetBuffer(string $name, string $value): void { $this->buffers[$name] = $value; }
    public function GetBuffer(string $name): string { return $this->buffers[$name] ?? ''; }
    public function RegisterMessage(int $id, int $message): void { $this->messages[$id . ':' . $message] = true; }
    public function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id . ':' . $message]); }
    public function SendDataToParent(string $json): bool { return true; }
    public function SendDebug(string $topic, string $message, int $format): void { $this->debug[] = [$topic, $message]; }
}

function IPS_VariableExists(int $id): bool { return isset($GLOBALS['simVars'][$id]); }
function IPS_GetVariable(int $id): array { return ['VariableType' => $GLOBALS['simVars'][$id]['type']]; }
function GetValue(int $id): mixed { return $GLOBALS['simVars'][$id]['value']; }
function GetValueBoolean(int $id): bool { return (bool) $GLOBALS['simVars'][$id]['value']; }
function SetValueBoolean(int $id, bool $value): void { $GLOBALS['simVars'][$id]['value'] = $value; }
function IPS_SetHidden(int $id, bool $hidden): void { $GLOBALS['simHidden'][$id] = $hidden; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['simInstances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['simInstances'][$id] ?? ['ConnectionID' => 0, 'InstanceStatus' => 0]; }
function IPS_SetProperty(int $id, string $name, mixed $value): void
{
    $GLOBALS['simInstances'][$id]['props'][$name] = $value;
    if ($name === 'Open') {
        $GLOBALS['simInstances'][$id]['InstanceStatus'] = $value ? 102 : 104;
    }
}
function IPS_ApplyChanges(int $id): void {}
function HasAction(int $id): bool { return (bool) ($GLOBALS['simVars'][$id]['action'] ?? false); }
function RequestAction(int $id, mixed $value): bool
{
    $GLOBALS['simActions'][] = [$id, (bool) $value];
    if ($GLOBALS['simActionMode'] === 'throw') {
        throw new RuntimeException('simulierter Aktionsfehler');
    }
    if ($GLOBALS['simActionMode'] === 'fail') {
        return false;
    }
    // Kritischer Praxisfall: die ausgewählte Boolean-Aktion kann intern einen
    // LCN-KURZ-/Memory-Tastendruck auslösen und ist damit technisch toggle-basiert.
    $GLOBALS['simHardwareLight'] = !$GLOBALS['simHardwareLight'];
    $GLOBALS['simVars'][$id]['value'] = (bool) $value;
    return true;
}

require_once __DIR__ . '/../AussenlichtAutomatik2/module.php';

function failSim(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSim(bool $condition, string $message): void
{
    if (!$condition) failSim($message);
}

function makeModule(bool $lightAutomation = true): AussenlichtAutomatik2
{
    $m = new AussenlichtAutomatik2();
    $m->Create();
    $now = time();

    $GLOBALS['simVars'][1] = ['type' => 1, 'value' => $now + 3600, 'action' => false];   // nächstes Ereignis Sunrise -> Nacht
    $GLOBALS['simVars'][2] = ['type' => 1, 'value' => $now + 36000, 'action' => false];  // Sunset später
    $GLOBALS['simVars'][3] = ['type' => 0, 'value' => false, 'action' => true];           // Schaltvariable
    $GLOBALS['simVars'][4] = ['type' => 1, 'value' => 0, 'action' => false];              // echte Intensity

    $m->props['Enabled'] = true;
    $m->props['CameraHost'] = '192.0.2.10';
    $m->props['CameraPort'] = 80;
    $m->props['Username'] = 'test';
    $m->props['Password'] = 'secret';
    $m->props['LightAutomationEnabled'] = $lightAutomation;
    $m->props['SunriseVariableID'] = 1;
    $m->props['SunsetVariableID'] = 2;
    $m->props['LightCommandVariableID'] = 3;
    $m->props['LightFeedbackVariableID'] = 4;
    $m->props['AfterRunSeconds'] = 180;
    $m->ApplyChanges();
    $m->WriteAttributeBoolean('Streaming', true);
    // HTTP-200-Stream und kürzlich empfangener Heartbeat gehören zu einer
    // gesunden Kameraverbindung; andernfalls ist die AUS-Vorabprüfung korrekt
    // vorsichtig und würde die alten Licht-Tests zunächst verzögern.
    $m->WriteAttributeInteger('LastCameraRx', time());
    return $m;
}

function resetActions(bool $hardware = false, int $feedback = 0): void
{
    $GLOBALS['simActions'] = [];
    $GLOBALS['simActionMode'] = 'toggle';
    $GLOBALS['simHardwareLight'] = $hardware;
    $GLOBALS['simVars'][3]['value'] = $hardware;
    $GLOBALS['simVars'][4]['value'] = $feedback;
}

function feedback(AussenlichtAutomatik2 $m, int $value): void
{
    $old = (int) $GLOBALS['simVars'][4]['value'];
    $GLOBALS['simVars'][4]['value'] = $value;
    $m->MessageSink(time(), 4, 10603, [$value, $old !== $value, $old]);
}

function event(AussenlichtAutomatik2 $m, string $action): void
{
    $m->ReceiveData(json_encode(['Buffer' => "Code=SmartMotionHuman;action={$action};index=0\r\n"]));
}

// 1) Standardablauf Nacht: START -> EIN -> STOP -> Nachlauf -> AUS.
$m = makeModule(true);
resetActions(false, 0);
event($m, 'Start');
assertSim(count($GLOBALS['simActions']) === 1 && $GLOBALS['simActions'][0][1] === true, 'Person START muss genau einen EIN-Befehl senden');
assertSim($GLOBALS['simHardwareLight'] === true, 'simulierte Hardware muss nach EIN aktiv sein');
assertSim($m->ReadAttributeBoolean('AutoOwned'), 'Automatik muss Eigentum nach eigenem EIN halten');

feedback($m, 100);
assertSim($m->ReadAttributeBoolean('AutoOwned'), 'echte Intensity=EIN darf Eigentum nicht löschen');

event($m, 'Stop');
assertSim(($m->timers['OffTimer'] ?? 0) === 180000, 'STOP muss 180-s-Nachlauf starten');

// Zeitpunkt gezielt vorspulen: kein reales Warten nötig.
$m->WriteAttributeInteger('OffDue', time() - 1);
// Ablauf simulieren: erster AUS-Request toggelt Hardware korrekt AUS, Feedback bleibt absichtlich verzögert EIN.
$m->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2 && $GLOBALS['simActions'][1][1] === false, 'Nachlauf muss genau einen AUS-Befehl senden');
assertSim($GLOBALS['simHardwareLight'] === false, 'erster AUS-Befehl muss simulierte Hardware ausschalten');

// 0.3.1-Regression: zweiter 15-s-Prüflauf darf NICHT erneut RequestAction(false) auslösen.
$m->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2, 'verzögerte Intensity darf keinen doppelten AUS-/Toggle-Befehl erzeugen');
assertSim($GLOBALS['simHardwareLight'] === false, 'verzögerte Rückmeldung darf Licht nicht wieder EIN toggeln');

// Auch nach Überschreiten des erwarteten Bestätigungsfensters darf ein bereits
// akzeptierter identischer Toggle nicht automatisch wiederholt werden.
$m->WriteAttributeInteger('SelfCommandUntil', time() - 1);
$m->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2, 'überfällige Rückmeldung darf keinen erneuten identischen Toggle senden');
assertSim($GLOBALS['simHardwareLight'] === false, 'überfällige Rückmeldung darf physisches Licht nicht wieder EIN toggeln');

feedback($m, 0);
assertSim(!$m->ReadAttributeBoolean('AutoOwned'), 'echte Intensity=0 muss Automatik-Eigentum sofort beenden');
assertSim(($m->timers['OffTimer'] ?? -1) === 0, 'bestätigtes AUS muss Nachlauf-Timer stoppen');

// 2) Bereits manuell eingeschaltetes Licht darf weder übernommen noch ausgeschaltet werden.
resetActions(true, 100);
$m->WriteAttributeInteger('LastLightFeedback', 1);
event($m, 'Start');
assertSim(count($GLOBALS['simActions']) === 0, 'bereits manuell EIN darf keinen Automatik-EIN-Befehl erzeugen');
assertSim(!$m->ReadAttributeBoolean('AutoOwned'), 'manuelles Vor-EIN darf nicht AutoOwned werden');
event($m, 'Stop');
assertSim(count($GLOBALS['simActions']) === 0, 'manuelles Vor-EIN darf nach Person STOP nicht ausgeschaltet werden');

// 3) Manuelles AUS während Person aktiv: Sperre bis Person vollständig beendet.
resetActions(false, 0);
$m->WriteAttributeInteger('LastLightFeedback', 0);
event($m, 'Start');
assertSim(count($GLOBALS['simActions']) === 1, 'neuer START muss EIN senden');
feedback($m, 100);
// Benutzer schaltet real AUS.
$GLOBALS['simHardwareLight'] = false;
feedback($m, 0);
assertSim(!$m->ReadAttributeBoolean('AutoOwned'), 'manuelles AUS muss AutoOwned verwerfen');
assertSim($m->ReadAttributeBoolean('ManualLockUntilPersonClear'), 'manuelles AUS bei aktiver Person muss Sperre setzen');
// Ein weiterer START derselben laufenden Erkennung darf nicht wieder einschalten.
event($m, 'Start');
assertSim(count($GLOBALS['simActions']) === 1, 'manuelle Sperre muss Wieder-EIN während derselben Person verhindern');
event($m, 'Stop');
assertSim(!$m->ReadAttributeBoolean('ManualLockUntilPersonClear'), 'Person STOP muss manuelle Sperre lösen');

// 4) Sonnenaufgang bei automatik-eigenem Licht: genau ein AUS trotz verzögerter Rückmeldung.
resetActions(false, 0);
$m->WriteAttributeInteger('LastLightFeedback', 0);
event($m, 'Start');
feedback($m, 100);
assertSim($m->ReadAttributeBoolean('AutoOwned'), 'Vor Sonnenaufgang muss AutoOwned aktiv sein');
// Auf Tag umstellen: nächstes Ereignis Sunset liegt vor Sunrise.
$now = time();
$GLOBALS['simVars'][1]['value'] = $now + 36000;
$GLOBALS['simVars'][2]['value'] = $now + 3600;
$m->MessageSink(time(), 1, 10603, [$GLOBALS['simVars'][1]['value'], true, 0]);
$afterSunriseActions = count($GLOBALS['simActions']);
assertSim($afterSunriseActions === 2 && end($GLOBALS['simActions'])[1] === false, 'Sonnenaufgang muss einen AUS-Befehl senden');
$m->OffTimer();
assertSim(count($GLOBALS['simActions']) === $afterSunriseActions, 'Sonnenaufgang-AUS darf bei verzögerter Rückmeldung nicht doppelt gesendet werden');
feedback($m, 0);
assertSim(!$m->ReadAttributeBoolean('AutoOwned'), 'Sonnenaufgang-AUS muss mit Intensity=0 abgeschlossen werden');

// 5) Wechsel der Licht-I/O in bestehender Instanz darf Eigentum nicht auf neue Lampe übertragen.
$GLOBALS['simVars'][5] = ['type' => 0, 'value' => false, 'action' => true];
$GLOBALS['simVars'][6] = ['type' => 1, 'value' => 0, 'action' => false];
$m->WriteAttributeBoolean('AutoOwned', true);
$m->WriteAttributeInteger('OffDue', time() + 100);
$m->props['LightCommandVariableID'] = 5;
$m->props['LightFeedbackVariableID'] = 6;
$m->ApplyChanges();
assertSim(!$m->ReadAttributeBoolean('AutoOwned'), 'I/O-Wechsel muss altes AutoOwned verwerfen');
assertSim($m->ReadAttributeInteger('OffDue') === 0, 'I/O-Wechsel muss alten Ausschaltzeitpunkt verwerfen');

// 6) Reine Personenerkennung darf niemals Lichtaktionen auslösen.
$m2 = makeModule(false);
resetActions(false, 0);
event($m2, 'Start');
assertSim($m2->ReadAttributeBoolean('PersonActive'), 'reiner Erkennungsmodus muss Person erkennen');
assertSim(count($GLOBALS['simActions']) === 0, 'reiner Erkennungsmodus darf keinen Lichtbefehl senden');

// 7) Streamverlust setzt Person sicher zurück.
$m2->WriteAttributeBoolean('Streaming', true);
$m2->MessageSink(time(), 900, 10505, [104]);
assertSim(!$m2->ReadAttributeBoolean('PersonActive'), 'Streamverlust muss PersonActive zurücksetzen');

// 8) Pending-Befehl muss bei ApplyChanges gelöscht werden.
$m->WriteAttributeInteger('SelfCommandTarget', 1);
$m->WriteAttributeInteger('SelfCommandUntil', time() + 60);
$m->ApplyChanges();
assertSim($m->ReadAttributeInteger('SelfCommandTarget') === -1 && $m->ReadAttributeInteger('SelfCommandUntil') === 0, 'ApplyChanges muss Pending-Schaltbefehl löschen');


// 9) Unbestätigtes EIN darf nach Ablauf der Bestätigungsfrist kein dauerhaftes
// AutoOwned hinterlassen und darf nicht automatisch erneut getoggelt werden.
$m3 = makeModule(true);
resetActions(false, 0);
event($m3, 'Start');
assertSim(count($GLOBALS['simActions']) === 1 && $m3->ReadAttributeBoolean('AutoOwned'), 'unbestätigtes EIN beginnt mit AutoOwned');
$m3->WriteAttributeInteger('SelfCommandUntil', time() - 1);
// Hardware für diesen Test wieder AUS setzen: angenommen, der akzeptierte Befehl wurde real nicht ausgeführt.
$GLOBALS['simHardwareLight'] = false;
$GLOBALS['simVars'][4]['value'] = 0;
$m3->Watchdog();
assertSim(!$m3->ReadAttributeBoolean('AutoOwned'), 'überfälliges unbestätigtes EIN muss AutoOwned verwerfen');
assertSim($m3->ReadAttributeInteger('SelfCommandTarget') === -1, 'überfälliges unbestätigtes EIN muss Pending-Ziel löschen');
assertSim(count($GLOBALS['simActions']) === 1, 'überfälliges unbestätigtes EIN darf nicht automatisch erneut getoggelt werden');

// 10) Neue Person verwirft einen alten noch unbestätigten AUS-Pending-Zustand.
$m4 = makeModule(true);
resetActions(false, 0);
event($m4, 'Start');
feedback($m4, 100);
event($m4, 'Stop');
$m4->WriteAttributeInteger('OffDue', time() - 1);
$m4->OffTimer();
assertSim($m4->ReadAttributeInteger('SelfCommandTarget') === 0, 'AUS muss als Pending-Ziel geführt werden');
// Rückmeldung bleibt absichtlich EIN; neue Person kommt vor Bestätigung.
event($m4, 'Start');
assertSim($m4->ReadAttributeInteger('SelfCommandTarget') === -1, 'neue Person muss alten AUS-Pending-Zustand verwerfen');


// 11) Nur tatsächlich fehlgeschlagene RequestAction darf beim nächsten Prüflauf erneut versucht werden.
$m5 = makeModule(true);
resetActions(true, 100);
$m5->WriteAttributeBoolean('AutoOwned', true);
$m5->WriteAttributeBoolean('PersonActive', false);
$GLOBALS['simActionMode'] = 'fail';
$m5->WriteAttributeInteger('OffDue', time() - 1);
$m5->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1, 'fehlgeschlagenes AUS muss einen Versuch protokollieren');
assertSim($m5->ReadAttributeInteger('SelfCommandTarget') === -1, 'fehlgeschlagener RequestAction muss Pending-Ziel löschen');
$GLOBALS['simActionMode'] = 'toggle';
$m5->WriteAttributeInteger('OffDue', time() - 1); // 15-s-Recheck ist abgelaufen
$m5->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2, 'nach echtem RequestAction-Fehler muss kontrollierter neuer Versuch möglich sein');
assertSim($GLOBALS['simHardwareLight'] === false, 'zweiter erfolgreicher AUS-Versuch muss Hardware ausschalten');

// 12) Person wird am Tag erkannt und bleibt bis Sonnenuntergang aktiv: erst ab Sunset einschalten.
$m6 = makeModule(true);
resetActions(false, 0);
$now = time();
$GLOBALS['simVars'][1]['value'] = $now + 36000; // Sunrise später als Sunset -> Tag
$GLOBALS['simVars'][2]['value'] = $now + 3600;
$m6->ApplyChanges();
$m6->WriteAttributeBoolean('Streaming', true);
event($m6, 'Start');
assertSim(count($GLOBALS['simActions']) === 0, 'Person am Tag darf Licht nicht einschalten');
// Sunset erreicht / Location Control setzt Sunset auf morgen; Sunrise ist nun das nächste Ereignis -> Nacht.
$GLOBALS['simVars'][1]['value'] = $now + 3600;
$GLOBALS['simVars'][2]['value'] = $now + 36000;
$m6->MessageSink(time(), 2, 10603, [$GLOBALS['simVars'][2]['value'], true, 0]);
assertSim(count($GLOBALS['simActions']) === 1 && $GLOBALS['simActions'][0][1] === true, 'aktive Person muss ab Sonnenuntergang Licht einschalten');


// 13) Reale Regression: Human-STOP geht verloren, HTTP-Heartbeats laufen weiter.
// Der Watchdog darf die Person nach 180 s nicht weiter als physisch anwesend
// werten und muss den normalen 180-s-Lichtnachlauf starten, ohne sofort AUS.
$m7 = makeModule(true);
resetActions(false, 0);
event($m7, 'Start');
feedback($m7, 100);
$human = json_decode($m7->ReadAttributeString('ActiveHumanEvents'), true);
assertSim(count($human) === 1, 'START muss ein aktives Human-Event erzeugen');
foreach ($human as &$entry) {
    $entry['startedAt'] = time() - 181;
}
unset($entry);
$m7->WriteAttributeString('ActiveHumanEvents', json_encode($human));
$m7->WriteAttributeInteger('LastHumanActivity', time() - 181);
$m7->WriteAttributeInteger('OffDue', time() - 1);
$m7->WriteAttributeInteger('LastCameraRx', time()); // Heartbeat weiterhin aktuell
$m7->Watchdog();
assertSim(!$m7->ReadAttributeBoolean('PersonActive'), 'Watchdog muss festhängendes Human nach 180 s lösen');
assertSim(!$GLOBALS['simVars'][$m7->GetIDForIdent('PersonDetected')]['value'], 'Sichtbare Person erkannt muss AUS werden');
assertSim($m7->ReadAttributeString('ActiveHumanEvents') === '[]' || $m7->ReadAttributeString('ActiveHumanEvents') === '{}',
    'Verwaister START muss aus internem Event-Tracker verschwinden');
assertSim(($m7->timers['OffTimer'] ?? 0) === 1000,
    'Staler Human-START darf keinen ZWEITEN 180-s-Nachlauf beginnen');
assertSim(count($GLOBALS['simActions']) === 1, 'Watchdog darf nicht unmittelbar das Licht AUS toggeln');
// Nachlauf ist im Test abgelaufen; der frühe Timer-Test ist separat.
$m7->WriteAttributeInteger('OffDue', time() - 1);
$m7->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Nachlauf muss genau ein AUS anfordern');
$m7->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2, 'Stale-START-Fix darf kein doppeltes Toggle-AUS auslösen');

// 14) Zwei unabhängige Human-Ziele: nur das veraltete löschen.
$m8 = makeModule(false);
resetActions(false, 0);
event($m8, 'Start');
$human = json_decode($m8->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($human as &$entry) {
    $entry['startedAt'] = time() - 181;
}
unset($entry);
$human['crossline:0:eventid=44'] = [
    'code' => 'CrossLineDetection', 'index' => 0, 'eventId' => 44,
    'startedAt' => time() - 10
];
$m8->WriteAttributeString('ActiveHumanEvents', json_encode($human));
$m8->WriteAttributeInteger('LastCameraRx', time());
$m8->Watchdog();
assertSim($m8->ReadAttributeBoolean('PersonActive'), 'Frisches paralleles Human darf nicht mit altem gelöscht werden');
assertSim(count(json_decode($m8->ReadAttributeString('ActiveHumanEvents'), true)) === 1,
    'Nur ein veralteter von zwei STARTs muss gelöscht werden');
$human = json_decode($m8->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($human as &$entry) {
    $entry['startedAt'] = time() - 181;
}
unset($entry);
$m8->WriteAttributeString('ActiveHumanEvents', json_encode($human));
$m8->Watchdog();
assertSim(!$m8->ReadAttributeBoolean('PersonActive'), 'Letztes abgelaufenes Human muss den Status beenden');
assertSim(count($GLOBALS['simActions']) === 0, 'Reine Erkennungsinstanz darf nie Licht schalten');

// 15) Erneuter START derselben Regel verlängert die Erkennungsfrist.
$m9 = makeModule(false);
event($m9, 'Start');
$human = json_decode($m9->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($human as &$entry) {
    $entry['startedAt'] = time() - 175;
}
unset($entry);
$m9->WriteAttributeString('ActiveHumanEvents', json_encode($human));
event($m9, 'Start');
$m9->Watchdog();
assertSim($m9->ReadAttributeBoolean('PersonActive'), 'Erneuter START muss Status gültig halten');
$human = json_decode($m9->ReadAttributeString('ActiveHumanEvents'), true);
assertSim((time() - (int) reset($human)['startedAt']) < 10, 'Re-START muss Zeitstempel erneuern');

// 16) Manuell eingeschaltetes Licht bleibt bei Stale-START unangetastet.
$m10 = makeModule(true);
resetActions(true, 100);
event($m10, 'Start');
assertSim(!$m10->ReadAttributeBoolean('AutoOwned'), 'Manuelles Licht wird nicht adoptiert');
$human = json_decode($m10->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($human as &$entry) {
    $entry['startedAt'] = time() - 181;
}
unset($entry);
$m10->WriteAttributeString('ActiveHumanEvents', json_encode($human));
$m10->Watchdog();
assertSim(count($GLOBALS['simActions']) === 0, 'Watchdog darf fremdes/manuelles Licht nicht AUS schalten');
assertSim(($m10->timers['OffTimer'] ?? 0) === 0, 'Manuelles Licht darf keinen OffTimer bekommen');

// 17) Socket bereits als nicht-streamend markiert: ebenfalls kein eingefrorenes Human.
$m11 = makeModule(false);
event($m11, 'Start');
$m11->WriteAttributeBoolean('Streaming', false);
$GLOBALS['simInstances'][900]['InstanceStatus'] = 104;
$m11->Watchdog();
assertSim(!$m11->ReadAttributeBoolean('PersonActive'), 'Inaktiver Socket ohne Streaming muss Person zurücksetzen');
$GLOBALS['simInstances'][900]['InstanceStatus'] = 102;

// 18) Fehlt ein Pulse-Timer, befreit der Watchdog den PULSE-Zustand.
$m12 = makeModule(false);
$m12->WriteAttributeInteger('HumanPulseUntil', time() - 1);
$m12->WriteAttributeBoolean('PersonActive', true);
$m12->Watchdog();
assertSim(!$m12->ReadAttributeBoolean('PersonActive') && $m12->ReadAttributeInteger('HumanPulseUntil') === 0,
    'Abgelaufener PULSE darf auch ohne Timer nicht festhängen');


// 19) Per-Instanz-Zeit statt global 180: kurze und lange Nachlauf-Konfiguration.
// Ein aktiver START ohne STOP darf nur in der jeweiligen Kamera ablaufen.
$m13 = makeModule(true);
$m13->props['AfterRunSeconds'] = 45;
$m13->ApplyChanges();
$m13->WriteAttributeBoolean('Streaming', true);
resetActions(false, 0);
event($m13, 'Start');
feedback($m13, 100);
$entries = json_decode($m13->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($entries as &$entry) { $entry['startedAt'] = time() - 46; }
unset($entry);
$m13->WriteAttributeString('ActiveHumanEvents', json_encode($entries));
$m13->WriteAttributeInteger('LastHumanActivity', time() - 46);
$m13->WriteAttributeInteger('OffDue', time() - 1);
$m13->Watchdog();
assertSim(!$m13->ReadAttributeBoolean('PersonActive'),
    'Kamera mit 45 s darf nicht starr 180 s warten');
assertSim(($m13->timers['OffTimer'] ?? 0) === 1000,
    'Abgelaufene 45 s dürfen kein zweites Zeitfenster starten');
assertSim(count($GLOBALS['simActions']) === 1,
    'Der Zeitablauf darf nicht vorzeitig AUS toggeln');

$m14 = makeModule(true);
$m14->props['AfterRunSeconds'] = 600;
$m14->ApplyChanges();
$m14->WriteAttributeBoolean('Streaming', true);
resetActions(false, 0);
event($m14, 'Start');
feedback($m14, 100);
$entries = json_decode($m14->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($entries as &$entry) { $entry['startedAt'] = time() - 181; }
unset($entry);
$m14->WriteAttributeString('ActiveHumanEvents', json_encode($entries));
$m14->Watchdog();
assertSim($m14->ReadAttributeBoolean('PersonActive'),
    'Kamera mit 600 s muss trotz 181 s weiterhin Person AN melden');
assertSim(($m14->timers['OffTimer'] ?? 0) === 600000,
    'Kamera mit 600 s hält ursprüngliche Bewegungsmelderfrist aktiv');
$entries = json_decode($m14->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($entries as &$entry) { $entry['startedAt'] = time() - 601; }
unset($entry);
$m14->WriteAttributeString('ActiveHumanEvents', json_encode($entries));
$m14->WriteAttributeInteger('LastHumanActivity', time() - 601);
$m14->WriteAttributeInteger('OffDue', time() - 1);
$m14->Watchdog();
assertSim(!$m14->ReadAttributeBoolean('PersonActive'),
    'Kamera mit 600 s muss nach individuellem Timeout auf AUS wechseln');
assertSim(($m14->timers['OffTimer'] ?? 0) === 1000,
    'Abgelaufene 600 s dürfen beim STOP-Ausfall nicht neu beginnen');

// 20) Bei reiner Personenerkennung gilt derselbe individuelle Timeout,
// ohne dass die Kamera jemals ein Licht schaltet.
$m15 = makeModule(false);
$m15->props['AfterRunSeconds'] = 25;
$m15->ApplyChanges();
$m15->WriteAttributeBoolean('Streaming', true);
resetActions(false, 0);
event($m15, 'Start');
$entries = json_decode($m15->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($entries as &$entry) { $entry['startedAt'] = time() - 26; }
unset($entry);
$m15->WriteAttributeString('ActiveHumanEvents', json_encode($entries));
$m15->Watchdog();
assertSim(!$m15->ReadAttributeBoolean('PersonActive'),
    'Reine Erkennung muss 25-s-Instanztimeout respektieren');
assertSim(count($GLOBALS['simActions']) === 0,
    'Reine Personenerkennung darf nach individuellem Timeout kein Licht schalten');


// 21) Klassischer Bewegungsmelder: ein Vorbeigehen -> ein individueller Nachlauf.
$m16 = makeModule(true);
resetActions(false, 0);
event($m16, 'Start');
feedback($m16, 100);
$initialDue = $m16->ReadAttributeInteger('OffDue');
assertSim($initialDue >= time() + 179, 'Echter START muss direkt 180-s-Nachlauf anlegen');
event($m16, 'Stop');
assertSim(!$m16->ReadAttributeBoolean('PersonActive'), 'STOP muss Person erkannt zurücksetzen');
assertSim($m16->ReadAttributeInteger('OffDue') === $initialDue,
    'STOP darf die Bewegungslaufzeit NICHT neu starten');
$m16->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1,
    'Vor Ablauf der letzten Bewegung darf Licht nicht AUS gehen');

// 22) Jeder neue START erneuert den Nachlauf, auch ohne Statuswechsel
// (z.B. mehrere START-Meldungen in derselben schon aktiven Erkennung).
$m17 = makeModule(true);
resetActions(false, 0);
event($m17, 'Start');
feedback($m17, 100);
$m17->WriteAttributeInteger('OffDue', time() + 20); // verbleibende Laufzeit
$lastActivity = $m17->ReadAttributeInteger('LastHumanActivity');
event($m17, 'Start'); // weiterhin PersonActive = true
assertSim($m17->ReadAttributeInteger('OffDue') >= time() + 179,
    'Wiederholter START muss auch bei bereits aktivem Human neu triggern');
assertSim($m17->ReadAttributeInteger('LastHumanActivity') >= $lastActivity,
    'Wiederholter START muss echten letzten Ereigniszeitstempel erneuern');
assertSim(count($GLOBALS['simActions']) === 1,
    'Zweiter START darf kein zweites LCN EIN auslösen');

// 23) STOP und frische Heartbeats werden NICHT als neue Bewegung gewertet.
$dueBeforeStop = $m17->ReadAttributeInteger('OffDue');
event($m17, 'Stop');
$m17->WriteAttributeInteger('LastCameraRx', time());
$m17->Watchdog();
assertSim($m17->ReadAttributeInteger('OffDue') === $dueBeforeStop,
    'STOP/Heartbeat darf die letzte Bewegungsfrist nicht verschieben');

// 24) Neuer START während alter Ausschaltfrist verlängert sie.
// Altes Timer-Ereignis muss sauber unterdrückt werden.
$m17->WriteAttributeInteger('OffDue', time() + 12);
event($m17, 'Start');
event($m17, 'Stop');
$m17->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1,
    'Alte OffTimer-Ausführung darf nach erneutem Human das Licht nicht ausschalten');
assertSim($m17->ReadAttributeInteger('OffDue') >= time() + 179,
    'Erneute Bewegung muss die Frist wieder auf den Instanzwert setzen');

// 25) Nach abgelaufener Frist und gesundem Eventstream: ein einziges AUS.
$m17->WriteAttributeInteger('OffDue', time() - 1);
$m17->WriteAttributeInteger('LastCameraRx', time());
$m17->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Abgelaufener 180-s-Nachlauf muss genau ein AUS auslösen');
$m17->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2,
    'Verzögerte LCN-Rückmeldung darf keinen doppelten Toggle auslösen');

// 26) Kamera-Stream nicht aktuell: AUS zurückhalten, vorhandener Watchdog
// versucht erneuten Verbindungsaufbau und Schonfrist ist begrenzt.
$m18 = makeModule(true);
resetActions(false, 0);
event($m18, 'Start');
feedback($m18, 100);
event($m18, 'Stop');
$m18->WriteAttributeInteger('OffDue', time() - 1);
$m18->WriteAttributeInteger('LastCameraRx', time() - 90);
$m18->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1,
    'Ohne frischen Kamerastream zunächst kein AUS senden');
assertSim(($m18->timers['OffTimer'] ?? 0) === 15000,
    'Bei Streamfehler kontrollierte Wiederprüfung nach 15 s');
$m18->WriteAttributeInteger('LastCameraRx', time());
$m18->WriteAttributeInteger('OffDue', time() - 1);
$m18->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Nach gesundem Stream darf Licht beim Nachlaufende AUS gehen');

// 27) Kein unbegrenztes Dauerlicht bei dauerhaft defekter Kamera.
$m19 = makeModule(true);
resetActions(false, 0);
event($m19, 'Start');
feedback($m19, 100);
event($m19, 'Stop');
$m19->WriteAttributeInteger('OffDue', time() - 1);
$m19->WriteAttributeInteger('LastCameraRx', time() - 90);
$m19->WriteAttributeInteger('OffValidationSince', time() - 901);
$m19->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Kameraausfall-Schonfrist muss begrenzt bleiben');

// 28) Manuelles AUS während aktiver Personenerkennung behält Vorrang.
$m20 = makeModule(true);
resetActions(false, 0);
event($m20, 'Start');
feedback($m20, 100);
$GLOBALS['simHardwareLight'] = false;
feedback($m20, 0);
assertSim($m20->ReadAttributeBoolean('ManualLockUntilPersonClear'),
    'Manuelles AUS muss während aktiver Erkennung sperren');
event($m20, 'Start');
assertSim(count($GLOBALS['simActions']) === 1,
    'Weiterer START derselben Person darf manuelle AUS-Sperre nicht umgehen');
event($m20, 'Stop');
assertSim(!$m20->ReadAttributeBoolean('ManualLockUntilPersonClear'),
    'STOP muss die manuelle Sperre lösen');

// 29) Tageslicht/Sonnenaufgang bleibt unabhängig von der Restlaufzeit vorrangig.
$m21 = makeModule(true);
resetActions(false, 0);
event($m21, 'Start');
feedback($m21, 100);
$now = time();
$GLOBALS['simVars'][1]['value'] = $now + 36000; // Tag
$GLOBALS['simVars'][2]['value'] = $now + 3600;
$m21->MessageSink(time(), 1, 10603, [$GLOBALS['simVars'][1]['value'], true, 0]);
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Sonnenaufgang muss auto-eigenes Licht unabhängig vom Nachlauf AUS schalten');

// 30) Unterschiedliche Instanzzeiten ohne weitere Aufenthaltsschutz-Einstellung.
$m22 = makeModule(true);
$m22->props['AfterRunSeconds'] = 900;
$m22->ApplyChanges();
$m22->WriteAttributeBoolean('Streaming', true);
$m22->WriteAttributeInteger('LastCameraRx', time());
resetActions(false, 0);
event($m22, 'Start');
feedback($m22, 100);
event($m22, 'Stop');
assertSim($m22->ReadAttributeInteger('OffDue') >= time() + 899,
    '900-s-Instanz muss ausschließlich 900-s-Nachlauf verwenden');
$m22->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1,
    'Kürzere feste globale Zeit darf 900-s-Instanz nicht ausschalten');

// 31) Verlorenes STOP darf kein doppeltes Zeitfenster auslösen.
$m23 = makeModule(true);
resetActions(false, 0);
event($m23, 'Start');
feedback($m23, 100);
$active = json_decode($m23->ReadAttributeString('ActiveHumanEvents'), true);
foreach ($active as &$entry) { $entry['startedAt'] = time() - 181; }
unset($entry);
$m23->WriteAttributeString('ActiveHumanEvents', json_encode($active));
$m23->WriteAttributeInteger('LastHumanActivity', time() - 181);
$m23->WriteAttributeInteger('OffDue', time() - 1);
$m23->WriteAttributeInteger('LastCameraRx', time());
$m23->OffTimer(); // bereinigt stale START selbst vor der AUS-Entscheidung
assertSim(!$m23->ReadAttributeBoolean('PersonActive'),
    'Beim alten Human-START muss Person erkannt durch lokale Fail-safe fallen');
assertSim(count($GLOBALS['simActions']) === 2 && end($GLOBALS['simActions'])[1] === false,
    'Bei gesunder Kamera und verstrichener Bewegungsfrist kann sofort AUS folgen');
$m23->OffTimer();
assertSim(count($GLOBALS['simActions']) === 2,
    'Verlorenes STOP darf kein doppeltes Ausschalten erzeugen');

// 32) Diagnose ohne zusätzliche dauerhafte Symcon-Variablen.
$m23->DumpState();
$state = json_decode(end($m23->debug)[1], true);
assertSim(($state['LastHumanActivityAge'] ?? 0) >= 180
    && ($state['HumanEventMaxAgeSeconds'] ?? 0) === 180
    && !array_key_exists('StayQuietSeconds', $state)
    && !array_key_exists('QuietHoldUntil', $state),
    'Nur die tatsächliche Bewegung und die individuelle Nachlaufzeit diagnostizieren');

// 33) Migration aus v0.3.6 mit einstündigem StayQuiet-Ausschalttermin.
// Nach dem Update darf die alte zusätzliche Stunde nicht erhalten bleiben.
$m24 = makeModule(true);
resetActions(true, 100);
$m24->props['AfterRunSeconds'] = 45;
$m24->WriteAttributeBoolean('AutoOwned', true);
$m24->WriteAttributeBoolean('ClassicMotionMigrated', false);
$m24->WriteAttributeInteger('LastLightFeedback', 1);
$m24->WriteAttributeInteger('LastHumanActivity', time() - 10);
$m24->WriteAttributeInteger('OffDue', time() + 3600);
$m24->ApplyChanges();
assertSim($m24->ReadAttributeBoolean('ClassicMotionMigrated'),
    'Einmalige Altlast-Migration muss als durchgeführt markiert werden');
assertSim($m24->ReadAttributeInteger('OffDue') <= time() + 36,
    'Alte einstündige Aufenthaltsschutz-Frist muss auf individuelle 45-s-Erkennung korrigiert werden');
assertSim($m24->ReadAttributeBoolean('AutoOwned'),
    'Migrationskorrektur darf automatisches Eigentum nicht ungewollt verlieren');

// 34) Bereits migrierte Instanzen dürfen bei normalem Speichern
// keine weitere einmalige Eingrenzung erfahren.
$m24->WriteAttributeInteger('OffDue', time() + 90);
$m24->ApplyChanges();
assertSim($m24->ReadAttributeInteger('OffDue') >= time() + 89,
    'Wiederholtes Speichern darf eine bereits migrierte Instanz nicht kürzen');

echo "OK: vollständige Laufzeitsimulation Licht/Person/Nacht/Manual/Config bestanden\n";
