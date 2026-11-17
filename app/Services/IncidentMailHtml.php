<?php
declare(strict_types=1);

/** HTML-Mail im NetLab-Dashboard-Stil. Nur inline CSS und Tabellen für Outlook. */
final class IncidentMailHtml
{
    private const TONES = [
        'checked' => ['Geprüft', '#55cfab'],
        'warning' => ['Warnung', '#f3c47b'],
        'critical' => ['Kritisch', '#fb7c91'],
        'unknown' => ['Nicht geprüft', '#7994ab'],
    ];

    /**
     * @param array<int,list<array<string,mixed>>> $groups
     * @param array<int,array<string,mixed>|null> $devices
     * @param array<string,mixed> $stats
     * @return array{html:string,images:list<array{cid:string,mime:string,data:string}>}
     */
    public static function render(array $groups, array $devices, DateTimeImmutable $now, array $stats): array
    {
        $deviceStatus = $stats['devices'] ?? [];
        $checked = (int) ($deviceStatus['checked'] ?? 0);
        $warning = (int) ($deviceStatus['warning'] ?? 0);
        $critical = (int) ($deviceStatus['critical'] ?? 0);
        $unknown = (int) ($deviceStatus['unknown'] ?? 0);
        $deviceTotal = $checked + $warning + $critical + $unknown;
        $issues = $stats['incidents'] ?? [];
        $open = array_sum(array_map('count', $groups));
        $resolved = (int) ($issues['resolved'] ?? 0);
        $incidentTotal = $open + $resolved;
        $affected = count($groups);

        $images = [];
        $devicesPng = IncidentMailCharts::donut([
            ['count' => $checked, 'color' => '#55cfab'],
            ['count' => $warning, 'color' => '#f3c47b'],
            ['count' => $critical, 'color' => '#fb7c91'],
            ['count' => $unknown, 'color' => '#7994ab'],
        ], $deviceTotal, 'Geräte');
        $incidentsPng = IncidentMailCharts::donut([
            ['count' => $open, 'color' => '#fb7c91'],
            ['count' => $resolved, 'color' => '#55cfab'],
        ], $open, 'Offen');
        if ($devicesPng !== null) {
            $images[] = ['cid' => 'netlab-devices@netlab.local', 'mime' => 'image/png', 'data' => $devicesPng];
        }
        if ($incidentsPng !== null) {
            $images[] = ['cid' => 'netlab-incidents@netlab.local', 'mime' => 'image/png', 'data' => $incidentsPng];
        }
        $h = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $box = 'background-color:#151f30;border:1px solid #2b425c;border-radius:14px;';
        $muted = 'color:#9ab0bd;';
        $td = 'padding:12px 10px;border-bottom:1px solid #2b425c;vertical-align:top;font-size:12px;color:#d1dce2;';
        $th = 'padding:12px 10px;text-align:left;background-color:#22354b;color:#a9c1cd;font-size:11px;text-transform:uppercase;letter-spacing:.4px;';
        $date = $h($now->format('d.m.Y H:i T'));
        $html = '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark"></head>'
            . '<body bgcolor="#0b1220" style="margin:0;padding:0;background-color:#0b1220;color:#ebf2f6;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#0b1220;"><tr><td align="center" style="padding:26px 10px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:820px;background-color:#101a2b;border:1px solid #2b425c;">'
            . '<tr><td style="padding:28px 30px 25px;background-color:#142339;border-bottom:3px solid #48bad7;">'
            . '<div style="color:#6dd1f4;font-size:12px;font-weight:bold;letter-spacing:2px;">NETLAB GUARDIAN</div>'
            . '<h1 style="margin:12px 0 8px;font-size:26px;line-height:1.2;color:#f0f6fa;">Incident-Bericht</h1>'
            . '<p style="margin:0;font-size:13px;color:#a9c0cc;">Stand: ' . $date . ' · Automatisch erstellte Netzwerkübersicht</p>'
            . '</td></tr><tr><td style="padding:24px 24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . self::metric('Offene Incidents', $open, '#ff9eae')
            . self::metric('Betroffene Geräte', $affected, '#7addf5')
            . self::metric('Geräte gesamt', $deviceTotal, '#ebf2f6')
            . '</tr></table></td></tr>'
            . self::precheckSummary($stats['precheck'] ?? null)
            . '<tr><td style="padding:8px 24px 18px;">'
            . '<h2 style="font-size:18px;margin:8px 0 14px;color:#f1f6f8;">Statusübersicht</h2>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>'
            . '<td width="50%" valign="top" style="padding:0 7px 0 0;">'
            . '<table role="presentation" width="100%" style="' . $box . '"><tr><td align="center" style="padding:17px 6px;">'
            . '<div style="font-size:14px;color:#e9f2f6;font-weight:bold;">Geräte</div>'
            . self::chartImage($devicesPng, 'netlab-devices@netlab.local', 'Gerätestatus-Diagramm', $deviceTotal)
            . self::legend([
                ['Geprüft', $checked, '#55cfab'], ['Warnung', $warning, '#f3c47b'],
                ['Kritisch', $critical, '#fb7c91'], ['Nicht geprüft', $unknown, '#7994ab'],
            ], $deviceTotal)
            . '</td></tr></table></td>'
            . '<td width="50%" valign="top" style="padding:0 0 0 7px;">'
            . '<table role="presentation" width="100%" style="' . $box . '"><tr><td align="center" style="padding:17px 6px;">'
            . '<div style="font-size:14px;color:#e9f2f6;font-weight:bold;">Incidents</div>'
            . self::chartImage($incidentsPng, 'netlab-incidents@netlab.local', 'Incident-Diagramm', $open)
            . self::legend([['Offen', $open, '#fb7c91'], ['Gelöst', $resolved, '#55cfab']], $incidentTotal)
            . '</td></tr></table></td></tr></table></td></tr>'
            . '<tr><td style="padding:6px 24px 28px;">'
            . '<h2 style="font-size:18px;margin:8px 0 5px;color:#f1f6f8;">Offene Incidents</h2>'
            . '<p style="margin:0 0 16px;font-size:12px;' . $muted . '">Nach Geräten gruppiert · ' . $open . ' offen</p>';
        if ($open === 0) {
            $html .= '<div style="' . $box . 'padding:24px;font-size:14px;color:#8ed1b6;">Keine offenen Incidents vorhanden. Nicht abgefragte Geräte sind oben separat ausgewiesen.</div>';
        }
        foreach ($groups as $deviceId => $deviceIssues) {
            $device = $devices[$deviceId] ?? [];
            $hostname = trim((string) ($device['hostname'] ?? '')) ?: 'Gerät #' . $deviceId;
            $ip = trim((string) ($device['management_ip'] ?? '')) ?: 'Keine IP';
            $model = trim((string) ($device['model'] ?? ''));
            $serial = trim((string) ($device['serial_number'] ?? ''));
            $fw = trim((string) ($device['firmware'] ?? ''));
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;' . $box . '">'
                . '<tr><td style="padding:15px 14px 11px;border-bottom:1px solid #2b425c;">'
                . '<strong style="font-size:15px;color:#eff6fa;">' . $h($hostname) . '</strong> '
                . '<span style="color:#99bbca;font-size:12px;">' . $h($ip) . '</span>'
                . '<div style="margin-top:5px;font-size:11px;color:#98adba;">'
                . $h(implode(' · ', array_filter([$model, $fw ? 'Firmware ' . $fw : '', $serial ? 'S/N ' . $serial : '', count($deviceIssues) . ' offen'])))
                . '</div></td></tr><tr><td style="padding:0;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<thead><tr><th style="' . $th . '">Status / ID</th><th style="' . $th . '">Check</th><th style="' . $th . '">Meldung</th><th style="' . $th . '">Erkannt / Zuletzt</th></tr></thead><tbody>';
            usort($deviceIssues, static fn(array $a, array $b): int =>
                strcmp((string) ($a['detected_at'] ?? ''), (string) ($b['detected_at'] ?? ''))
                ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0)));
            foreach ($deviceIssues as $issue) {
                $command = self::command((string) ($issue['source_key'] ?? ''));
                $type = (string) preg_replace('/^Python-Check:\s*/', '', (string) ($issue['type'] ?? 'Incident'));
                $details = trim((string) ($issue['description'] ?? ''));
                if ($command !== 'Manuell' && str_starts_with($details, $command . ': ')) {
                    $details = substr($details, strlen($command) + 2);
                }
                $html .= '<tr><td style="' . $td . 'white-space:nowrap;"><span style="color:#efa7ac;">● Offen</span><br><span style="color:#859da9;">#'
                    . (int) ($issue['id'] ?? 0) . '</span></td>'
                    . '<td style="' . $td . '">' . $h($command) . '</td>'
                    . '<td style="' . $td . '"><strong style="color:#eef4f7;">' . $h($type) . '</strong><br>'
                    . '<span style="color:#a7bbc6;white-space:pre-wrap;">' . nl2br($h($details), false) . '</span></td>'
                    . '<td style="' . $td . '"><span style="white-space:nowrap;">' . $h(self::date($issue['detected_at'] ?? null, $now))
                    . '</span><br><span style="color:#91a7b5;white-space:nowrap;">'
                    . $h(self::date($issue['last_seen_at'] ?? $issue['detected_at'] ?? null, $now))
                    . '</span></td></tr>';
            }
            $html .= '</tbody></table></td></tr></table>';
        }
        $html .= '</td></tr><tr><td style="padding:17px 30px;border-top:1px solid #2b425c;font-size:11px;color:#8ca4b3;">'
            . 'NetLab Guardian · Automatischer Monitoring-Bericht · Antworten auf diese Nachricht werden nicht ausgewertet.'
            . '</td></tr></table></td></tr></table></body></html>';
        return ['html' => $html, 'images' => $images];
    }

    /** Zeigt die Aktualität des unmittelbar vor der E-Mail gestarteten Gerätechecks. */
    private static function precheckSummary(mixed $check): string
    {
        if (!is_array($check)) {
            return '';
        }
        $statuses = $check['statuses'] ?? [];
        $total = (int) ($check['total'] ?? 0);
        $attempted = (int) ($check['attempted'] ?? 0);
        $excluded = (int) ($check['excluded'] ?? 0);
        $invalid = (int) ($check['invalid_ip'] ?? 0);
        $ok = (int) ($statuses['ok'] ?? 0);
        $errors = (int) ($statuses['error'] ?? 0);
        $unreachable = (int) ($statuses['unreachable'] ?? 0);
        $auth = (int) ($statuses['authentication_failed'] ?? 0);
        $date = htmlspecialchars((string) ($check['checked_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $missing = $excluded + $invalid;
        $highlight = $missing > 0 ? '#f3c47b' : '#55cfab';
        return '<tr><td style="padding:2px 24px 14px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#17273a;border:1px solid #324a61;">'
            . '<tr><td style="padding:14px 16px;color:#e8f1f5;font-size:13px;">'
            . '<strong style="color:' . $highlight . ';">Gerätecheck vor dem Versand</strong> '
            . '<span style="color:#9ab0bd;">· ' . $date . '</span><br>'
            . '<span style="color:#dbe9ee;">' . $attempted . ' von ' . $total . ' Geräten abgefragt</span>'
            . '<div style="margin-top:6px;color:#a9bdc8;font-size:12px;">'
            . $ok . ' OK · ' . $errors . ' mit Fehlern · ' . $unreachable . ' nicht erreichbar · '
            . $auth . ' Anmeldung fehlgeschlagen'
            . ($missing > 0 ? ' · ' . $excluded . ' gesperrt · ' . $invalid . ' ohne gültige IP' : '')
            . '</div></td></tr></table></td></tr>';
    }

    private static function metric(string $label, int $number, string $color): string
    {
        return '<td width="33%" style="padding:4px;" valign="top"><table role="presentation" width="100%" style="background-color:#18283d;border:1px solid #2b425c;">'
            . '<tr><td style="padding:16px 12px;"><div style="font-size:11px;color:#9eb6c2;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div><div style="font-size:26px;font-weight:bold;margin-top:8px;color:'
            . $color . ';">' . $number . '</div></td></tr></table></td>';
    }

    private static function chartImage(?string $png, string $cid, string $alt, int $center): string
    {
        if ($png === null) {
            return '<div style="padding:22px;font-size:28px;color:#f0f6f8;">' . $center . '</div>';
        }
        return '<div style="padding:8px 0;"><img src="cid:' . $cid
            . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8')
            . '" width="190" height="190" style="display:block;border:0;max-width:100%;height:auto;" /></div>';
    }

    /** @param list<array{0:string,1:int,2:string}> $rows */
    private static function legend(array $rows, int $total): string
    {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" width="90%">';
        foreach ($rows as [$label, $count, $color]) {
            $percent = $total > 0 ? round($count / $total * 100, 1) : 0;
            $html .= '<tr><td style="text-align:left;padding:4px 2px;font-size:11px;color:#c4d5dd;">'
                . '<span style="color:' . $color . ';font-size:16px;">●</span> '
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="text-align:right;padding:4px 2px;font-size:11px;color:#bbd1da;white-space:nowrap;">'
                . $count . ' · ' . str_replace('.', ',', (string) $percent) . '%</td></tr>';
        }
        return $html . '</table>';
    }

    private static function command(string $sourceKey): string
    {
        if (!str_starts_with($sourceKey, 'python|')) return 'Manuell';
        $parts = explode('|', $sourceKey, 3);
        if (count($parts) !== 3) return 'Python';
        $command = rawurldecode($parts[1]);
        return str_starts_with($command, '_') ? 'Systemprüfung' : $command;
    }

    private static function date(mixed $value, DateTimeImmutable $now): string
    {
        if (!is_string($value) || trim($value) === '') return '—';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone($now->getTimezone())->format('d.m.Y H:i');
        } catch (Exception) {
            return '—';
        }
    }
}
