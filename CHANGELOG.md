# Changelog

## 0.20-beta (Beta-Kanal – in Entwicklung)

Dieser Stand läuft im **Beta-Kanal** und trägt daher das Kürzel `-beta` in der Version. Neue
Funktionen werden hier gesammelt und erst nach dem Test als reguläre `0.20` in den Stable-Kanal
übernommen.

## 0.19.1

- **Fix: Hintergrundfarbe deckte nicht die ganze Kachel ab.** Die konfigurierte Farbe wurde nur ans
  Diagramm übergeben — Legende, Tagesstreifen und Ränder blieben transparent (im WebView/Popup also
  weiß). Jetzt gilt die Farbe für die gesamte Fläche. Zusätzlich richten sich die **Textfarben nach
  der Helligkeit der gewählten Hintergrundfarbe** (dunkler Hintergrund → helle Schrift und umgekehrt)
  statt nach dem Hell-/Dunkelmodus des Geräts — vorher konnte helle Schrift auf weißem Grund landen.
  Ohne konfigurierte Farbe (transparent) bleibt alles wie gehabt (IPS-Theme/Gerätemodus).

## 0.19.0

- **Energiebilanz als eigenständige Webseite (WebHook)** — für IPSView-Popups und jeden Browser:
  Die Kachel ist jetzt zusätzlich unter `http://<IPS-IP>:3777/hook/energiebilanz<InstanzID>`
  erreichbar (Auto-Aktualisierung alle 30 s per Polling; `?json=1` liefert nur die Daten).
  Einbindung in IPSView: WebView-Element auf einer Popup-Seite mit dieser URL. Der Hook wird
  automatisch registriert; die konkrete URL steht in der Instanz-Doku. Hinweis: WebHooks sind im
  lokalen Netz ohne Anmeldung erreichbar.

## 0.18.2

- **Grafische Hinweise in der Modul-Doku**: Die „📖 Dokumentation & Hilfe"-Panels enthalten jetzt
  erklärende Grafiken (eingebettet, kein Internet nötig):
  - Lastprognose: **P10/P50/P90-Band** erklärt (Obergrenze/Median/Untergrenze, EMS-Nutzung).
  - PV-Prognose: **Azimut-Kompass** (0=Süd, −90=Ost, +90=West) mit Neigungs-Hinweis.
  - Energiebilanz: **Soll/Ist-Legende** (durchgezogen = Prognose, gestrichelt = gemessen,
    Punkt = Momentanwert, Band = Unsicherheit).

## 0.18.1

- **Dokumentation & Hilfe direkt im Modul**: Alle drei Instanz-Formulare haben jetzt ganz oben ein
  eingeklapptes Panel „📖 Dokumentation & Hilfe" (Muster wie im Modul Mittelwertberechnungen) —
  Funktionsweise, Datenquellen, Abzugsliste-Empfehlung, Azimut-Konvention, Quellen-/Lizenzhinweise,
  Erklärung der Prognosegüte (Bias/|Ø-Fehler|) und Praxis-Tipps.

## 0.18.0

- **Prognosegüte-Messung (Soll vs. Ist)** in Lastprognose und PV-Prognose: Bei jeder Neuberechnung
  wird je vergangenem Tag (bis 7 zurück) der Day-Ahead-Snapshot (Soll-kWh) mit dem gemessenen Ist
  aus dem Archiv verglichen — bei der Last identisch zum Prognoseziel (Hauptverbrauch minus
  Abzugsliste), bei PV die Summe der Generator-Leistungen. Neue Variablen je Modul:
  **„Prognosefehler |Ø| (%)"** (mittlerer Betragsfehler) und **„Prognosegüte"** (Text mit Bias =
  systematischer Abweichung und Tagesanzahl). Grundlage für die kommende Bias-Korrektur und das
  Residuen-Band; die Werte füllen sich, sobald Snapshots (ab v0.14) für vergangene Tage vorliegen.

## 0.17.0

- **Automatische W/kW-Erkennung (neuer Standard)**: Die Einheit der Leistungsvariablen wird jetzt
  **je Variable automatisch** erkannt — zuerst über das Variablenprofil (Suffix „W"/„kW"/„MW"),
  sonst über die Größenordnung der Tagesmaxima der letzten 7 Tage (Maximum < 100 ist nur als kW
  plausibel), im Zweifel W. Damit funktionieren auch **gemischte** Installationen (z.B. Hausverbrauch
  in W, Wärmepumpe in kW) ohne Konfiguration. Die manuelle Auswahl W/kW bleibt als Übersteuerung für
  Grenzfälle (Variablen ohne Profil mit ungewöhnlicher Größenordnung) erhalten. Gilt in allen drei
  Modulen (Lastprognose, PV-Prognose, Energiebilanz).

## 0.16.0

- **Einheit der Leistungsvariablen wählbar (W/kW)** — Community-Wunsch: wer seine Leistung seit
  Jahren in kW loggt, muss nichts umkopieren.
  - Lastprognose: ein Schalter für Hausverbrauch, Abzugsliste und Geräte (zentrale Umrechnung).
  - PV-Prognose: Einheit der gemessenen Generator-Leistung (Selbstkalibrierung).
  - Energiebilanz: Einheit der Ist-Leistungsvariablen (Legende, „jetzt"-Punkt, Ist-Verlauf, Ist-kWh).
- **Anwesenheits-Logik invertierbar** — Community-Wunsch: wer eine ABwesenheits-Variable hat
  (true = niemand zu Hause), aktiviert „Logik invertieren"; gilt für Historie und Vorhersage,
  fehlende Tage werden im invertierten Modus korrekt als „anwesend" gewertet.

## 0.15.0

- **„Gestern" im Diagramm** (Energiebilanz-Kachel, Schalter „Gestern mit anzeigen"): links vom
  Heute-Segment wird der Vortag ergänzt — **Soll** aus dem gespeicherten Prognose-Snapshot
  (`LFC_/PVF_GetSnapshot`) und **Ist** als gemessene Kurve (ganzer Tag) aus dem Archiv. So sieht man,
  wie gut die Prognose den Vortag getroffen hat. Der Tagesstreifen zeigt für jeden Tag Soll und (wo
  vorhanden) Ist. Intern auf ein Pro-Tag-Ist-Modell umgebaut; „jetzt"-Marker sitzt weiterhin korrekt
  am Heute-Segment. Beide Engines.
  - Hinweis: Das „Soll" für Gestern erscheint erst, sobald ein Snapshot vom Vortag existiert (baut
    sich ab v0.14 auf); bis dahin zeigt Gestern nur den gemessenen Ist-Verlauf.

## 0.14.0

- **Prognose-Snapshots (Vorbereitung für „Gestern"-Kontrolle):** Lastprognose und PV-Prognose
  speichern bei jeder Neuberechnung die Prognose (Soll) je Tag als Snapshot (Day-Ahead: heute +
  morgen, jeweils nur der früheste Stand pro Datum), begrenzt auf 14 Tage. Damit kann später ein
  vergangener Tag echtes **Soll vs. Ist** zeigen. Abruf über `LFC_GetSnapshot($id, 'Y-m-d')` bzw.
  `PVF_GetSnapshot($id, 'Y-m-d')`. Noch keine Darstellung im Diagramm — die Daten bauen sich erst
  über die nächsten Tage auf.

## 0.13.0

- **Ist-Tageswerte unter den Soll-Werten** (Energiebilanz-Kachel): Unter der Prognose („Soll") für
  **heute** wird jetzt der bisher gemessene Tagesertrag/-verbrauch als „Ist" in kWh angezeigt
  (PV · Verbrauch). Berechnet aus dem gemessenen Tagesverlauf (Integration bis „jetzt"), sobald die
  Ist-Leistungsvariablen konfiguriert sind. Nur „heute" hat Ist-Werte; morgen/übermorgen zeigen nur
  Soll. Der Tagesstreifen reserviert dafür automatisch etwas mehr Höhe. Beide Engines.

## 0.12.2

- **Fix: Tagesprognose unter dem Diagramm war unsichtbar.** Der Streifen mit Tagesname + kWh wurde
  per `style.display = ''` nicht eingeblendet (fiel auf das CSS `display:none` zurück) und rutschte
  zudem unter den Kachelrand. Jetzt explizit sichtbar (`display:block`), und im Höhenbudget der
  eingestellten Diagrammhöhe wird Platz dafür reserviert (Diagramm etwas niedriger, Tagesprognose
  bleibt sichtbar). Beide Engines.

## 0.12.1

- **Zeitachse mit 3-Stunden-Raster** in der Energiebilanz-Kachel: Stunden-Beschriftung (00, 03, …, 21
  je Tag) und vertikales Gitter alle 3 h — der Tagesverlauf ist jetzt ablesbar. Die Tagesnamen + kWh
  sind in den Streifen unter dem Diagramm gewandert (beide Engines), Tagesgrenzen als kräftigere
  Trennlinie. Greift in ECharts wie in Highcharts.

## 0.12.0

- **Wählbare Diagramm-Engine** in der Energiebilanz-Kachel:
  - **ECharts** (Apache-2.0, Default) — kostenlos auch für **kommerzielle** Nutzung.
  - **Highcharts** — nur für **private/nicht-kommerzielle** Nutzung kostenlos.
  Es wird nur die gewählte Library per CDN geladen; beim Umschalten wird das alte Diagramm sauber
  entsorgt. Beide Engines bieten denselben Funktionsumfang (Bänder, Ist-Verlauf, „jetzt"-Marker,
  kWh je Tag, Hover mit Saldo, Live-Werte, Aus-/Einblenden) und ein nahezu identisches Aussehen.
  - Hinweis: Default ist ECharts. Für private Nutzung in der Instanz „Highcharts" wählen.

## 0.11.2

- **Kachel-Layout:** mehr Abstand oben zum (IPS-)Titel, Legende sitzt in einem eigenen Streifen mit
  Abstand zum Diagramm (nicht mehr überlappend), und die **Diagrammhöhe ist einstellbar**
  (`ChartHeight`, Standard 360 px, 180–800).

## 0.11.1

- **Fix Highcharts-Kachel verschwand nach ~10–20 s**: Bei jeder Live-Aktualisierung wurde der
  Diagramm-Container geleert (`innerHTML = ''`), wodurch das anschließende `chart.update()` ins Leere
  lief. Der Container wird jetzt nur noch beim **Neuanlegen** geleert; Aktualisierungen laufen
  in-place (mit Recreate-Fallback bei Fehler). Mehrfach-Updates im Preview verifiziert.

## 0.11.0

- **Energiebilanz-Kachel auf Highcharts umgebaut.** Professionelleres Diagramm mit kontrollierten
  Pixel-Schriftgrößen (behebt das Schriftgrößen-Problem der SVG-Variante), Splines, nativen
  P10/P90-Bändern (`arearange`), schönen Tooltips und nativer Legende. Klick auf einen
  Legendeneintrag blendet die Reihe weiterhin aus/ein (jetzt Highcharts-nativ). Alle bisherigen
  Funktionen erhalten: Ist-Verlauf-Overlay, „jetzt"-Marker + Ist-Punkte, kWh je Tag, Hover mit Saldo,
  Linienstärke/Glättung/Band/Gitter/Y-Achse/Schriftart konfigurierbar.
- **Hinweis Lizenz:** Highcharts wird per CDN (`code.highcharts.com`) geladen, **nicht** im Repo
  mitgeliefert. Highcharts ist für private, nicht-kommerzielle Nutzung kostenlos; kommerzielle
  Nutzung erfordert eine Highcharts-Lizenz (siehe https://www.highcharts.com/license).

## 0.10.0

- **Kachel-Feinschliff (Energiebilanz):**
  - Eigener „Energiebilanz"-Titel **entfernt** — IP-Symcon zeigt den Instanznamen ohnehin; damit gibt
    es nur noch eine Überschrift (kein doppelter, unterschiedlich eingerückter Titel mehr).
  - **kW-Achsenbeschriftung** als senkrechtes Label links — keine Überlappung mit dem obersten
    Achsenwert mehr.
  - **Schriftart wählbar** (System/Arial/Verdana/Tahoma/Trebuchet/Georgia/Courier) und die
    **Schriftgröße** wirkt jetzt auf alle Beschriftungen inkl. heute/morgen/übermorgen; Tagesnamen
    etwas kräftiger.

## 0.9.3

- **Cache für den Ist-Verlauf**: Die (potenziell teure) Integration des gemessenen Tagesverlaufs aus
  dem Archiv läuft nur noch alle `MeasuredCacheSec` Sekunden (Standard 120, einstellbar) statt bei
  jedem Tile-Render. Zwischenzeitliche Renders nutzen das gecachte Profil (Attribut). Der momentane
  Ist-Wert (Legende + „jetzt"-Punkt) bleibt davon unberührt und aktualisiert weiterhin live. Cache
  wird bei Konfig-Änderung, Variablen-/Auflösungswechsel und Tageswechsel automatisch verworfen.

## 0.9.2

- **Ist-Verlauf-Overlay ohne Treppenstufen**: Bei 30/15-min-Auflösung wird der gemessene
  Tagesverlauf jetzt zeitgewichtet aus den Rohwerten integriert (`AC_GetLoggedValues`) statt aus dem
  stündlichen Aggregat hochgerechnet — echte Viertelstunden-Auflösung (Wolkendips u. Ä. sichtbar),
  glatte Linie. 60 min nutzt weiterhin das leichtgewichtige Stundenaggregat.

## 0.9.1

- **Fix Zeitversatz PV-Prognose (Open-Meteo)**: Open-Meteo liefert Strahlung als Mittel der
  *vorangehenden* Stunde (Wert um 13:00 = 12:00–13:00), das IPS-Stundenarchiv ordnet dem
  *Stundenbeginn* zu. Dadurch lag die PV-Prognose ~1 h zu spät. Die Open-Meteo-Werte werden jetzt dem
  Stundenbeginn zugeordnet (`omSlot()`), sodass Prognose und gemessener Tagesverlauf deckungsgleich
  sind. Gegen die Live-API verifiziert (Peak nun am Sonnenmittag).

## 0.9.0

- **Gemessener Tagesverlauf als Overlay** in der Energiebilanz-Kachel: der heutige Ist-Verlauf von
  PV und Verbrauch wird als gestrichelte Linie über die Prognose gelegt (aus dem Archiv, stündlich
  aufs Prognoseraster gebracht) — Prognose gegen Realität über den ganzen Tag. Je Reihe per Schalter
  ein-/ausschaltbar (`ShowActualPV` / `ShowActualLoad`).
- **Ein-/Ausblenden direkt im WebFront**: Klick auf einen Legendeneintrag blendet die jeweilige Reihe
  (inkl. Band, Ist-Linie und kWh) live aus bzw. ein; ausgeblendete Reihe wird gedimmt. Die Achse
  skaliert auf die sichtbaren Reihen.

## 0.8.0

- **Ist-Werte in der Energiebilanz-Kachel**: optionale Variablen „Ist-PV-Leistung (W)" und
  „Ist-Hausverbrauch (W)". Die momentane Leistung erscheint live in der Legende und als Punkt auf der
  „jetzt"-Linie — Prognose gegen Realität auf einen Blick. Live-Update per `VM_UPDATE`; respektiert
  die Anzeige-Schalter.
- **PVPrognose: wählbare Auflösung 60/30/15 min** (`PVF_Resolution`), deckungsgleich zur Lastprognose.
  Die Wetterquellen liefern stündlich; feinere Stufen werden interpoliert (glatterer Verlauf, gleiche
  Tagessumme — verifiziert).
- **2 Nachkommastellen** für die kWh-Werte je Tag und die Ist-Werte in der Kachel.

## 0.7.0

- **Modul `LastprognoseKachel` entfernt** — die Energiebilanz-Kachel deckt den Last-only-Fall ab und
  ist die fähigere Kachel. Bestehende LastprognoseKachel-Instanzen bitte durch eine Energiebilanz-
  Instanz mit „PV-Erzeugung anzeigen" = aus ersetzen.
- **Energiebilanz: Anzeige-Schalter** „PV-Erzeugung anzeigen" und „Verbrauch anzeigen". Damit lässt
  sich dieselbe Kachel als reine PV-, reine Verbrauchs- oder kombinierte Ansicht nutzen — auch wenn
  beide Quell-Instanzen vorhanden sind.

## 0.6.0

- **Konsistente Namensgebung** (nur Anzeigenamen; Prefixe `LFC_`/`PVF_`/`EFTILE_` und GUIDs bleiben,
  damit bestehende Instanzen und EMS-Aufrufe weiterlaufen):
  - Bibliothek → **Prognose**
  - LoadForecast → **Lastprognose** (Alias „Last-Prognose")
  - PVForecast → **PVPrognose** (Alias „PV-Prognose")
  - LoadForecastTile → **LastprognoseKachel** (Alias „Last-Prognose (Kachel)")
  - EnergyForecastTile → **Energiebilanz** (Alias „Energieprognose")
  - (Modulname = PHP-Klassenname; Bindestriche daher nur als Alias möglich.)
- **Energiebilanz-Kachel konfigurierbar**: Linienstärke, Kurvenglättung (Catmull-Rom — gegen die
  kantigen Linien), Unsicherheitsband ein/aus + Transparenz, Gitter/Achsen ein/aus, Y-Achse manuell
  begrenzbar.
- **kWh je Tag** statt Gesamtsumme: jeder Tag zeigt seine eigene erwartete PV- und Verbrauchs-kWh
  unter dem Tagesnamen; die Legende ist auf den Farbschlüssel reduziert.

## 0.5.0

- **Bibliothek zur Energieprognose-Suite erweitert** (Last + PV in einem Repo). Zwei neue Module:
- **PVForecast** (Typ 3, Prefix `PVF`) — **physikbasierte** PV-Erzeugungsprognose statt Mustersuche:
  - Pro PV-Generator Anlagengeometrie (Neigung, Azimut, kWp); Generatoren werden zur Gesamt-PV summiert.
  - **Wählbare Vorhersagequelle**: Open-Meteo (kostenlos, kein Key, geneigte Einstrahlung →
    `kWp × GTI/1000 × PR` mit Temperatur-Derating), Forecast.Solar (liefert Leistung direkt) oder
    Solcast (API-Key, inkl. P10/P90).
  - **Selbstkalibrierung** (Open-Meteo): vergleicht gemessene mit aus vergangener Einstrahlung
    modellierter Erzeugung und lernt je Generator einen Korrekturfaktor (Verschattung, Verschmutzung,
    reale Leistung). Plus manueller Korrekturfaktor je Generator.
  - Stündliche Ausgabe P10/P50/P90 + kWh für heute/morgen/übermorgen, per `PVF_GetForecast($id, $offset)`.
  - Leistungsrechnung gegen die echte Open-Meteo-API plausibilisiert.
- **EnergyForecastTile** (Typ 3, Prefix `EFTILE`) — kombinierte Kachel: PV-Erzeugung und Verbrauch
  als zwei Bänder in einem Diagramm, Auto-Erkennung beider Quellen, Hover/Touch mit **Saldo**,
  funktioniert auch PV-only.

## 0.4.0

- **Wählbare zeitliche Auflösung** (60 / 30 / 15 Minuten, Modellparameter). 60 min nutzt weiterhin
  das robuste Stundenaggregat; 30/15 min werden zeitgewichtet aus den Rohwerten integriert
  (`AC_GetLoggedValues`). Slots, Profile, Perzentile und kWh rechnen jetzt durchgängig mit der
  Slot-Dauer. Hinweis: feinere Auflösung braucht entsprechend feine Archivierung über den
  Historie-Zeitraum; Tage ohne Rohdaten werden übersprungen.
- **Regionale Feiertage**: Auswahl des Bundeslands (Modellparameter). Zusätzlich zu den bundesweiten
  Feiertagen werden die landesspezifischen berücksichtigt (Heilige Drei Könige, Fronleichnam,
  Mariä Himmelfahrt, Reformationstag, Allerheiligen, Buß- und Bettag, Frauentag, Weltkindertag).
  Verbessert die Tagtyp-Zuordnung und damit die Ähnliche-Tage-Suche.
- **Separate Geräteprognose jetzt für heute/morgen/übermorgen** (`LFC_WPkWhToday/Tomorrow/DayAfter`)
  statt nur morgen. Variablen umbenannt auf „WP/Klima".
- Die Kachel ist resolutionsunabhängig: Tooltip-Uhrzeit und „jetzt"-Marker rechnen minutengenau aus
  der Slot-Anzahl (z.B. „Morgen 18:15" bei 15-min-Auflösung).

## 0.3.2

- **Kachel: Hover-/Touch-Tooltip** im Diagramm. Beim Überfahren (Maus oder Touch — wichtig für
  Wandtablets) erscheint ein Fadenkreuz mit Punkten auf P10/P50/P90 und ein Wertefeld mit Tag,
  Uhrzeit, erwartetem Wert (P50) und Bandbereich (P10–P90).

## 0.3.1

- **Fix Kachel blieb leer:** `GetVisualizationTile()` übergibt die Daten als JSON-**String**;
  `handleMessage()` interpretierte ihn aber als bereits geparstes Objekt → kein Inhalt. `handleMessage`
  parst den String jetzt (wie das Tibber-Kachelmodul): `typeof payload === 'string' ? JSON.parse(...)`.

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
