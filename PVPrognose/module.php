<?php

// ============================================================
//  PVForecast — PV-Erzeugungsprognose für IP-Symcon
//  Autor   : DG65
//  Version : 0.5 (Teil der LastPrognose-Bibliothek)
//  GUID    : {257DD4E8-9705-462E-89FC-56D0A1038353}
//
//  Ansatz: deterministische Physik statt Mustersuche.
//  Pro PV-Generator (Neigung, Azimut, kWp) wird die erwartete
//  Leistung aus einer externen Einstrahlungs-/PV-Vorhersage
//  berechnet. Wählbare Quelle:
//    - Open-Meteo  : geneigte Einstrahlung (GTI) → kWp·GTI/1000·PR
//    - Forecast.Solar: liefert PV-Leistung direkt
//    - Solcast     : liefert PV-Leistung inkl. P10/P90
//  Die Archivdaten dienen der Selbstkalibrierung (gemessen vs.
//  vorhergesagt → Korrekturfaktor je Generator).
// ============================================================

define('PVF_LOG_OFF',     0);
define('PVF_LOG_BASIC',   1);
define('PVF_LOG_VERBOSE', 2);

define('PVF_ARCHIVE_GUID', '{43192F0B-135B-4CE7-A0A7-1475603F3060}');

// Vorhersagequellen
define('PVF_SRC_OPENMETEO',     0);
define('PVF_SRC_FORECASTSOLAR', 1);
define('PVF_SRC_SOLCAST',       2);

class PVPrognose extends IPSModule
{
    // Request-lokales Modell: [offset => 24×{p10,p50,p90} in W]
    private $modelCache = null;
    // Request-lokaler Cache der automatisch erkannten Einheiten je Variable
    private $unitCache = [];

    // ----------------------------------------------------------------
    //  Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('PVF_Active',        false);
        $this->RegisterPropertyInteger('PVF_IntervalHours', 6);
        $this->RegisterPropertyInteger('PVF_Log_Level',     PVF_LOG_BASIC);

        // Vorhersagequelle
        $this->RegisterPropertyInteger('PVF_Source',        PVF_SRC_OPENMETEO);
        $this->RegisterPropertyFloat(  'PVF_Latitude',      49.0);
        $this->RegisterPropertyFloat(  'PVF_Longitude',     9.0);
        $this->RegisterPropertyFloat(  'PVF_PR',            0.85);   // Performance-Ratio (Open-Meteo)
        $this->RegisterPropertyFloat(  'PVF_TempCoeff',     -0.40);  // %/K (Open-Meteo, 0 = aus)
        $this->RegisterPropertyString( 'PVF_SolcastKey',    '');

        // PV-Generatoren: je Dachfläche/MPP-Tracker.
        // { Name, Tilt, Azimuth(-180..180,0=Süd), kWp, PowerVar, SolcastId, Factor }
        $this->RegisterPropertyString('PVGenerators',       '[]');

        // Selbstkalibrierung (Open-Meteo): gemessen vs. vorhergesagt.
        $this->RegisterPropertyBoolean('PVF_Calibrate',     false);
        $this->RegisterPropertyInteger('PVF_CalibDays',     21);
        // Einheit der gemessenen Generator-Leistung: 0=W, 1=kW, 2=automatisch.
        $this->RegisterPropertyInteger('PVF_PowerUnit',     2);

        // Zeitliche Auflösung (60/30/15 min). Quellen liefern stündlich;
        // feinere Stufen werden interpoliert (zur Deckung mit der Lastprognose).
        $this->RegisterPropertyInteger('PVF_Resolution',    60);

        // Ausgabe
        $this->RegisterVariableString('PVF_Today',     'PV-Prognose heute (JSON)',      '', 10);
        $this->RegisterVariableString('PVF_Tomorrow',  'PV-Prognose morgen (JSON)',     '', 20);
        $this->RegisterVariableString('PVF_DayAfter',  'PV-Prognose übermorgen (JSON)', '', 30);
        $this->RegisterVariableFloat( 'PVF_kWhToday',    'Erwartete PV heute (kWh)',     '~Electricity', 40);
        $this->RegisterVariableFloat( 'PVF_kWhTomorrow', 'Erwartete PV morgen (kWh)',    '~Electricity', 50);
        $this->RegisterVariableFloat( 'PVF_kWhDayAfter', 'Erwartete PV übermorgen (kWh)','~Electricity', 60);
        $this->RegisterVariableString('PVF_Status',    'Status',                    '', 70);
        $this->RegisterVariableInteger('PVF_LastUpdate','Letzte Berechnung',        '~UnixTimestamp', 80);

        // Tages-Snapshots der Prognose (für spätere Soll-vs-Ist-Kontrolle je Tag)
        $this->RegisterAttributeString('PVF_Snapshots', '');

        $this->RegisterTimer('PVF_RebuildTimer', 0, 'PVF_Rebuild($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('PVF_Active');
        $hours  = max(1, $this->ReadPropertyInteger('PVF_IntervalHours'));

        if ($active) {
            $this->SetTimerInterval('PVF_RebuildTimer', $hours * 3600 * 1000);
            $this->SetStatus(102);
        } else {
            $this->SetTimerInterval('PVF_RebuildTimer', 0);
            $this->SetStatus(104);
        }
    }

    // ----------------------------------------------------------------
    //  Öffentlich
    // ----------------------------------------------------------------

    public function Rebuild()
    {
        if (count($this->pvGenerators()) === 0) {
            $this->SetValue('PVF_Status', 'Keine PV-Generatoren konfiguriert');
            return;
        }

        $this->modelCache = null;
        $model = $this->buildModel();
        if ($model === null) {
            $this->SetValue('PVF_Status', 'Vorhersage konnte nicht geladen werden (API/Netzwerk?)');
            $this->SetStatus(104);
            return;
        }

        $idents = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter'];
        $kwhIds = ['PVF_kWhToday', 'PVF_kWhTomorrow', 'PVF_kWhDayAfter'];
        $fcs = [];
        for ($offset = 0; $offset <= 2; $offset++) {
            $fc = $this->GetForecast($offset);
            $fcs[$offset] = $fc;
            $this->SetValue($idents[$offset], json_encode($fc));
            $this->SetValue($kwhIds[$offset], round($fc['kwh'], 2));
        }
        $this->saveSnapshot($fcs);

        $this->SetValue('PVF_LastUpdate', time());
        $this->SetValue('PVF_Status', sprintf(
            'OK | heute %.1f / morgen %.1f / übermorgen %.1f kWh',
            $this->GetValue('PVF_kWhToday'),
            $this->GetValue('PVF_kWhTomorrow'),
            $this->GetValue('PVF_kWhDayAfter')
        ));
        $this->SetStatus(102);
        $this->log(PVF_LOG_BASIC, 'Neuberechnung abgeschlossen');
    }

    /**
     * PV-Prognose für einen Tag als Array. $offset 0/1/2.
     * Für das EMS per PVF_GetForecast($id, $offset) abrufbar.
     */
    public function GetForecast(int $offset)
    {
        if ($this->modelCache === null) {
            $this->modelCache = $this->buildModel();
        }
        $targetTs = strtotime('today +' . $offset . ' days');
        if ($this->modelCache === null || !isset($this->modelCache[$offset])) {
            return $this->emptyForecast($targetTs);
        }

        $day = $this->modelCache[$offset];
        $h10 = []; $h50 = []; $h90 = [];
        for ($h = 0; $h < 24; $h++) {
            $h10[$h] = $day[$h]['p10'];
            $h50[$h] = $day[$h]['p50'];
            $h90[$h] = $day[$h]['p90'];
        }

        // Stündliches Modell auf die gewählte Auflösung bringen (Interpolation).
        $slots = $this->slots();
        $p10 = $this->resample($h10, $slots);
        $p50 = $this->resample($h50, $slots);
        $p90 = $this->resample($h90, $slots);
        $kwh = array_sum($p50) * $this->slotHours() / 1000.0;

        return [
            'date'       => date('Y-m-d', $targetTs),
            'slots'      => $slots,
            'resolution' => $this->slotMinutes() . 'min',
            'unit'       => 'W',
            'p10'        => array_map(function ($x) { return round($x, 1); }, $p10),
            'p50'        => array_map(function ($x) { return round($x, 1); }, $p50),
            'p90'        => array_map(function ($x) { return round($x, 1); }, $p90),
            'mean'       => array_map(function ($x) { return round($x, 1); }, $p50),
            'kwh'        => round($kwh, 2),
            'neighbors'  => 0,
        ];
    }

    private function slotMinutes(): int
    {
        $m = $this->ReadPropertyInteger('PVF_Resolution');
        return in_array($m, [15, 30, 60], true) ? $m : 60;
    }

    private function slots(): int
    {
        return (int)(1440 / $this->slotMinutes());
    }

    private function slotHours(): float
    {
        return $this->slotMinutes() / 60.0;
    }

    /**
     * Stündliche Werte (24, je Stundenmarke h:00) linear auf $slots Slots
     * interpolieren. Bei 60 min unverändert; feiner = geglätteter Verlauf.
     */
    private function resample(array $hourly, int $slots): array
    {
        if ($slots === 24) { return array_values($hourly); }
        $out = [];
        $step = 24.0 / $slots; // Stunden je Slot
        for ($s = 0; $s < $slots; $s++) {
            $hf = $s * $step;          // Position in Stunden
            $h0 = (int)floor($hf);
            $h1 = min(23, $h0 + 1);
            $f  = $hf - $h0;
            $out[$s] = $hourly[$h0] * (1.0 - $f) + $hourly[$h1] * $f;
        }
        return $out;
    }

    public function GetStatusText()
    {
        return (string) $this->GetValue('PVF_Status');
    }

    /**
     * Gespeicherte PV-Prognose (Soll) eines vergangenen Tages ('Y-m-d').
     * Rückgabe [] wenn kein Snapshot vorhanden.
     */
    public function GetSnapshot(string $date)
    {
        $snaps = json_decode($this->ReadAttributeString('PVF_Snapshots'), true);
        return (is_array($snaps) && isset($snaps[$date])) ? $snaps[$date] : [];
    }

    /**
     * Speichert je Tag genau einen Prognose-Snapshot (Soll): heute + morgen,
     * jeweils nur wenn für das Datum noch keiner existiert → jeder Tag behält
     * den frühesten (Day-Ahead-)Stand. Auf die letzten 14 Tage begrenzt.
     */
    private function saveSnapshot(array $fcs)
    {
        $snaps = json_decode($this->ReadAttributeString('PVF_Snapshots'), true);
        if (!is_array($snaps)) { $snaps = []; }

        foreach ([0, 1] as $offset) {
            if (!isset($fcs[$offset])) { continue; }
            $fc   = $fcs[$offset];
            $date = date('Y-m-d', strtotime('today +' . $offset . ' days'));
            if (isset($snaps[$date])) { continue; }
            if (array_sum($fc['p50'] ?? []) <= 0) { continue; }
            $snaps[$date] = [
                'slots'      => $fc['slots'],
                'resolution' => $fc['resolution'],
                'p50'        => $fc['p50'],
                'kwh'        => $fc['kwh'],
            ];
        }

        krsort($snaps);
        $snaps = array_slice($snaps, 0, 14, true);
        $this->WriteAttributeString('PVF_Snapshots', json_encode($snaps));
    }

    // ----------------------------------------------------------------
    //  Modellaufbau (Summe der Generatoren)
    // ----------------------------------------------------------------

    /**
     * Baut das Tagesmodell für heute/morgen/übermorgen:
     * [offset => [hour => ['p10','p50','p90']]] in W, Summe aller Generatoren.
     * Rückgabe null, wenn keine Quelle Daten liefert.
     */
    private function buildModel()
    {
        $gens = $this->pvGenerators();
        if (count($gens) === 0) { return null; }
        $src = $this->ReadPropertyInteger('PVF_Source');

        $model = [];
        for ($o = 0; $o <= 2; $o++) {
            $model[$o] = [];
            for ($h = 0; $h < 24; $h++) { $model[$o][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0]; }
        }

        $gotAny = false;
        foreach ($gens as $g) {
            switch ($src) {
                case PVF_SRC_FORECASTSOLAR: $perDay = $this->fetchForecastSolar($g); break;
                case PVF_SRC_SOLCAST:       $perDay = $this->fetchSolcast($g);       break;
                case PVF_SRC_OPENMETEO:
                default:                    $perDay = $this->fetchOpenMeteo($g);     break;
            }
            if ($perDay === null) { continue; }

            $factor = $this->generatorFactor($g, $src);
            for ($o = 0; $o <= 2; $o++) {
                if (!isset($perDay[$o])) { continue; }
                for ($h = 0; $h < 24; $h++) {
                    $cell = $perDay[$o][$h];
                    $model[$o][$h]['p10'] += $cell['p10'] * $factor;
                    $model[$o][$h]['p50'] += $cell['p50'] * $factor;
                    $model[$o][$h]['p90'] += $cell['p90'] * $factor;
                }
            }
            $gotAny = true;
        }

        return $gotAny ? $model : null;
    }

    private function pvGenerators(): array
    {
        $out  = [];
        $list = json_decode($this->ReadPropertyString('PVGenerators'), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                $out[] = [
                    'name'    => (string)($row['Name'] ?? ''),
                    'tilt'    => (float)($row['Tilt'] ?? 30),
                    'az'      => (float)($row['Azimuth'] ?? 0),
                    'kwp'     => (float)($row['kWp'] ?? 0),
                    'powervar'=> (int)($row['PowerVar'] ?? 0),
                    'solcast' => (string)($row['SolcastId'] ?? ''),
                    'factor'  => (float)($row['Factor'] ?? 1.0),
                ];
            }
        }
        return $out;
    }

    /** Wirksamer Korrekturfaktor: manuell × (optional) Selbstkalibrierung. */
    private function generatorFactor(array $g, int $src): float
    {
        $f = ($g['factor'] > 0) ? $g['factor'] : 1.0;
        if ($src === PVF_SRC_OPENMETEO && $this->ReadPropertyBoolean('PVF_Calibrate') && $g['powervar'] > 0) {
            $cal = $this->calibrate($g);
            if ($cal !== null) { $f *= $cal; }
        }
        return $f;
    }

    // ----------------------------------------------------------------
    //  Quelle: Open-Meteo (geneigte Einstrahlung → Leistung)
    // ----------------------------------------------------------------

    private function fetchOpenMeteo(array $g, int $pastDays = 0)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
            . '&hourly=global_tilted_irradiance,temperature_2m&tilt=%s&azimuth=%s'
            . '&forecast_days=3&past_days=%d&timezone=auto',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']), $pastDays
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['hourly']['time'])) { return null; }

        $time = $j['hourly']['time'];
        $gti  = $j['hourly']['global_tilted_irradiance'] ?? [];
        $temp = $j['hourly']['temperature_2m'] ?? [];
        $pr   = $this->ReadPropertyFloat('PVF_PR');
        $tc   = $this->ReadPropertyFloat('PVF_TempCoeff');
        $kwpW = $g['kwp'] * 1000.0;

        // Leistung je Datum/Stunde berechnen (W).
        $byDate = [];
        $n = count($time);
        for ($i = 0; $i < $n; $i++) {
            // Open-Meteo: Strahlung = Mittel der VORANGEHENDEN Stunde →
            // dem Stundenbeginn zuordnen (deckt sich mit dem IPS-Stundenaggregat).
            list($date, $hour) = $this->omSlot($time[$i]);
            $irr  = (float)($gti[$i] ?? 0);
            $ta   = (float)($temp[$i] ?? 20);
            $derate = 1.0;
            if ($tc != 0.0 && $irr > 0) {
                $tcell  = $ta + $irr / 800.0 * 20.0;       // NOCT-Näherung
                $derate = 1.0 + ($tc / 100.0) * ($tcell - 25.0);
            }
            $w = $kwpW * ($irr / 1000.0) * $pr * max(0.0, $derate);
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = $w;
        }
        return $this->mapOffsets($byDate);
    }

    /**
     * Open-Meteo-Zeitstempel ("Y-m-d\TH:i") auf [Datum, Stunde] des
     * Mittelungsintervall-BEGINNS abbilden (eine Stunde zurück), damit
     * Prognose und gemessenes Stundenaggregat zeitlich deckungsgleich sind.
     */
    private function omSlot(string $t): array
    {
        $date = substr($t, 0, 10);
        $hour = (int)substr($t, 11, 2) - 1;
        if ($hour < 0) { $hour = 23; $date = date('Y-m-d', strtotime($date . ' -1 day')); }
        return [$date, $hour];
    }

    /**
     * Selbstkalibrierung: gemessene vs. vorhergesagte Tages-kWh über die
     * letzten Tage (aus echter, vergangener Einstrahlung). Liefert das
     * mittlere Verhältnis gemessen/modelliert (geklammert), sonst null.
     */
    private function calibrate(array $g)
    {
        $days = max(7, $this->ReadPropertyInteger('PVF_CalibDays'));
        // Open-Meteo mit past_days liefert auch vergangene Einstrahlung.
        $perDayPast = $this->fetchOpenMeteoPast($g, $days);
        if ($perDayPast === null) { return null; }

        $ratios = [];
        foreach ($perDayPast as $date => $hours) {
            $pred = array_sum($hours) / 1000.0;          // modellierte kWh
            if ($pred < 0.2) { continue; }               // Nachts/triviale Tage überspringen
            $meas = $this->measuredKwh($g['powervar'], strtotime($date));
            if ($meas === null) { continue; }
            $ratios[] = $meas / $pred;
        }
        if (count($ratios) < 5) { return null; }

        sort($ratios);
        $median = $ratios[(int)floor(count($ratios) / 2)];
        return max(0.4, min(1.6, $median));
    }

    /** Open-Meteo nur für vergangene Tage (Kalibrierung), nach Datum. */
    private function fetchOpenMeteoPast(array $g, int $pastDays)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
            . '&hourly=global_tilted_irradiance,temperature_2m&tilt=%s&azimuth=%s'
            . '&forecast_days=0&past_days=%d&timezone=auto',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']), $pastDays
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['hourly']['time'])) { return null; }

        $time = $j['hourly']['time'];
        $gti  = $j['hourly']['global_tilted_irradiance'] ?? [];
        $pr   = $this->ReadPropertyFloat('PVF_PR');
        $kwpW = $g['kwp'] * 1000.0;
        $today = date('Y-m-d');

        $byDate = [];
        $n = count($time);
        for ($i = 0; $i < $n; $i++) {
            list($date, $hour) = $this->omSlot($time[$i]); // Stundenbeginn (siehe fetchOpenMeteo)
            if ($date >= $today) { continue; }             // nur abgeschlossene Tage
            $w = $kwpW * ((float)($gti[$i] ?? 0) / 1000.0) * $pr;
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = $w;
        }
        return $byDate;
    }

    // ----------------------------------------------------------------
    //  Quelle: Forecast.Solar (liefert Leistung direkt)
    // ----------------------------------------------------------------

    private function fetchForecastSolar(array $g)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.forecast.solar/estimate/%s/%s/%s/%s/%s',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']),
            rawurlencode((string)$g['kwp'])
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['result']['watts'])) {
            $this->log(PVF_LOG_VERBOSE, 'Forecast.Solar ohne Ergebnis (Limit erreicht?)');
            return null;
        }

        $byDate = [];
        foreach ($j['result']['watts'] as $ts => $w) {
            $date = substr($ts, 0, 10);
            $hour = (int)substr($ts, 11, 2);
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = (float)$w;            // Stundenwert (volle Stunde gewinnt)
        }
        return $this->mapOffsets($byDate);
    }

    // ----------------------------------------------------------------
    //  Quelle: Solcast (Leistung inkl. P10/P90)
    // ----------------------------------------------------------------

    private function fetchSolcast(array $g)
    {
        $key = trim($this->ReadPropertyString('PVF_SolcastKey'));
        $rid = trim($g['solcast']);
        if ($key === '' || $rid === '') {
            $this->log(PVF_LOG_VERBOSE, 'Solcast: API-Key oder Resource-ID fehlt');
            return null;
        }
        $url = sprintf('https://api.solcast.com.au/rooftop_sites/%s/forecasts?format=json&hours=72',
            rawurlencode($rid));
        $j = $this->httpGetJson($url, ['Authorization: Bearer ' . $key]);
        if ($j === null || !isset($j['forecasts'])) { return null; }

        // 30-Min-Schätzungen (kW) zu Stunden mitteln; P10/P50/P90.
        $acc = [];
        foreach ($j['forecasts'] as $f) {
            $end = strtotime($f['period_end'] ?? '');
            if ($end <= 0) { continue; }
            $date = date('Y-m-d', $end);
            $hour = (int)date('G', $end);
            $key2 = $date . ' ' . $hour;
            if (!isset($acc[$key2])) { $acc[$key2] = ['p10' => [], 'p50' => [], 'p90' => []]; }
            $acc[$key2]['p50'][] = (float)($f['pv_estimate'] ?? 0) * 1000.0;
            $acc[$key2]['p10'][] = (float)($f['pv_estimate10'] ?? $f['pv_estimate'] ?? 0) * 1000.0;
            $acc[$key2]['p90'][] = (float)($f['pv_estimate90'] ?? $f['pv_estimate'] ?? 0) * 1000.0;
        }
        $byDate = [];
        foreach ($acc as $key2 => $vals) {
            list($date, $hour) = explode(' ', $key2);
            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
                for ($h = 0; $h < 24; $h++) { $byDate[$date][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0]; }
            }
            $byDate[$date][(int)$hour] = [
                'p10' => array_sum($vals['p10']) / max(1, count($vals['p10'])),
                'p50' => array_sum($vals['p50']) / max(1, count($vals['p50'])),
                'p90' => array_sum($vals['p90']) / max(1, count($vals['p90'])),
            ];
        }
        return $this->mapOffsets($byDate, true);
    }

    // ----------------------------------------------------------------
    //  Hilfen
    // ----------------------------------------------------------------

    /**
     * Ordnet ein nach Datum indiziertes Tagesraster den Offsets 0/1/2 zu.
     * $hasBands=false: skalare W-Werte → p10=p50=p90; true: bereits {p10,p50,p90}.
     */
    private function mapOffsets(array $byDate, bool $hasBands = false): array
    {
        $out = [];
        for ($o = 0; $o <= 2; $o++) {
            $date = date('Y-m-d', strtotime('today +' . $o . ' days'));
            $out[$o] = [];
            for ($h = 0; $h < 24; $h++) {
                if (!isset($byDate[$date])) {
                    $out[$o][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0];
                } elseif ($hasBands) {
                    $out[$o][$h] = $byDate[$date][$h];
                } else {
                    $w = $byDate[$date][$h];
                    $out[$o][$h] = ['p10' => $w, 'p50' => $w, 'p90' => $w];
                }
            }
        }
        return $out;
    }

    /** Gemessene Tages-kWh einer Leistungsvariablen (W) aus dem Archiv. */
    private function measuredKwh(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $ids = IPS_GetInstanceListByModuleID(PVF_ARCHIVE_GUID);
        if (count($ids) === 0) { return null; }
        $aid = $ids[0];

        $start = strtotime('today', $ts);
        $end   = $start + 86400 - 1;
        $rows  = AC_GetAggregatedValues($aid, $varID, 0, $start, $end, 0); // stündlich
        if (!is_array($rows) || count($rows) === 0) { return null; }
        $f  = $this->varPowerFactor($varID); // Einheit → W
        $wh = 0.0;
        foreach ($rows as $r) { $wh += (float)$r['Avg'] * $f; } // Ø-W × 1 h = Wh
        return $wh / 1000.0;
    }

    /** Faktor zur Umrechnung nach W: 0=W, 1=kW, 2=automatisch je Variable. */
    private function varPowerFactor(int $vid): float
    {
        $mode = $this->ReadPropertyInteger('PVF_PowerUnit');
        if ($mode === 0) { return 1.0; }
        if ($mode === 1) { return 1000.0; }
        if (isset($this->unitCache[$vid])) { return $this->unitCache[$vid]; }
        $f = $this->autoPowerFactor($vid);
        $this->unitCache[$vid] = $f;
        return $f;
    }

    /**
     * Automatische Einheiten-Erkennung: 1) Profil-Suffix („W"/„kW"),
     * 2) Größenordnung der Tagesmaxima (letzte 7 Tage, < 100 → kW), 3) W.
     */
    private function autoPowerFactor(int $vid): float
    {
        $v    = IPS_GetVariable($vid);
        $prof = ($v['VariableCustomProfile'] !== '') ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($prof !== '' && IPS_VariableProfileExists($prof)) {
            $suffix = strtolower(trim(IPS_GetVariableProfile($prof)['Suffix']));
            if ($suffix === 'kw') { return 1000.0; }
            if ($suffix === 'w')  { return 1.0; }
            if ($suffix === 'mw') { return 1000000.0; }
        }
        $ids = IPS_GetInstanceListByModuleID(PVF_ARCHIVE_GUID);
        if (count($ids) > 0) {
            $rows = @AC_GetAggregatedValues($ids[0], $vid, 1, strtotime('-7 days'), time(), 0);
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                foreach ($rows as $r) { $max = max($max, (float)$r['Max']); }
                if ($max > 0 && $max < 100) { return 1000.0; }
            }
        }
        return 1.0;
    }

    private function httpGetJson(string $url, array $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IP-Symcon PVForecast');
        if (count($headers) > 0) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            $this->log(PVF_LOG_BASIC, 'HTTP-Fehler ' . $code . ' ' . $err . ' bei ' . $url);
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private function emptyForecast(int $ts)
    {
        $zeros = array_fill(0, 24, 0.0);
        return [
            'date'       => date('Y-m-d', $ts),
            'slots'      => 24,
            'resolution' => '60min',
            'unit'       => 'W',
            'p10' => $zeros, 'p50' => $zeros, 'p90' => $zeros, 'mean' => $zeros,
            'kwh' => 0.0, 'neighbors' => 0,
        ];
    }

    private function log($level, $message)
    {
        $configLevel = $this->ReadPropertyInteger('PVF_Log_Level');
        if ($level > $configLevel) { return; }
        $prefix = ($level === PVF_LOG_VERBOSE) ? 'VERBOSE' : 'INFO';
        $this->SendDebug($prefix, $message, 0);
        if ($level <= PVF_LOG_BASIC) { IPS_LogMessage('PVPrognose', $message); }
    }
}
