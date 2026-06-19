<?php

// ============================================================
//  LoadForecast — Verbrauchsprognose für IP-Symcon
//  Autor   : DG65
//  Version : 0.1
//  GUID    : {DC5AD508-507F-40EA-8630-0959AED83050}
//
//  Konzept: Ähnliche-Tage-Verfahren (k-NN).
//  Für jeden Prognosetag wird ein Feature-Vektor gebildet
//  (Tagtyp, Tageslänge, Außentemperatur/Heizgrad, Anwesenheit).
//  Aus dem Archiv werden die k ähnlichsten Vergangenheitstage
//  gesucht und ihre Stundenprofile entfernungsgewichtet zu
//  P10/P50/P90 verdichtet. Optional: WP-Verbrauch separat über
//  lineare Temperaturregression (Heizgradtage).
// ============================================================

// Archive Control Modul-GUID (Kernmodul)
define('LFC_ARCHIVE_GUID', '{43192F0B-135B-4CE7-A0A7-1475603F3060}');

// OpenWeatherData (demel42) — für den Auto-Modus der Temperaturvorhersage.
// GUID stabil über alle Installationen; Instanz wird zur Laufzeit gesucht.
define('LFC_OWM_GUID',      '{8072158E-53BF-482A-B925-F4FBE522CEF2}');
define('LFC_OWM_IDENT_TIME','HourlyForecastBegin_%02d');
define('LFC_OWM_IDENT_MIN', 'HourlyForecastTemperatureMin_%02d');
define('LFC_OWM_IDENT_MAX', 'HourlyForecastTemperatureMax_%02d');
define('LFC_OWM_MAX_SLOTS', 50);

// Modi der Temperaturvorhersage
define('LFC_FC_AUTO',  0);  // OpenWeatherData automatisch, sonst Klimatologie
define('LFC_FC_DAILY', 1);  // drei Tagesmittel-Variablen
define('LFC_FC_IDENT', 2);  // Slot-Aggregation über frei wählbare Ident-Muster

// Logging-Level
define('LFC_LOG_OFF',     0);
define('LFC_LOG_BASIC',   1);
define('LFC_LOG_VERBOSE', 2);

// Tagtypen
define('LFC_DT_WORK', 0);   // Werktag (Mo–Fr, kein Feiertag)
define('LFC_DT_SAT',  1);   // Samstag
define('LFC_DT_SUN',  2);   // Sonntag / Feiertag

// Auflösung: Slots pro Tag (24 = stündlich). Bewusst stündlich
// gehalten — robust über AC_GetAggregatedValues (Stufe 0).
define('LFC_SLOTS', 24);

class LoadForecast extends IPSModule
{
    // Request-lokaler Cache der Prognosetemperaturen [0=>heute,1=>morgen,2=>übermorgen]
    private $fcTempCache = null;

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        // ── Allgemein ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('LFC_Active',         false);
        $this->RegisterPropertyInteger('LFC_IntervalHours',  6);
        $this->RegisterPropertyInteger('LFC_Log_Level',      LFC_LOG_BASIC);

        // ── Datenquellen (Archiv) ───────────────────────────────────
        // Hauptverbrauch als LEISTUNG (W) — z.B. EMS_HousePower.
        $this->RegisterPropertyInteger('VAR_Consumption',    0);
        // Optional abzuziehende Verbraucher (WP, Wallbox …) als Liste.
        $this->RegisterPropertyString('ExcludeVars',         '[]');
        // Außentemperatur (Historie, °C).
        $this->RegisterPropertyInteger('VAR_TempHistory',    0);
        // Anwesenheit (bool/0..1), Historie.
        $this->RegisterPropertyInteger('VAR_Presence',       0);

        // ── Prognose-Eingaben (Zukunft) ─────────────────────────────
        // Quelle der Vorhersagetemperatur. Bewusst modul-agnostisch:
        //   0 = Tagesmittel-Variablen (portabel, keine Abhängigkeit)
        //   1 = Slot-Aggregation über Ident-Muster (z.B. OWM/DWD)
        $this->RegisterPropertyInteger('LFC_TempFcMode',     0);

        // Modus 0: je eine Tagesmittel-Variable.
        $this->RegisterPropertyInteger('VAR_TempFc_D0',      0);
        $this->RegisterPropertyInteger('VAR_TempFc_D1',      0);
        $this->RegisterPropertyInteger('VAR_TempFc_D2',      0);

        // Modus 1: Eltern-Objekt + Ident-Muster der Slot-Variablen.
        // Platzhalter %d / %02d wird durch den Slot-Index ersetzt.
        $this->RegisterPropertyInteger('LFC_FcParentID',     0);
        $this->RegisterPropertyString('LFC_FcTempIdentLow',  '');
        $this->RegisterPropertyString('LFC_FcTempIdentHigh', '');
        $this->RegisterPropertyString('LFC_FcTimeIdent',     '');
        $this->RegisterPropertyInteger('LFC_FcStartIndex',   0);
        $this->RegisterPropertyInteger('LFC_FcCount',        40);

        // Geplante Anwesenheit (bool/0..1); leer = aktueller Zustand.
        $this->RegisterPropertyInteger('VAR_PresenceFc',     0);

        // ── Modellparameter ─────────────────────────────────────────
        $this->RegisterPropertyInteger('LFC_LookbackDays',   365);
        $this->RegisterPropertyInteger('LFC_K',              12);
        $this->RegisterPropertyFloat(  'LFC_Latitude',       49.0);
        $this->RegisterPropertyFloat(  'LFC_HDD_Base',       15.0);

        // ── Wärmepumpe (optional, separate Prognose) ────────────────
        $this->RegisterPropertyInteger('VAR_WP_Power',       0);

        // ── Ausgabe-Variablen ───────────────────────────────────────
        $this->RegisterVariableString('LFC_Today',     'Prognose heute (JSON)',     '', 10);
        $this->RegisterVariableString('LFC_Tomorrow',  'Prognose morgen (JSON)',    '', 20);
        $this->RegisterVariableString('LFC_DayAfter',  'Prognose übermorgen (JSON)','', 30);
        $this->RegisterVariableFloat( 'LFC_kWhToday',     'Erwartung heute (kWh)',    '~Electricity', 40);
        $this->RegisterVariableFloat( 'LFC_kWhTomorrow',  'Erwartung morgen (kWh)',   '~Electricity', 50);
        $this->RegisterVariableFloat( 'LFC_kWhDayAfter',  'Erwartung übermorgen (kWh)','~Electricity', 60);
        $this->RegisterVariableFloat( 'LFC_WPkWhTomorrow','Erwartung WP morgen (kWh)','~Electricity', 70);
        $this->RegisterVariableString('LFC_Status',    'Status',                    '', 80);
        $this->RegisterVariableInteger('LFC_LastUpdate','Letzte Berechnung',        '~UnixTimestamp', 90);

        // ── Timer ───────────────────────────────────────────────────
        $this->RegisterTimer('LFC_RebuildTimer', 0, 'LFC_Rebuild($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('LFC_Active');
        $hours  = max(1, $this->ReadPropertyInteger('LFC_IntervalHours'));

        if ($active) {
            $this->SetTimerInterval('LFC_RebuildTimer', $hours * 3600 * 1000);
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Aktiv, Neuberechnung alle ' . $hours . ' h');
        } else {
            $this->SetTimerInterval('LFC_RebuildTimer', 0);
            $this->SetStatus(104);
            $this->log(LFC_LOG_BASIC, 'Deaktiviert');
        }
    }

    // ----------------------------------------------------------------
    //  Öffentliche Funktionen
    // ----------------------------------------------------------------

    /**
     * Vollständige Neuberechnung aller Prognose-Horizonte.
     */
    public function Rebuild()
    {
        if ($this->ReadPropertyInteger('VAR_Consumption') <= 0) {
            $this->SetValue('LFC_Status', 'Keine Verbrauchsvariable konfiguriert');
            return;
        }

        try {
            $idents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter'];
            $kwhIds = ['LFC_kWhToday', 'LFC_kWhTomorrow', 'LFC_kWhDayAfter'];

            for ($offset = 0; $offset <= 2; $offset++) {
                $fc = $this->GetForecast($offset);
                $this->SetValue($idents[$offset], json_encode($fc));
                $this->SetValue($kwhIds[$offset], round($fc['kwh'], 2));
            }

            // Optional: separate WP-Prognose (morgen)
            $wp = $this->wpForecast(1);
            if ($wp !== null) {
                $this->SetValue('LFC_WPkWhTomorrow', round($wp, 2));
            }

            $this->SetValue('LFC_LastUpdate', time());
            $this->SetValue('LFC_Status', sprintf(
                'OK | heute %.1f / morgen %.1f / übermorgen %.1f kWh',
                $this->GetValue('LFC_kWhToday'),
                $this->GetValue('LFC_kWhTomorrow'),
                $this->GetValue('LFC_kWhDayAfter')
            ));
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Neuberechnung abgeschlossen');

        } catch (Exception $e) {
            $this->SetValue('LFC_Status', 'Fehler: ' . $e->getMessage());
            $this->log(LFC_LOG_BASIC, 'Fehler: ' . $e->getMessage());
        }
    }

    /**
     * Liefert die Prognose für einen Tag als Array.
     * $offset: 0 = heute, 1 = morgen, 2 = übermorgen.
     * Rückgabe: ['date','slots','p10','p50','p90','kwh','neighbors']
     * Für das EMS per LFC_GetForecast($id, $offset) abrufbar.
     */
    public function GetForecast(int $offset)
    {
        $targetTs = strtotime('today +' . $offset . ' days');
        $tf       = $this->dayFeatures($targetTs, true);

        $lookback = $this->ReadPropertyInteger('LFC_LookbackDays');
        $k        = max(1, $this->ReadPropertyInteger('LFC_K'));

        // Kandidaten: alle Tage von gestern rückwärts.
        $cands = [];
        for ($d = 1; $d <= $lookback; $d++) {
            $ts      = strtotime('today -' . $d . ' days');
            $profile = $this->getDayProfile($ts);
            if ($profile === null) {
                continue; // kein/zu wenig Datum an diesem Tag
            }
            $cf   = $this->dayFeatures($ts, false);
            $dist = $this->distance($tf, $cf);
            $cands[] = ['dist' => $dist, 'profile' => $profile];
        }

        if (count($cands) === 0) {
            return $this->emptyForecast($targetTs);
        }

        // k nächste Nachbarn auswählen.
        usort($cands, function ($a, $b) {
            return $a['dist'] <=> $b['dist'];
        });
        $neighbors = array_slice($cands, 0, $k);

        // Entfernungsgewichtung (Gauß-Kernel über mittlere Distanz).
        $distSum = 0.0;
        foreach ($neighbors as $n) { $distSum += $n['dist']; }
        $sigma   = max(0.0001, $distSum / max(1, count($neighbors)));
        foreach ($neighbors as $i => $n) {
            $neighbors[$i]['w'] = exp(-0.5 * ($n['dist'] * $n['dist']) / ($sigma * $sigma));
        }

        // Pro Slot P10/P50/P90 und gewichteten Mittelwert bilden.
        $p10 = []; $p50 = []; $p90 = []; $mean = [];
        for ($s = 0; $s < LFC_SLOTS; $s++) {
            $pairs = [];
            $wsum  = 0.0; $vsum = 0.0;
            foreach ($neighbors as $n) {
                $v = $n['profile'][$s];
                $pairs[] = ['v' => $v, 'w' => $n['w']];
                $wsum += $n['w'];
                $vsum += $n['w'] * $v;
            }
            $mean[$s] = ($wsum > 0) ? $vsum / $wsum : 0.0;
            $p10[$s]  = $this->weightedPercentile($pairs, 0.10);
            $p50[$s]  = $this->weightedPercentile($pairs, 0.50);
            $p90[$s]  = $this->weightedPercentile($pairs, 0.90);
        }

        $kwh = array_sum($mean) / 1000.0; // Wh → kWh (1 h pro Slot)

        return [
            'date'      => date('Y-m-d', $targetTs),
            'slots'     => LFC_SLOTS,
            'resolution'=> 'hourly',
            'unit'      => 'W',
            'p10'       => array_map(function ($x) { return round($x, 1); }, $p10),
            'p50'       => array_map(function ($x) { return round($x, 1); }, $p50),
            'p90'       => array_map(function ($x) { return round($x, 1); }, $p90),
            'mean'      => array_map(function ($x) { return round($x, 1); }, $mean),
            'kwh'       => round($kwh, 2),
            'neighbors' => count($neighbors),
        ];
    }

    public function GetStatusText()
    {
        return (string)$this->GetValue('LFC_Status');
    }

    // ----------------------------------------------------------------
    //  Feature-Engineering
    // ----------------------------------------------------------------

    /**
     * Bildet den Feature-Vektor eines Tages.
     * $future = true → Prognose-Eingaben (Wettervorhersage, geplante
     * Anwesenheit). $future = false → historische Archivwerte.
     */
    private function dayFeatures(int $ts, bool $future)
    {
        $dt   = $this->dayType($ts);
        $dl   = $this->dayLength($ts);

        if ($future) {
            $temp = $this->forecastTemp($ts);
            $pres = $this->forecastPresence();
        } else {
            $temp = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), $ts);
            if ($temp === null) { $temp = $this->ReadPropertyFloat('LFC_HDD_Base'); }
            $pres = $this->dailyMean($this->ReadPropertyInteger('VAR_Presence'), $ts);
            if ($pres === null) { $pres = 1.0; }
        }

        $base = $this->ReadPropertyFloat('LFC_HDD_Base');
        $hdd  = max(0.0, $base - $temp); // Heizgrad

        return ['dt' => $dt, 'dl' => $dl, 'hdd' => $hdd, 'pres' => $pres];
    }

    /**
     * Gewichtete euklidische Distanz im Feature-Raum.
     * Tagtyp kategorial (harter Aufschlag bei Abweichung), übrige
     * Features auf vergleichbare Skalen normiert.
     */
    private function distance(array $a, array $b)
    {
        // Gewichte (siehe README): Tagtyp dominiert die Form.
        $wDT = 4.0; $wDL = 1.0; $wHDD = 2.0; $wPres = 3.0;
        $sDL = 4.0;  // h
        $sHDD = 8.0; // K

        $d2  = $wDT  * (($a['dt'] !== $b['dt']) ? 1.0 : 0.0);
        $d2 += $wDL  * pow(($a['dl']  - $b['dl'])  / $sDL,  2);
        $d2 += $wHDD * pow(($a['hdd'] - $b['hdd']) / $sHDD, 2);
        $d2 += $wPres * pow(($a['pres'] - $b['pres']), 2);

        return sqrt($d2);
    }

    /**
     * Tagtyp: Sonntag/Feiertag = 2, Samstag = 1, sonst Werktag = 0.
     */
    private function dayType(int $ts)
    {
        if ($this->isHoliday($ts)) { return LFC_DT_SUN; }
        $wd = (int)date('N', $ts); // 1=Mo … 7=So
        if ($wd === 7) { return LFC_DT_SUN; }
        if ($wd === 6) { return LFC_DT_SAT; }
        return LFC_DT_WORK;
    }

    /**
     * Tageslänge (Sonnenscheindauer in Stunden) als Saison-Proxy.
     * CBM-Modell nach Forsythe et al., abhängig von Breitengrad
     * und Tag des Jahres.
     */
    private function dayLength(int $ts)
    {
        $lat = deg2rad($this->ReadPropertyFloat('LFC_Latitude'));
        $n   = (int)date('z', $ts) + 1; // Tag des Jahres 1..366
        $p   = asin(0.39795 * cos(0.2163108 + 2 * atan(0.9671396 * tan(0.00860 * ($n - 186)))));
        $arg = (sin(deg2rad(0.8333)) + sin($lat) * sin($p)) / (cos($lat) * cos($p));
        $arg = max(-1.0, min(1.0, $arg));
        return 24.0 - (24.0 / M_PI) * acos($arg);
    }

    /**
     * Deutsche bundesweite Feiertage (regionale ggf. ergänzen).
     */
    private function isHoliday(int $ts)
    {
        $y   = (int)date('Y', $ts);
        $md  = date('m-d', $ts);

        $fixed = ['01-01', '05-01', '10-03', '12-25', '12-26'];
        if (in_array($md, $fixed, true)) { return true; }

        // Osterbasierte Feiertage
        $easter = easter_date($y); // Ostersonntag (Mittag)
        $movable = [
            strtotime('-2 days', $easter),  // Karfreitag
            strtotime('+1 day',  $easter),  // Ostermontag
            strtotime('+39 days',$easter),  // Christi Himmelfahrt
            strtotime('+50 days',$easter),  // Pfingstmontag
        ];
        foreach ($movable as $m) {
            if (date('Y-m-d', $m) === date('Y-m-d', $ts)) { return true; }
        }
        return false;
    }

    // ----------------------------------------------------------------
    //  Archivzugriff
    // ----------------------------------------------------------------

    /**
     * Stundenprofil (LFC_SLOTS Werte, Ø-Leistung in W) eines Tages.
     * Zieht optional konfigurierte Verbraucher (WP, Wallbox) ab,
     * sodass die planbare Grundlast übrig bleibt.
     * Rückgabe null, wenn der Tag kaum Daten hat.
     */
    private function getDayProfile(int $ts)
    {
        $main = $this->hourlyProfile($this->ReadPropertyInteger('VAR_Consumption'), $ts);
        if ($main === null) { return null; }

        $excludes = json_decode($this->ReadPropertyString('ExcludeVars'), true);
        if (is_array($excludes)) {
            foreach ($excludes as $row) {
                $vid = isset($row['VariableID']) ? (int)$row['VariableID'] : 0;
                if ($vid <= 0) { continue; }
                $sub = $this->hourlyProfile($vid, $ts);
                if ($sub === null) { continue; }
                for ($s = 0; $s < LFC_SLOTS; $s++) {
                    $main[$s] = max(0.0, $main[$s] - $sub[$s]);
                }
            }
        }
        return $main;
    }

    /**
     * Stündliche Ø-Leistung (W) einer Variablen für einen Tag.
     * Erwartet eine LEISTUNGS-Variable (W). Rückgabe null bei
     * unzureichender Datenlage (< halber Tag belegt).
     */
    private function hourlyProfile(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0) { return null; }

        $start = strtotime('today', $ts);
        $end   = $start + 86400 - 1;

        $rows = AC_GetAggregatedValues($aid, $varID, 0 /* stündlich */, $start, $end, 0);
        if (!is_array($rows) || count($rows) === 0) { return null; }

        $profile = array_fill(0, LFC_SLOTS, null);
        foreach ($rows as $r) {
            $h = (int)date('G', $r['TimeStamp']);
            if ($h >= 0 && $h < LFC_SLOTS) {
                $profile[$h] = (float)$r['Avg'];
            }
        }

        // Lücken füllen (Nachbarinterpolation) und Mindestabdeckung prüfen.
        $have = 0;
        foreach ($profile as $v) { if ($v !== null) { $have++; } }
        if ($have < LFC_SLOTS / 2) { return null; }

        $last = 0.0;
        for ($s = 0; $s < LFC_SLOTS; $s++) {
            if ($profile[$s] === null) { $profile[$s] = $last; }
            else { $last = $profile[$s]; }
        }
        return $profile;
    }

    /**
     * Tagesmittel einer Variablen aus dem Archiv (z.B. Temperatur,
     * Anwesenheitsanteil). Rückgabe null bei fehlenden Daten.
     */
    private function dailyMean(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0) { return null; }

        $start = strtotime('today', $ts);
        $end   = $start + 86400 - 1;

        $rows = AC_GetAggregatedValues($aid, $varID, 1 /* täglich */, $start, $end, 0);
        if (!is_array($rows) || count($rows) === 0) { return null; }
        return (float)$rows[0]['Avg'];
    }

    private function getArchiveID()
    {
        $ids = IPS_GetInstanceListByModuleID(LFC_ARCHIVE_GUID);
        return (count($ids) > 0) ? $ids[0] : 0;
    }

    // ----------------------------------------------------------------
    //  Prognose-Eingaben
    // ----------------------------------------------------------------

    private function forecastTemp(int $ts)
    {
        if ($this->fcTempCache === null) {
            $this->fcTempCache = $this->buildForecastTemps();
        }
        $offset = (int)round(($ts - strtotime('today')) / 86400);
        if (isset($this->fcTempCache[$offset]) && $this->fcTempCache[$offset] !== null) {
            return $this->fcTempCache[$offset];
        }
        // Fallback-Kaskade: saisonales Normal (Klimatologie) → gestern → Basis.
        $clim = $this->climatologyTemp($ts);
        if ($clim !== null) { return $clim; }
        $t = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), strtotime('yesterday'));
        return ($t !== null) ? $t : $this->ReadPropertyFloat('LFC_HDD_Base');
    }

    /**
     * Ermittelt die Vorhersage-Tagesmittel [0=>heute,1=>morgen,2=>übermorgen].
     * Modul-agnostisch: keine Instanz-ID fest verdrahtet. Nicht ermittelbare
     * Tage bleiben null (Klimatologie-Fallback greift in forecastTemp).
     */
    private function buildForecastTemps()
    {
        $mode = $this->ReadPropertyInteger('LFC_TempFcMode');

        switch ($mode) {
            case LFC_FC_DAILY:
                return $this->forecastFromDailyVars();

            case LFC_FC_IDENT:
                return $this->forecastFromIdentPattern();

            case LFC_FC_AUTO:
            default:
                // OpenWeatherData automatisch finden und auswerten.
                $owm = $this->owmInstance();
                if ($owm > 0) {
                    $res = $this->aggregateForecastSlots(
                        $owm, LFC_OWM_IDENT_TIME, LFC_OWM_IDENT_MIN, LFC_OWM_IDENT_MAX,
                        0, LFC_OWM_MAX_SLOTS
                    );
                    if ($res[0] !== null || $res[1] !== null || $res[2] !== null) {
                        return $res;
                    }
                    $this->log(LFC_LOG_VERBOSE, 'OWM gefunden, aber keine Stundenvorhersage (aktiviert?)');
                }
                // Sonst leer lassen → Klimatologie übernimmt in forecastTemp.
                return [0 => null, 1 => null, 2 => null];
        }
    }

    /** Modus DAILY: drei direkte Tagesmittel-Variablen. */
    private function forecastFromDailyVars()
    {
        $out = [0 => null, 1 => null, 2 => null];
        $map = [0 => 'VAR_TempFc_D0', 1 => 'VAR_TempFc_D1', 2 => 'VAR_TempFc_D2'];
        foreach ($map as $off => $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $out[$off] = (float)GetValue($vid);
            }
        }
        return $out;
    }

    /** Modus IDENT: frei konfigurierte Slot-Idents aggregieren. */
    private function forecastFromIdentPattern()
    {
        $parent  = $this->ReadPropertyInteger('LFC_FcParentID');
        $patLow  = trim($this->ReadPropertyString('LFC_FcTempIdentLow'));
        $patHigh = trim($this->ReadPropertyString('LFC_FcTempIdentHigh'));
        $patTime = trim($this->ReadPropertyString('LFC_FcTimeIdent'));
        $start   = $this->ReadPropertyInteger('LFC_FcStartIndex');
        $count   = $this->ReadPropertyInteger('LFC_FcCount');

        if ($parent <= 0 || !IPS_ObjectExists($parent) || $patLow === '' || $patTime === '') {
            $this->log(LFC_LOG_VERBOSE, 'Temp-Vorhersage Ident-Modus unvollständig konfiguriert');
            return [0 => null, 1 => null, 2 => null];
        }
        return $this->aggregateForecastSlots($parent, $patTime, $patLow, $patHigh, $start, $count);
    }

    /**
     * Aggregiert nummerierte Slot-Variablen zu Tagesmitteln je Kalendertag.
     * Bucketing über den Unix-Zeitstempel jedes Slots; (Min+Max)/2, falls
     * ein Max-Muster angegeben ist. Wird von Auto- und Ident-Modus genutzt.
     */
    private function aggregateForecastSlots(int $parent, string $patTime, string $patLow, string $patHigh, int $start, int $count)
    {
        $today = strtotime('today');
        $sum   = [0 => 0.0, 1 => 0.0, 2 => 0.0];
        $cnt   = [0 => 0,   1 => 0,   2 => 0];

        for ($i = $start; $i < $start + $count; $i++) {
            $tId = $this->identToVar($parent, $patTime, $i);
            $lId = $this->identToVar($parent, $patLow,  $i);
            if ($tId === 0 || $lId === 0) { continue; }

            $slotTs = (int)GetValue($tId);
            if ($slotTs <= 0) { continue; }
            $off = (int)floor(($slotTs - $today) / 86400);
            if ($off < 0 || $off > 2) { continue; } // nur heute..übermorgen

            $val = (float)GetValue($lId);
            if ($patHigh !== '') {
                $hId = $this->identToVar($parent, $patHigh, $i);
                if ($hId !== 0) { $val = ($val + (float)GetValue($hId)) / 2.0; }
            }
            $sum[$off] += $val;
            $cnt[$off]++;
        }

        $out = [0 => null, 1 => null, 2 => null];
        foreach ([0, 1, 2] as $off) {
            if ($cnt[$off] > 0) { $out[$off] = $sum[$off] / $cnt[$off]; }
        }
        return $out;
    }

    /** Erste OpenWeatherData-Instanz im System (0 = keine vorhanden). */
    private function owmInstance()
    {
        $ids = IPS_GetInstanceListByModuleID(LFC_OWM_GUID);
        return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
    }

    /**
     * Saisonales Normal (Klimatologie) als Fallback ohne Vorhersage:
     * Mittel desselben Kalendertags ±7 Tage über alle verfügbaren
     * Vorjahre aus dem Temperatur-Archiv. Nutzt „die Daten vom letzten
     * Jahr", aber geglättet statt eines einzelnen verrauschten Tages.
     * Rückgabe null, wenn keine Temperatur-Historie konfiguriert ist.
     */
    private function climatologyTemp(int $ts)
    {
        $vid = $this->ReadPropertyInteger('VAR_TempHistory');
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }

        $yearsBack = max(1, (int)ceil($this->ReadPropertyInteger('LFC_LookbackDays') / 365));
        $yearsBack = min(5, $yearsBack);

        $sum = 0.0; $n = 0;
        for ($y = 1; $y <= $yearsBack; $y++) {
            for ($d = -7; $d <= 7; $d++) {
                $day = strtotime("-{$y} year {$d} day", $ts);
                $m   = $this->dailyMean($vid, $day);
                if ($m !== null) { $sum += $m; $n++; }
            }
        }
        return ($n > 0) ? $sum / $n : null;
    }

    /**
     * Löst eine Slot-Variable über ein Ident-Muster relativ zum
     * Eltern-Objekt auf. Muster mit %d/%02d wird per Index ersetzt.
     * Rückgabe 0, wenn nicht vorhanden.
     */
    private function identToVar(int $parent, string $pattern, int $index)
    {
        $ident = (strpos($pattern, '%') !== false) ? sprintf($pattern, $index) : $pattern;
        $id    = @IPS_GetObjectIDByIdent($ident, $parent);
        return ($id !== false && $id > 0 && IPS_VariableExists($id)) ? $id : 0;
    }

    private function forecastPresence()
    {
        $vid = $this->ReadPropertyInteger('VAR_PresenceFc');
        if ($vid <= 0) { $vid = $this->ReadPropertyInteger('VAR_Presence'); }
        if ($vid > 0 && IPS_VariableExists($vid)) {
            return (float)GetValue($vid);
        }
        return 1.0;
    }

    // ----------------------------------------------------------------
    //  Wärmepumpe — separate Temperaturregression (optional)
    // ----------------------------------------------------------------

    /**
     * Erwarteter WP-Tagesverbrauch (kWh) für $offset Tage.
     * Lineares Modell kWh = a + b · Heizgrad, gefittet über die
     * Historie. Rückgabe null, wenn keine WP-Variable konfiguriert
     * ist oder zu wenige Datenpunkte vorliegen.
     */
    private function wpForecast(int $offset)
    {
        $vid = $this->ReadPropertyInteger('VAR_WP_Power');
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }

        $lookback = min(180, $this->ReadPropertyInteger('LFC_LookbackDays'));
        $base     = $this->ReadPropertyFloat('LFC_HDD_Base');

        $xs = []; $ys = [];
        for ($d = 1; $d <= $lookback; $d++) {
            $ts   = strtotime('today -' . $d . ' days');
            $prof = $this->hourlyProfile($vid, $ts);
            $temp = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), $ts);
            if ($prof === null || $temp === null) { continue; }
            $kwh  = array_sum($prof) / 1000.0;
            $xs[] = max(0.0, $base - $temp);
            $ys[] = $kwh;
        }
        if (count($xs) < 10) { return null; }

        // Lineare Regression (Least Squares).
        $n = count($xs);
        $sx = array_sum($xs); $sy = array_sum($ys);
        $sxx = 0.0; $sxy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxx += $xs[$i] * $xs[$i];
            $sxy += $xs[$i] * $ys[$i];
        }
        $denom = ($n * $sxx - $sx * $sx);
        if (abs($denom) < 1e-9) { return $sy / $n; }
        $b = ($n * $sxy - $sx * $sy) / $denom;
        $a = ($sy - $b * $sx) / $n;

        $targetTs = strtotime('today +' . $offset . ' days');
        $hdd      = max(0.0, $base - $this->forecastTemp($targetTs));
        return max(0.0, $a + $b * $hdd);
    }

    // ----------------------------------------------------------------
    //  Hilfsfunktionen
    // ----------------------------------------------------------------

    /**
     * Gewichtetes Perzentil aus [['v'=>wert,'w'=>gewicht], …].
     */
    private function weightedPercentile(array $pairs, float $p)
    {
        if (count($pairs) === 0) { return 0.0; }
        usort($pairs, function ($x, $y) { return $x['v'] <=> $y['v']; });

        $total = 0.0;
        foreach ($pairs as $pp) { $total += $pp['w']; }
        if ($total <= 0) { return $pairs[0]['v']; }

        $cum = 0.0;
        $target = $p * $total;
        foreach ($pairs as $pp) {
            $cum += $pp['w'];
            if ($cum >= $target) { return $pp['v']; }
        }
        return end($pairs)['v'];
    }

    private function emptyForecast(int $ts)
    {
        $zeros = array_fill(0, LFC_SLOTS, 0.0);
        return [
            'date'      => date('Y-m-d', $ts),
            'slots'     => LFC_SLOTS,
            'resolution'=> 'hourly',
            'unit'      => 'W',
            'p10' => $zeros, 'p50' => $zeros, 'p90' => $zeros, 'mean' => $zeros,
            'kwh' => 0.0, 'neighbors' => 0,
        ];
    }

    private function log($level, $message)
    {
        $configLevel = $this->ReadPropertyInteger('LFC_Log_Level');
        if ($level > $configLevel) { return; }
        $prefix = ($level === LFC_LOG_VERBOSE) ? 'VERBOSE' : 'INFO';
        $this->SendDebug($prefix, $message, 0);
        if ($level <= LFC_LOG_BASIC) {
            IPS_LogMessage('LoadForecast', $message);
        }
    }
}
