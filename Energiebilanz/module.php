<?php

declare(strict_types=1);

/**
 * EnergyForecastTile
 *
 * Kombinierte HTML-SDK-Kachel: zeigt PV-Erzeugungsprognose (PVForecast) und
 * — falls vorhanden — Verbrauchsprognose (LoadForecast) gemeinsam als zwei
 * P10/P50/P90-Bänder in einem Diagramm. Beide Quellen werden automatisch per
 * Modul-GUID gefunden; die Kachel funktioniert auch PV-only.
 */
class Energiebilanz extends IPSModule
{
    private const SOURCE_PV   = '{257DD4E8-9705-462E-89FC-56D0A1038353}'; // PVForecast
    private const SOURCE_LOAD = '{DC5AD508-507F-40EA-8630-0959AED83050}'; // LoadForecast

    private const DEF_PV    = 0xE0A020; // Bernstein
    private const DEF_LOAD  = 0x2BB3C0; // Türkis
    private const DEF_BG     = -1;
    private const DEF_SCALE  = 1.0;
    private const DEF_DAYS   = 3;
    private const DEF_LW     = 2.0;
    private const DEF_SMOOTH = true;
    private const DEF_BAND   = true;
    private const DEF_BANDOP = 0.16;
    private const DEF_GRID   = true;
    private const DEF_YMAX   = 0.0; // 0 = automatisch
    private const DEF_FONT   = 'system';
    private const DEF_HEIGHT = 360;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PVSource',   0);
        $this->RegisterPropertyInteger('LoadSource', 0);
        $this->RegisterPropertyBoolean('ShowPV',     true);
        $this->RegisterPropertyBoolean('ShowLoad',   true);
        // Ist-Werte (momentane Leistung in W) — optional, für Prognose vs. Realität.
        $this->RegisterPropertyInteger('ActualPV',   0);
        $this->RegisterPropertyInteger('ActualLoad', 0);
        // Gemessenen Tagesverlauf (heute) als Overlay-Linie zeichnen.
        $this->RegisterPropertyBoolean('ShowActualPV',   false);
        $this->RegisterPropertyBoolean('ShowActualLoad', false);
        // Ist-Verlauf nur alle … Sekunden neu aus dem Archiv integrieren (Cache).
        $this->RegisterPropertyInteger('MeasuredCacheSec', 120);
        $this->RegisterAttributeString('MeasuredCache', '');
        $this->RegisterPropertyInteger('Days',       self::DEF_DAYS);
        $this->RegisterPropertyInteger('ColorPV',    self::DEF_PV);
        $this->RegisterPropertyInteger('ColorLoad',  self::DEF_LOAD);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BG);
        $this->RegisterPropertyFloat('FontScale',    self::DEF_SCALE);

        // Darstellungsoptionen
        $this->RegisterPropertyFloat('LineWidth',    self::DEF_LW);
        $this->RegisterPropertyBoolean('Smooth',     self::DEF_SMOOTH);
        $this->RegisterPropertyBoolean('ShowBand',   self::DEF_BAND);
        $this->RegisterPropertyFloat('BandOpacity',  self::DEF_BANDOP);
        $this->RegisterPropertyBoolean('ShowGrid',   self::DEF_GRID);
        $this->RegisterPropertyFloat('YMaxManual',   self::DEF_YMAX);
        $this->RegisterPropertyString('FontFamily',  self::DEF_FONT);
        $this->RegisterPropertyInteger('ChartHeight', self::DEF_HEIGHT);

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->WriteAttributeString('MeasuredCache', ''); // Cache bei Konfig-Änderung verwerfen

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) { $this->UnregisterMessage($senderID, VM_UPDATE); }
            }
        }

        $found = false;
        $pv = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        if ($pv > 0) {
            foreach (['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $pv);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        $load = $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource');
        if ($load > 0) {
            foreach (['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $load);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        // Ist-Wert-Variablen live abonnieren (momentane Leistung).
        foreach (['ActualPV', 'ActualLoad'] as $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $this->RegisterMessage($vid, VM_UPDATE);
                $found = true;
            }
        }
        $this->SetStatus($found ? 102 : 104);

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode(json_decode(file_get_contents(__DIR__ . '/form.json'), true));
    }

    public function ResetStyle(): void
    {
        $this->UpdateFormField('Days', 'value', self::DEF_DAYS);
        $this->UpdateFormField('ColorPV', 'value', self::DEF_PV);
        $this->UpdateFormField('ColorLoad', 'value', self::DEF_LOAD);
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BG);
        $this->UpdateFormField('FontScale', 'value', self::DEF_SCALE);
        $this->UpdateFormField('LineWidth', 'value', self::DEF_LW);
        $this->UpdateFormField('Smooth', 'value', self::DEF_SMOOTH);
        $this->UpdateFormField('ShowBand', 'value', self::DEF_BAND);
        $this->UpdateFormField('BandOpacity', 'value', self::DEF_BANDOP);
        $this->UpdateFormField('ShowGrid', 'value', self::DEF_GRID);
        $this->UpdateFormField('YMaxManual', 'value', self::DEF_YMAX);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
        $this->UpdateFormField('ChartHeight', 'value', self::DEF_HEIGHT);
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $style = [
            'pvColor'   => $this->ColorHex($this->ReadPropertyInteger('ColorPV'), '#e0a020'),
            'loadColor' => $this->ColorHex($this->ReadPropertyInteger('ColorLoad'), '#2bb3c0'),
            'bg'        => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'scale'     => $this->FontScaleValue(),
            'lineWidth' => max(0.5, min(6.0, $this->ReadPropertyFloat('LineWidth'))),
            'smooth'    => $this->ReadPropertyBoolean('Smooth'),
            'showBand'  => $this->ReadPropertyBoolean('ShowBand'),
            'bandOp'    => max(0.0, min(0.6, $this->ReadPropertyFloat('BandOpacity'))),
            'showGrid'  => $this->ReadPropertyBoolean('ShowGrid'),
            'yMaxManual'=> max(0.0, $this->ReadPropertyFloat('YMaxManual')),
            'font'      => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'height'    => max(180, $this->ReadPropertyInteger('ChartHeight')),
        ];

        $limit = max(1, min(3, $this->ReadPropertyInteger('Days')));
        $labels = ['heute', 'morgen', 'übermorgen'];

        $showPV   = $this->ReadPropertyBoolean('ShowPV');
        $showLoad = $this->ReadPropertyBoolean('ShowLoad');

        $pvSrc   = $showPV   ? $this->ResolveSource(self::SOURCE_PV, 'PVSource')   : 0;
        $loadSrc = $showLoad ? $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource') : 0;

        $pvDays   = $this->ReadSeries($pvSrc,   ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter'], $limit);
        $loadDays = $this->ReadSeries($loadSrc, ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter'], $limit);

        $days = [];
        $hasData = false;
        for ($i = 0; $i < $limit; $i++) {
            $pv   = $pvDays[$i]   ?? null;
            $load = $loadDays[$i] ?? null;
            if ($pv !== null || $load !== null) { $hasData = true; }
            $days[] = ['label' => $labels[$i], 'pv' => $pv, 'load' => $load];
        }

        // Ist-Werte (momentane Leistung in W), nur wenn die Reihe sichtbar ist.
        $actualPV   = $showPV   ? $this->readActual('ActualPV')   : null;
        $actualLoad = $showLoad ? $this->readActual('ActualLoad') : null;

        // Gemessener Tagesverlauf (heute) als Overlay — auf das Slot-Raster
        // des jeweiligen Tag-0-Prognoseprofils gebracht.
        $measuredPV = null; $measuredLoad = null;
        if ($showPV && $this->ReadPropertyBoolean('ShowActualPV') && ($days[0]['pv'] ?? null) !== null) {
            $measuredPV = $this->measuredCached('pv', $this->ReadPropertyInteger('ActualPV'), count($days[0]['pv']['p50']));
        }
        if ($showLoad && $this->ReadPropertyBoolean('ShowActualLoad') && ($days[0]['load'] ?? null) !== null) {
            $measuredLoad = $this->measuredCached('load', $this->ReadPropertyInteger('ActualLoad'), count($days[0]['load']['p50']));
        }

        return json_encode(array_merge($style, [
            'hasData'      => $hasData,
            'message'      => $hasData ? '' : 'Keine Prognosedaten',
            'days'         => $days,
            'actualPV'     => $actualPV,
            'actualLoad'   => $actualLoad,
            'measuredPV'   => $measuredPV,
            'measuredLoad' => $measuredLoad,
        ]));
    }

    /** Liest die JSON-Prognosevariablen einer Quelle in [Tag => {p10,p50,p90,kwh}|null]. */
    private function ReadSeries(int $src, array $idents, int $limit): array
    {
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $out[$i] = null;
            if ($src <= 0) { continue; }
            $raw = $this->ReadSourceValue($src, $idents[$i], '');
            $fc  = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($fc) || !isset($fc['p50']) || !is_array($fc['p50'])) { continue; }
            $out[$i] = [
                'p10' => array_map('floatval', $fc['p10'] ?? []),
                'p50' => array_map('floatval', $fc['p50']),
                'p90' => array_map('floatval', $fc['p90'] ?? []),
                'kwh' => round((float) ($fc['kwh'] ?? 0), 2),
            ];
        }
        return $out;
    }

    /** Momentane Leistung (W) einer Ist-Wert-Variablen; null wenn unkonfiguriert. */
    private function readActual(string $prop)
    {
        $vid = $this->ReadPropertyInteger($prop);
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        return (float) GetValue($vid);
    }

    /**
     * Gemessener Tagesverlauf (heute) einer Leistungsvariablen, auf $slots
     * Slots gebracht (stündliches Archivaggregat → auf Raster expandiert).
     * Nicht belegte/zukünftige Slots = null. Rückgabe null ohne Archiv/Daten.
     */
    /**
     * Wie readMeasured(), aber mit Cache: integriert den Ist-Verlauf nur alle
     * MeasuredCacheSec Sekunden neu (Archiv-Zugriff), dazwischen aus dem
     * Attribut. Der „jetzt"-Punkt/Legendenwert bleibt davon unberührt (live).
     */
    private function measuredCached(string $key, int $vid, int $slots)
    {
        $ttl   = max(15, $this->ReadPropertyInteger('MeasuredCacheSec'));
        $today = date('Y-m-d');

        $cache = json_decode($this->ReadAttributeString('MeasuredCache'), true);
        if (!is_array($cache)) { $cache = []; }

        $e = $cache[$key] ?? null;
        if (is_array($e)
            && ($e['day'] ?? '') === $today
            && (int) ($e['vid'] ?? 0) === $vid
            && (int) ($e['slots'] ?? 0) === $slots
            && (time() - (int) ($e['ts'] ?? 0)) < $ttl) {
            return $e['data'];
        }

        $data = $this->readMeasured($vid, $slots);
        $cache[$key] = ['ts' => time(), 'day' => $today, 'vid' => $vid, 'slots' => $slots, 'data' => $data];
        $this->WriteAttributeString('MeasuredCache', json_encode($cache));
        return $data;
    }

    private function readMeasured(int $vid, int $slots)
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0) { return null; }

        $start = strtotime('today');

        // 60 min: stündliches Aggregat (exakt, leichtgewichtig).
        if ($slots <= 24) {
            $rows = AC_GetAggregatedValues($aid, $vid, 0, $start, $start + 86400 - 1, 0);
            if (!is_array($rows) || count($rows) === 0) { return null; }
            $out = array_fill(0, $slots, null);
            foreach ($rows as $r) {
                $h = (int) date('G', $r['TimeStamp']);
                if ($h >= 0 && $h < $slots) { $out[$h] = (float) $r['Avg']; }
            }
            return $out;
        }

        // 30/15 min: zeitgewichtet aus den Rohwerten (keine Treppenstufen).
        return $this->measuredFine($aid, $vid, $start, $slots);
    }

    /**
     * Gemessenes Slot-Profil (heute bis „jetzt") zeitgewichtet aus den
     * Rohwerten: jeder geloggte Wert gilt bis zum nächsten Wechsel,
     * Ø-Leistung je Slot = Σ v·Δt / Σ Δt. Zukünftige Slots = null.
     */
    private function measuredFine(int $aid, int $vid, int $start, int $slots)
    {
        $until   = min($start + 86400, time());
        $slotSec = 86400.0 / $slots;

        $carry = null;
        $pre = AC_GetLoggedValues($aid, $vid, 0, $start - 1, 1);
        if (is_array($pre) && count($pre) > 0) { $carry = (float) $pre[0]['Value']; }

        $rows = AC_GetLoggedValues($aid, $vid, $start, $until, 0);
        if (!is_array($rows)) { $rows = []; }
        usort($rows, function ($a, $b) { return $a['TimeStamp'] <=> $b['TimeStamp']; });

        $points = [];
        $first  = ($carry !== null) ? $carry : (count($rows) > 0 ? (float) $rows[0]['Value'] : null);
        $points[] = ['t' => $start, 'v' => $first];
        foreach ($rows as $r) {
            $t = (int) $r['TimeStamp'];
            if ($t > $start && $t <= $until) { $points[] = ['t' => $t, 'v' => (float) $r['Value']]; }
        }
        if ($first === null && count($points) <= 1) { return null; }

        $sumW = array_fill(0, $slots, 0.0);
        $sumS = array_fill(0, $slots, 0.0);
        $cnt  = count($points);
        for ($p = 0; $p < $cnt; $p++) {
            $v = $points[$p]['v'];
            if ($v === null) { continue; }
            $t0 = $points[$p]['t'];
            $t1 = ($p + 1 < $cnt) ? $points[$p + 1]['t'] : $until;
            while ($t0 < $t1) {
                $slot = (int) (($t0 - $start) / $slotSec);
                if ($slot < 0 || $slot >= $slots) { break; }
                $slotEnd = $start + ($slot + 1) * $slotSec;
                $segEnd  = min($t1, $slotEnd);
                $dur     = $segEnd - $t0;
                $sumW[$slot] += $v * $dur;
                $sumS[$slot] += $dur;
                $t0 = $segEnd;
            }
        }
        $out = array_fill(0, $slots, null);
        for ($s = 0; $s < $slots; $s++) {
            if ($sumS[$s] > 0) { $out[$s] = $sumW[$s] / $sumS[$s]; }
        }
        return $out;
    }

    private function getArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (count($ids) > 0) ? (int) $ids[0] : 0;
    }

    private function ResolveSource(string $guid, string $prop): int
    {
        $configured = $this->ReadPropertyInteger($prop);
        if ($configured > 0 && IPS_InstanceExists($configured)) { return $configured; }
        $list = IPS_GetInstanceListByModuleID($guid);
        return (count($list) === 1) ? (int) $list[0] : 0;
    }

    private function ReadSourceValue(int $instanceID, string $ident, $default)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) { return $default; }
        return GetValue($vid);
    }

    private function FontScaleValue(): float
    {
        $s = $this->ReadPropertyFloat('FontScale');
        return max(0.5, min(2.5, $s));
    }

    private function FontStack(string $key): string
    {
        switch ($key) {
            case 'arial':     return 'Arial, Helvetica, sans-serif';
            case 'verdana':   return 'Verdana, Geneva, sans-serif';
            case 'tahoma':    return 'Tahoma, Geneva, sans-serif';
            case 'trebuchet': return '"Trebuchet MS", Helvetica, sans-serif';
            case 'georgia':   return 'Georgia, "Times New Roman", serif';
            case 'courier':   return '"Courier New", Courier, monospace';
            case 'system':
            default:          return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }

    private function ColorHex(int $value, string $fallback): string
    {
        return ($value < 0) ? $fallback : sprintf('#%06x', $value);
    }

    private function ColorOrEmpty(int $value): string
    {
        return ($value < 0) ? '' : sprintf('#%06x', $value);
    }
}
