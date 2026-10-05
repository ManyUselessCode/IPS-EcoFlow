# EcoFlow für IP-Symcon

Bindet EcoFlow-Geräte (PowerOcean, PowerStream, STREAM, Delta, Wallbox, Smart Plug …) über die offizielle EcoFlow Open API in IP-Symcon ein.

## Funktionen
- Liest zyklisch alle Datenpunkte eines Geräts und legt dafür automatisch Variablen an
- Filter, um nur die gewünschten Datenpunkte anzulegen
- Variable „Stromüberschuss“ mit Ein-/Aus-Schwelle (Hysterese), z. B. für die Poolsteuerung
- Parameter schreiben per Skript

## Installation
1. Diesen Ordner als eigenes Repository auf GitHub anlegen (z. B. `KOSV83/IPS-EcoFlow`).
2. In der Verwaltungskonsole: Kern-Instanzen → Modules (Modulverwaltung) → „Hinzufügen“ → GitHub-URL eintragen.
3. Instanz hinzufügen: „EcoFlow Gerät (Cloud API)“ – eine Instanz pro Gerät.

## Einrichtung
1. Access Key und Secret Key eintragen, Server „Europa“ wählen.
2. Button „Geräte im EcoFlow-Konto auflisten“ → Seriennummer übernehmen.
3. Button „Verfügbare Datenpunkte anzeigen“ → sehen, was das Gerät liefert.
4. Optional Filter setzen, z. B. nur die Werte, die du wirklich brauchst.
5. Optional unter „Stromüberschuss“ den Datenpunkt für die Netzleistung eintragen.

## Funktionen für Skripte
```php
ECO_Update($id);                                   // sofort aktualisieren
ECO_ListDevices($id);                              // Geräte im Konto
ECO_ShowQuotas($id);                               // alle Datenpunkte mit Wert
ECO_GetQuota($id, '["20_1.batSoc"]');              // einzelne Werte abfragen
ECO_SetQuota($id, '{"cmdCode":"WN511_SET_PERMANENT_WATTS_PACK","params":{"permanentWatts":2000}}');
```
Der Inhalt für `ECO_SetQuota` hängt vom Gerätetyp ab, siehe EcoFlow-Entwicklerdoku.

## Hinweise
- Die Daten kommen aus der EcoFlow-Cloud. Ohne Internet oder bei Störungen der Cloud gibt es keine Werte.
- Fehler stehen im Meldungsfenster und im Debug-Fenster der Instanz.
