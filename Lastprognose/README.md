# LoadForecast (Lastprognose)

Erstellt aus deinen IP-Symcon-Archivdaten eine **1–3-Tage-Verbrauchsprognose** und liefert sie als
JSON-Profil (P10/P50/P90 je Zeitslot) zur direkten Nutzung durch ein EMS. Prefix der Funktionen: `LFC`.

## Funktionsweise

Statt den Verbrauch in starre Gruppen (Tageszeit × Jahreszeit × Wochentag …) zu zwängen, sucht das
Modul über ein **Ähnliche-Tage-Verfahren (k-NN)** die *k* ähnlichsten Vergangenheitstage und
verdichtet deren gemessene Tagesprofile zu einer Prognose mit Unsicherheitsband:

- **P50** – wahrscheinlichster Verlauf (Median)
- **P10 / P90** – Unter-/Obergrenze (10 % bzw. 90 % der ähnlichen Tage lagen darunter)

Die Ähnlichkeit eines Tages wird aus **Tagtyp** (Werktag/Sa/So+Feiertag), **Tageslänge**,
**Heizgrad** (aus der Außentemperatur) und **Anwesenheit** bestimmt.

## Voraussetzungen

- IP-Symcon ab **7.0**
- Eine **archivierte Leistungsvariable** des Hausverbrauchs (Watt oder Kilowatt – wird automatisch
  erkannt). Kein kWh-Zähler.
- Optional, aber empfohlen: Außentemperatur (archiviert) und ein Anwesenheitssignal.

## Einrichtung

| Bereich | Bedeutung |
|---|---|
| **Hausverbrauch** | Pflicht: archivierte Leistungsvariable. Einheit W/kW automatisch oder manuell. |
| **Abzugsliste** | Steuerbare Lasten (Wallbox, Batterie-Ladung, WP), die **vom Hausverbrauch abgezogen** werden – so lernt das Modul die planbare **Grundlast**. Preisgesteuertes Laden plant das EMS selbst. **Diese Variablen müssen archiviert sein**, sonst können sie nicht abgezogen werden (der Status meldet nicht archivierte Variablen). |
| **Wärmepumpe / Klima** | Optionale eigene Prognose je Gerät über Temperaturregression: Heizen, Kühlen oder beides (V-Kurve, z. B. Luft-Luft-WP). |
| **Anwesenheit** | Bool/0..1 (z. B. Geofencing). Bei einer *Abwesenheits*-Variable die Logik invertieren. |
| **Temperaturvorhersage** | Auto (OpenWeatherData-Instanz), eigene Tagesmittel-Variablen, Ident-Muster oder – ohne Quelle – saisonales Normal aus dem Archiv. Bei **mehreren** OpenWeatherData-Instanzen lässt sich die relevante explizit wählen (leer = erste automatisch). |
| **Auflösung** | 60 / 30 / 15 Minuten. 60 min ist robust; feinere Raster brauchen entsprechend feine Archivierung. |
| **Bundesland** | Für regionale Feiertage. |

## Statusvariablen

| Ident | Beschreibung |
|---|---|
| `LFC_Today` / `LFC_Tomorrow` / `LFC_DayAfter` | Prognoseprofil als JSON (`p10/p50/p90/mean` in W je Slot, `kwh`, `slots`, `resolution`) |
| `LFC_kWhToday` / `…Tomorrow` / `…DayAfter` | Tagesenergie der Prognose (kWh) |
| `LFC_WPkWhToday` / `…Tomorrow` / `…DayAfter` | Separater WP-/Klima-Anteil (kWh), falls konfiguriert |
| `LFC_ErrorMAPE` | Mittlerer Betragsfehler der letzten Tage (%) |
| `LFC_Accuracy` | Prognosegüte als Text: Tagesanzahl, Bias, \|Ø-Fehler\| |
| `LFC_Status` / `LFC_LastUpdate` | Status und Zeitpunkt der letzten Berechnung |

## Prognosegüte (Soll vs. Ist)

Bei jeder Neuberechnung wird die frühere Day-Ahead-Prognose vergangener Tage (gespeicherte
Snapshots) mit dem gemessenen Ist verglichen. **Bias** zeigt systematische Über-/Unterschätzung
(+ = überschätzt), **\|Ø-Fehler\|** den mittleren Betrag. Die Werte füllen sich nach einigen Tagen
Laufzeit.

## Öffentliche Funktionen

```php
// Prognose eines Tages holen: $offset 0=heute, 1=morgen, 2=übermorgen
$fc = LFC_GetForecast(int $InstanzID, int $offset); // array mit p10/p50/p90/mean/kwh …

// Gespeicherte Prognose (Soll) eines vergangenen Tages ('Y-m-d')
$snap = LFC_GetSnapshot(int $InstanzID, string $date); // [] wenn kein Snapshot

// Sofortige Neuberechnung auslösen
LFC_Rebuild(int $InstanzID);
```

Teil der **[EnergiePrognose-Suite](https://github.com/DG65/Prognose)** – zusammen mit *PVForecast*
und der *Energiebilanz*-Kachel.
