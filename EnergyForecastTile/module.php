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
class EnergyForecastTile extends IPSModule
{
    private const SOURCE_PV   = '{257DD4E8-9705-462E-89FC-56D0A1038353}'; // PVForecast
    private const SOURCE_LOAD = '{DC5AD508-507F-40EA-8630-0959AED83050}'; // LoadForecast

    private const DEF_PV    = 0xE0A020; // Bernstein
    private const DEF_LOAD  = 0x2BB3C0; // Türkis
    private const DEF_BG    = -1;
    private const DEF_SCALE = 1.0;
    private const DEF_DAYS  = 3;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PVSource',   0);
        $this->RegisterPropertyInteger('LoadSource', 0);
        $this->RegisterPropertyInteger('Days',       self::DEF_DAYS);
        $this->RegisterPropertyInteger('ColorPV',    self::DEF_PV);
        $this->RegisterPropertyInteger('ColorLoad',  self::DEF_LOAD);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BG);
        $this->RegisterPropertyFloat('FontScale',    self::DEF_SCALE);

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
        ];

        $limit = max(1, min(3, $this->ReadPropertyInteger('Days')));
        $labels = ['heute', 'morgen', 'übermorgen'];

        $pvSrc   = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        $loadSrc = $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource');

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

        return json_encode(array_merge($style, [
            'hasData' => $hasData,
            'message' => $hasData ? '' : 'Keine Prognosedaten',
            'days'    => $days,
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
                'kwh' => round((float) ($fc['kwh'] ?? 0), 1),
            ];
        }
        return $out;
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

    private function ColorHex(int $value, string $fallback): string
    {
        return ($value < 0) ? $fallback : sprintf('#%06x', $value);
    }

    private function ColorOrEmpty(int $value): string
    {
        return ($value < 0) ? '' : sprintf('#%06x', $value);
    }
}
