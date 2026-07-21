# Hinweise für Claude — Repo DG65/Prognose

## Was hier liegt

Die **EnergiePrognose-Suite** für IP-Symcon mit drei Modulen:

| Ordner | Modul | Präfix | GUID |
|---|---|---|---|
| `Lastprognose/` | Verbrauchsprognose (k-NN) | `LFC` | `{DC5AD508-507F-40EA-8630-0959AED83050}` |
| `PVPrognose/` | PV-Erzeugungsprognose (physikbasiert) | `PVF` | `{257DD4E8-9705-462E-89FC-56D0A1038353}` |
| `Energiebilanz/` | Kombinierte Kachel (HTML-SDK) | `EFTILE` | `{481CBE19-C8D9-4B72-B13F-0D249006B709}` |

## Release-Workflow

- Entwicklung läuft auf dem Branch **`beta`** (= IPS-Beta-Kanal), Version trägt das Suffix `-beta`.
- **Stable gibt der Nutzer frei** — nicht ungefragt nach `main` mergen/pushen.
- `library.json` darf **nur diese 8 Felder** enthalten (sonst lehnt der Module Store ab:
  „Zu viele Eigenschaften"): `id`, `author`, `name`, `url`, `compatibility`, `version`, `build`, `date`.
  `compatibility` im Format `{"version": "7.0"}`.
- **Build bei jeder Änderung hochzählen**, nie überschreiben.

## Zusammenarbeit mit anderen Repos

Dieses Repo wird von **[DG65/InverterHub](https://github.com/DG65/InverterHub)** konsumiert
(`InverterHubMonitor` berechnet aus Einstrahlung × Generatorparametern Erwartungswerte und
vergleicht sie mit dem gemessenen Ertrag → Verschmutzungs-/Defekterkennung).

### Vertrag: die `PVF_Get*`-Funktionen sind öffentliche API

| Funktion / Objekt | Rückgabe | Von InverterHub genutzt |
|---|---|---|
| `PVF_GetGenerators($id)` | `{pr, totalKwp, generators:[{name,kwp,tilt,azimuth,factor,area}]}` | ✅ primär |
| `PVF_GetModuleArea($id)` | Gesamt-Modulfläche in m² (float) | ✅ |
| `PVF_GetModuleAreas($id)` | Fläche je Generator `[{name,modules,lengthMM,widthMM,areaPerModule,area}]` | – |
| `PVF_GetForecast($id,$offset)` | Prognoseprofil (0=heute,1=morgen,2=übermorgen) | – |
| `PVF_GetSnapshot($id,'Y-m-d')` | Gespeicherte Day-Ahead-Prognose eines Tages | – |
| Statusvariable `PVF_ModuleArea` | Gesamtfläche (m²) | ✅ Fallback |
| Property `PVF_PR` (via `IPS_GetConfiguration`) | Performance-Ratio | ⚠️ Alt-Fallback, siehe unten |

**Regeln:**

1. Ändern sich **Signatur oder Rückgabestruktur** dieser Funktionen, muss die InverterHub-Seite
   vorher informiert werden (sie zieht `InverterHubMonitor/module.php` nach). Umgekehrt meldet sich
   InverterHub, bevor neue Bedarfe entstehen.
2. **Interne Umbauten sind frei**, solange die obige Rückgabestruktur stabil bleibt. Beispiel: Die
   Modulfläche wird seit Build 40 aus **Länge × Breite (mm)** statt aus einem Flächenfeld berechnet —
   für `PVF_GetGenerators`/`PVF_GetModuleArea` war das transparent.
3. `PVF_PR` wird von InverterHub nur als **Fallback für ältere Versionen** über
   `IPS_GetConfiguration` gelesen. Aktuell liefert `PVF_GetGenerators()` das Performance-Ratio bereits
   als Feld `pr` — der Zugriff auf die Property ist also nicht mehr nötig.
4. **Zuständigkeit:** Dieses Repo gehört uns, InverterHub der dortigen Sitzung. Änderungen, die beide
   betreffen, vorher ankündigen. Keine eigenmächtigen Commits im jeweils anderen Repo.

## Commit-Hygiene (wichtig — geteiltes Repo!)

Es committen **mehrere Sitzungen** in dieses Repo (der Getter `PVF_GetGenerators`, Build 41, kam aus
der InverterHub-Sitzung). Deshalb:

- **Kein `git add -A`** — nur die eigenen, bewusst geänderten Dateien stagen. Sonst landen fremde,
  in Arbeit befindliche Änderungen im eigenen Commit.
- **Vor dem Commit** `git fetch` / `git pull --rebase` und `git log` prüfen; Build-Nummer auf dem
  tatsächlich aktuellen Stand hochzählen.

## Fachliche Leitplanken

- **Trennung Prognose ↔ EMS:** Die Prognose sagt die *unbeeinflussbare* Nachfrage/Erzeugung vorher.
  Preis (Tibber), SoC und Ladeentscheidungen gehören in den **EMS-Optimierer**, nicht in die Prognose.
- **Abzugsliste (LFC):** Steuerbare Lasten (Wallbox, Batterie) werden abgezogen, damit die planbare
  **Grundlast** gelernt wird. Diese Variablen müssen **archiviert** sein.
- **Abgeregelte PV-Generatoren** (DC-MPPT mit Strom-/Spannungslimit): Selbstkalibrierung je Generator
  abschalten → das Modell liefert das **Potenzial** statt der gedrosselten Messung.
