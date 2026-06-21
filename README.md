# LoadForecast — Verbrauchsprognose für IP-Symcon

Erstellt aus deinen Archivdaten eine **1–3-Tage-Verbrauchsprognose** und liefert sie
als JSON-Profil (stündlich, P10/P50/P90) zur direkten Nutzung durch das EMS.

## Konzept

Statt den Verbrauch in starre Gruppen (Tageszeit × Jahreszeit × Wochentag × Urlaub …)
zu zwängen — was kombinatorisch explodiert und pro Gruppe kaum Daten lässt — nutzt das
Modul ein **Ähnliche-Tage-Verfahren (k-NN)**:

1. Jeder Prognosetag bekommt einen **Feature-Vektor**:
   - **Tagtyp** — Werktag / Samstag / Sonntag·Feiertag (stärkster Form-Treiber)
   - **Tageslänge** — sauberer Saison-Proxy statt „Monat" (aus Breitengrad + Tag des Jahres)
   - **Heizgrad** — aus Außentemperatur (Wettervorhersage); fängt Heizen/Beleuchtung
   - **Anwesenheit** — der eigentlich knifflige Faktor (Urlaub ≠ Wochentag!)
2. Aus der Historie werden die **k ähnlichsten Tage** gesucht (gewichtete Distanz).
3. Deren Stundenprofile werden entfernungsgewichtet zu **P10/P50/P90** verdichtet.

So braucht „Winter-Samstag-im-Urlaub" nie einen eigenen Bucket — das Verfahren findet
einfach die passenden Nachbartage.

Die **Wärmepumpe** (größter wetterabhängiger Verbraucher) wird optional separat über
eine lineare Temperaturregression (`kWh = a + b · Heizgrad`) prognostiziert und kann
beim Hauptverbrauch abgezogen werden, sodass nur die planbare Grundlast übrig bleibt.

## Einrichtung

> Installation **nur** über die IP-Symcon Modulverwaltung (Modul-Repository-URL),
> nicht durch manuelles Kopieren.

1. Modul-Instanz „LoadForecast" anlegen.
2. **Datenquellen** verbinden:
   - *Hausverbrauch (W)* — z.B. die EMS-Variable `Hausverbrauch (W)`. Muss archiviert sein!
   - *Abzuziehende Verbraucher* — optional WP / Wallbox (Leistung in W), um die Grundlast zu isolieren.
   - *Außentemperatur (°C)* — archiviert.
   - *Anwesenheit* — bool/0..1, z.B. aus Geofencing. **Wichtig:** ohne dieses Signal ist die Prognose bei Urlaub/Homeoffice systematisch falsch.
3. **Temperaturvorhersage** anbinden (siehe unten) und ggf. geplante Anwesenheit.
4. **Modellparameter** anpassen (Defaults sind brauchbar): Historie-Tiefe `365 d`, `k = 12`, Breitengrad, Heizgrenze `15 °C`.
5. „Prognose jetzt neu berechnen" drücken.

## Temperaturvorhersage (modul-agnostisch)

Das Modul bindet **keine** bestimmte Wetterquelle fest ein — es läuft auf jedem
Symcon-System. Wichtig: erwartet werden **Tagesmittel** (konsistent zur Historie,
die ebenfalls das Tagesmittel nutzt), nicht die aktuelle Temperatur. Drei Modi
plus automatischer Klimatologie-Fallback:

**Auto (Default, empfohlen).**
Das Modul sucht selbst eine OpenWeatherData-Instanz (demel42) per Modul-GUID — es
wird **keine** Instanz-ID fest verdrahtet, daher portabel über alle Systeme. Aus
den 3h-Slots (Min/Max → Tagesmittel) werden heute/morgen/übermorgen aggregiert.
Voraussetzung: in der OWM-Instanz die Stundenvorhersage aktivieren
(`hourly_forecast_count` > 0, z.B. 40). Ist keine OWM-Instanz da oder die
Stundenvorhersage aus → automatisch Klimatologie (siehe unten).

**Tagesmittel-Variablen.**
Zeige auf drei Variablen mit dem Tagesmittel für heute/morgen/übermorgen. Für
Wettermodule mit fertigen Tageswerten — keine Abhängigkeit.

**Slot-Aggregation über Ident-Muster.**
Für andere Module mit stündlichen/3h-Slots (DWD, Meteoblue …). Du gibst an, *wo*
die Slots liegen und *wie* sie heißen — das Modul aggregiert zum Tagesmittel:

- *Eltern-Objekt*: Instanz/Kategorie, unter der die Slot-Variablen liegen.
- *Ident-Muster*: mit `%d` oder `%02d` als Platzhalter für den Slot-Index.
- *Zeitstempel-Muster*: Unix-Zeit je Slot → das Modul sortiert nach Kalendertag.
- *Min + optional Max*: bei beiden wird `(Min+Max)/2` als Slot-Wert genutzt.

**Klimatologie-Fallback (immer aktiv).**
Liefert die gewählte Quelle für einen Tag nichts, nutzt das Modul das *saisonale
Normal*: das Mittel desselben Kalendertags ±7 Tage über alle verfügbaren Vorjahre
aus dem Temperatur-Archiv. Damit funktioniert die Prognose auch ganz ohne
Wettermodul — nur eine archivierte Außentemperatur genügt.

> Selbst ohne jede Temperaturquelle bleibt das Modul lauffähig: Der Heizgrad fällt
> dann neutral aus und die Prognose stützt sich auf Tagtyp, Tageslänge und
> Anwesenheit (Grundlast).

## Ausgaben

| Variable | Inhalt |
|---|---|
| `Prognose heute/morgen/übermorgen (JSON)` | volles Profil: `p10[]`, `p50[]`, `p90[]`, `mean[]`, `kwh` |
| `Erwartung heute/morgen/übermorgen (kWh)` | Tagessumme |
| `Erwartung WP morgen (kWh)` | separate WP-Prognose (falls konfiguriert) |
| `Status`, `Letzte Berechnung` | Diagnose |

JSON-Struktur (stündlich, 24 Slots, Werte in **W** Ø-Leistung pro Stunde):

```json
{ "date":"2026-06-18","slots":24,"resolution":"hourly","unit":"W",
  "p10":[...],"p50":[...],"p90":[...],"mean":[...],"kwh":14.3,"neighbors":12 }
```

## EMS-Integration

Im EMS-Script die Prognose direkt abrufen:

```php
$lfc = 12345; // Instanz-ID LoadForecast
$fc  = LFC_GetForecast($lfc, 1);   // 1 = morgen

$erwartungMorgen = $fc['kwh'];      // kWh
$profilMedian    = $fc['p50'];      // 24 Stundenwerte (W)
$profilP90       = $fc['p90'];      // konservativ planen

// Beispiel: Batterie konservativer laden, wenn Prognose unsicher ist
$spread = array_sum($fc['p90']) - array_sum($fc['p10']);
```

Das **Unsicherheitsband** (P10/P90) erlaubt risikobewusste Entscheidungen:
bei großer Spreizung konservativer puffern, bei enger Spreizung optimistischer fahren.

## Kachel (LoadForecastTile)

Das Paket enthält ein eigenständiges Kachel-Modul **LoadForecastTile** für die
Tile-Visualisierung. Es findet die `LoadForecast`-Instanz automatisch (oder per
Auswahl) und zeichnet das P10/P50/P90-Band der nächsten 1–3 Tage als SVG-Diagramm
(Median-Linie + Unsicherheitsfläche, Tagestrenner, kWh je Tag, „jetzt"-Marker) —
ohne externe Chart-Library. Akzentfarbe, Hintergrund und Schriftgröße sind
einstellbar; Standard ist theme-konform (transparent, automatische Textfarbe).

## Ausbaustufen

- **15-Minuten-Auflösung** (96 Slots) statt stündlich — Konstante `LFC_SLOTS` + Integration aus `AC_GetLoggedValues` (aktuell stündlich via `AC_GetAggregatedValues` für Robustheit).
- **Regionale Feiertage** in `isHoliday()` ergänzen (aktuell bundesweite).
- **Feature-Gewichte** in `distance()` justieren (`wDT/wDL/wHDD/wPres`).
