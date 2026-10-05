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
$m5->OffTimer();
assertSim(count($GLOBALS['simActions']) === 1, 'fehlgeschlagenes AUS muss einen Versuch protokollieren');
assertSim($m5->ReadAttributeInteger('SelfCommandTarget') === -1, 'fehlgeschlagener RequestAction muss Pending-Ziel löschen');
$GLOBALS['simActionMode'] = 'toggle';
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

echo "OK: vollständige Laufzeitsimulation Licht/Person/Nacht/Manual/Config bestanden\n";
