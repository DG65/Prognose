<?php

declare(strict_types=1);

/**
 * LoadForecastTile
 *
 * Eigenständige HTML-SDK-Kachel für die Tile-Visualisierung. Liest die
 * Prognose-Variablen einer LoadForecast-Instanz (Quelle) und zeichnet das
 * P10/P50/P90-Band über die nächsten 1–3 Tage als SVG-Diagramm.
 *
 * Bewusst von der Datenlogik getrennt: Ein Problem in der Kachel kann die
 * Prognoseberechnung der Quell-Instanz nicht beeinträchtigen.
 */
class LastprognoseKachel extends IPSModule
{
    // GUID des Datenmoduls LoadForecast (für die Quellen-Auswahl)
    private const SOURCE_MODULE = '{DC5AD508-507F-40EA-8630-0959AED83050}';

    // Standardwerte (auch für „Zurücksetzen")
    private const DEF_ACCENT     = 0x2BB3C0;
    private const DEF_BACKGROUND  = -1; // -1 = IPS-Theme (transparent, Text automatisch)
    private const DEF_SCALE       = 1.0;
    private const DEF_DAYS        = 3;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyInteger('Days', self::DEF_DAYS);
        $this->RegisterPropertyInteger('ColorAccent', self::DEF_ACCENT);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyFloat('FontScale', self::DEF_SCALE);

        // Als HTML-Kachel-Visualisierung anmelden
        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        // Bisherige VM_UPDATE-Registrierungen lösen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Auf Änderungen der Prognose-Variablen lauschen
        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            foreach (['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $src);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

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
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    /** Button-Aktion: Darstellung auf Standard zurücksetzen (nur im offenen Formular). */
    public function ResetStyle(): void
    {
        $this->UpdateFormField('Days', 'value', self::DEF_DAYS);
        $this->UpdateFormField('ColorAccent', 'value', self::DEF_ACCENT);
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontScale', 'value', self::DEF_SCALE);
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() ist erst im HTML definiert -> initialen Aufruf ans Ende hängen.
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------
    // Datenaufbereitung
    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $style = [
            'accent' => $this->ColorHex($this->ReadPropertyInteger('ColorAccent'), '#2bb3c0'),
            'bg'     => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'scale'  => $this->FontScaleValue(),
        ];

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'hasData' => false,
                'message' => 'Keine Prognose-Instanz ausgewählt',
                'days'    => [],
            ]));
        }

        $limit = max(1, min(3, $this->ReadPropertyInteger('Days')));
        $map   = [
            ['LFC_Today',    'heute'],
            ['LFC_Tomorrow', 'morgen'],
            ['LFC_DayAfter', 'übermorgen'],
        ];

        $days = [];
        for ($i = 0; $i < $limit; $i++) {
            [$ident, $label] = $map[$i];
            $raw = $this->ReadSourceValue($src, $ident, '');
            $fc  = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($fc) || !isset($fc['p50']) || !is_array($fc['p50'])) {
                continue;
            }
            $days[] = [
                'label' => $label,
                'date'  => $fc['date'] ?? '',
                'kwh'   => round((float) ($fc['kwh'] ?? 0), 1),
                'p10'   => array_map('floatval', $fc['p10'] ?? []),
                'p50'   => array_map('floatval', $fc['p50']),
                'p90'   => array_map('floatval', $fc['p90'] ?? []),
            ];
        }

        return json_encode(array_merge($style, [
            'hasData' => count($days) > 0,
            'message' => count($days) > 0 ? '' : 'Noch keine Prognose berechnet',
            'unit'    => 'kW',
            'days'    => $days,
        ]));
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function ResolveSource(): int
    {
        $configured = $this->ReadPropertyInteger('SourceInstance');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        $list = IPS_GetInstanceListByModuleID(self::SOURCE_MODULE);
        $this->SendDebug(__FUNCTION__, 'SourceInstance=' . $configured . ' · LoadForecast-Instanzen: ' . count($list) . ' [' . implode(', ', $list) . ']', 0);
        if (count($list) === 1) {
            return (int) $list[0];
        }
        return 0;
    }

    private function ReadSourceValue(int $instanceID, string $ident, $default)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) {
            return $default;
        }
        return GetValue($vid);
    }

    private function FontScaleValue(): float
    {
        $scale = $this->ReadPropertyFloat('FontScale');
        if ($scale < 0.5) { $scale = 0.5; }
        if ($scale > 2.5) { $scale = 2.5; }
        return $scale;
    }

    private function ColorHex(int $value, string $fallback): string
    {
        if ($value < 0) { return $fallback; }
        return sprintf('#%06x', $value);
    }

    private function ColorOrEmpty(int $value): string
    {
        if ($value < 0) { return ''; }
        return sprintf('#%06x', $value);
    }
}
