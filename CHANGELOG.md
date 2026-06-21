# Changelog

## 0.3.0

- **Separate Geräteprognose auf Heizen/Kühlen erweitert** (für Luft-Luft-WP/Klimaanlagen):
  - Das frühere Einzelfeld „Wärmepumpe" ist jetzt eine **Geräteliste** mit je eigener Betriebsart
    (Heizen / Kühlen / beides). Pro Gerät wird eine eigene Regression gefittet, die Summe ergibt die
    Gesamtprognose (`LFC_WPkWhTomorrow`).
  - Betriebsart „Heizen + Kühlen" nutzt eine **V-Kurve**: `kWh = a + b·Heizgrad + c·Kühlgrad`
    mit getrennten Knickpunkten (Heiz- und Kühlgrenztemperatur). Damit wird ein Gerät, das im
    Winter heizt und im Sommer kühlt, in beide Richtungen korrekt prognostiziert.
  - Neue Kühlgrenztemperatur (`LFC_CDD_Base`, Standard 22 °C); Heizgrenze wie bisher (15 °C).
  - Gelöst über die Normalgleichungen mit Gauß-Elimination; bei singulärer Datenlage (z.B. kein
    Kühlbedarf in der Historie) wird das Gerät sauber übersprungen.
  - **Abwärtskompatibel**: eine bestehende Einzelfeld-Konfiguration wirkt übergangsweise als
    Heiz-Gerät weiter, bis sie in der Liste eingetragen wird.

## 0.2.0

- Neues, eigenständiges Kachel-Modul **LoadForecastTile** (Typ 3, Prefix `LFCTILE`) in derselben
  Bibliothek (Aufbau wie das Tibber-Kachelmodul):
  - Randlose HTML-SDK-Kachel (`SetVisualizationType(1)` + `GetVisualizationTile()` + `module.html`).
  - Zeichnet das **P10/P50/P90-Band** der nächsten 1–3 Tage als selbst gerendertes SVG-Diagramm
    (Median-Linie + Unsicherheitsfläche), ohne externe Chart-Library — läuft offline in der Kachel.
  - Tagestrenner, kWh je Tag als Chips, „jetzt"-Marker auf der aktuellen Stunde.
  - Findet die `LoadForecast`-Quelle automatisch (`IPS_GetInstanceListByModuleID`), aktualisiert
    sich per `VM_UPDATE` der Prognose-Variablen.
  - Theme-konform (transparent/automatische Textfarbe), Akzentfarbe/Hintergrund/Schriftgröße
    einstellbar, Button „auf Standard zurücksetzen".

## 0.1.0

- Erstes öffentliches Gerüst des Moduls **LoadForecast** (Typ 3, Prefix `LFC`):
  - 1–3-Tage-Verbrauchsprognose aus dem IPS-Archiv über ein **Ähnliche-Tage-Verfahren (k-NN)**
    mit Feature-Vektor: Tagtyp (Werktag/Sa/So·Feiertag), Tageslänge (Saison-Proxy aus Standort),
    Heizgrad aus Außentemperatur und Anwesenheit.
  - Ausgabe als stündliches Profil mit **Unsicherheitsband P10/P50/P90** und Tagessumme (kWh),
    als JSON-Variablen sowie per `LFC_GetForecast($id, $offset)` für das EMS abrufbar.
  - Optional abziehbare Verbraucher (Wärmepumpe, Wallbox) → reine planbare Grundlast.
  - Optionale separate **Wärmepumpen-Prognose** über lineare Temperaturregression (Heizgrad).
- **Temperaturvorhersage modul-agnostisch** (läuft auf jedem System, keine Instanz-ID hartcodiert):
  - Auto-Modus: findet eine `OpenWeatherData`-Instanz (demel42) automatisch per Modul-GUID und
    aggregiert die 3h-Slots zu Tagesmitteln.
  - Tagesmittel-Variablen oder freie Ident-Muster als Alternativen für andere Wettermodule.
  - **Klimatologie-Fallback**: saisonales Normal (gleicher Kalendertag ±7 Tage über Vorjahre)
    aus dem Temperatur-Archiv, wenn keine Vorhersage verfügbar ist.
