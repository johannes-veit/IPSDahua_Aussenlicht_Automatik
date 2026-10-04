# Changelog

## 0.3.0 – 2026-10-04

- Dahua-Parser auf **vollständige mehrzeilige IVS-Ereignisse** erweitert; JSON darf über mehrere TCP-Chunks verteilt sein
- Multipart-Boundaries und Heartbeats werden robust ignoriert
- direkte SMD-Erkennung `SmartMotionHuman` sowie Human-/Person-/Pedestrian-Klassifizierung in IVS-Daten vereinheitlicht
- `CrossLineDetection` und `CrossRegionDetection` mit Human-Objekt unterstützt
- verschachtelte `Object`-/`Objects[]`-Strukturen unterstützt
- EventID, RuleID, GroupID und ObjectID werden aus IVS-Daten extrahiert
- aktive Human-Ereignisse werden getrennt nachgeführt
- STOP ohne erneute Human-Klassifizierung beendet einen passenden bekannten Human-START
- explizite Vehicle-/andere Nicht-Human-STOPs löschen keinen parallelen Human-Zustand
- mehrere gleichzeitig aktive Human-Regeln werden aggregiert; `Person erkannt` bleibt TRUE bis alle beendet sind
- Human-PULSE wird fünf Sekunden gehalten
- Stream-/Heartbeat-Verlust verwirft aktive Human-Zustände und verhindert festhängendes `Person erkannt`
- Streamverlust startet bei automatik-eigenem Licht regulär den Nachlauf
- Diagnose um aktive Human-Ereignisse, letzte Human-Meldung, IDs und Empfangsalter erweitert
- Digest-Unterstützung um **SHA-256** und **SHA-256-sess** ergänzt; MD5/MD5-sess bleiben erhalten
- Nachtfreigabe wird zusätzlich vom Watchdog gegen die aktuellen Sonnenzeiten geprüft
- Message-Subscriptions für Sonnenaufgang, Sonnenuntergang, Intensitätsrückmeldung und Parent werden bei Konfigurationsänderung sauber aktualisiert
- Nachlauf-Wiederherstellung nach Neustart/Update abgesichert: Automatik-Eigentum bleibt nicht ohne Ausschaltzeitpunkt bestehen
- Licht-Schaltvariable bleibt **Boolean mit Aktion**, zusätzlich wird `HasAction()` vor dem Schalten geprüft
- echte Licht-Rückmeldung bleibt **Integer/Float Intensity**, `0=AUS`, `>0=EIN`
- ausgelegt für TiOC und `IPC-HFW5442E-ZE` ohne kameramodellspezifische Umschaltung
- neue Regressionstests für Multi-Line-IVS und parallele Human-Ereignisse

## 0.2.1 – 2026-10-04

- Licht-Schaltvariable ausdrücklich als Boolean-Variable mit Aktion ausgelegt; auch direkter nativer LCN-Ausgang möglich
- echte Licht-Rückmeldung auf Integer/Float-Intensity umgestellt
- `0 = AUS`, `> 0 = EIN`
- Rückmeldung wird nur gelesen, niemals beschrieben

## 0.2.0 – 2026-10-04

- Modul auf beliebig viele Dahua-Kameras erweitert: pro Kamera eine eigene Instanz
- Option **Personenerkennung zusätzlich für Lichtautomatik verwenden**
- reine Personenerkennung ohne Sonnen-/Lichtpflicht
- sichtbare Variable **Person erkannt** immer aktiv
- **Nachtfreigabe** bei reiner Personenerkennung ausgeblendet
- keine fest verdrahteten Terrassen-IDs für neue Instanzen

## 0.1.3 – 2026-10-04

- sichtbare read-only Statusvariablen **Person erkannt** und **Nachtfreigabe** ergänzt

## 0.1.2 – 2026-10-04

- Dahua-Digest-Handshake korrigiert: authentifizierter Request nach `401 Connection: close` über frische TCP-Verbindung
- kontrollierter Client-Socket-Neuaufbau
- Schutz gegen Passwort-Endlosschleifen
- Diagnose-Buttons korrigiert
- `.gitattributes` mit LF ergänzt

## 0.1.1 – 2026-10-04

- Dunkelheits-/Helligkeitsvariable aus der Freigabe entfernt
- Nachtfreigabe ausschließlich über Sonnenaufgang/Sonnenuntergang
- Logik: `Person erkannt UND (nach Sonnenuntergang ODER vor Sonnenaufgang)`

## 0.1.0 – 2026-10-04

- erste Modulversion für Dahua JV Terrasse
- Eventstream `codes=[All]`
- Personenerkennung, LCN-Lichtschaltung und 180-s-Nachlauf
