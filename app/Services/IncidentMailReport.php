<?php
declare(strict_types=1);

/** Erstellt aus der bestehenden NetLab-SQLite-API einen täglichen Incident-Bericht. */
final class IncidentMailReport
{
    private const MAX_ISSUES = 10000;

    /** @return array{subject:string,body:string,count:int,device_count:int} */
    public static function fromDatabase(DBInternalAPI $db, DateTimeImmutable $now): array
    {
        $openCount = (int) ($db->incidentCounts()['open'] ?? 0);
        if ($openCount > self::MAX_ISSUES) {
            throw new RuntimeException('Mehr als 10000 offene Incidents: Bericht wird nicht unvollständig verschickt.');
        }

        // Bestehende Datenbank-Funktionen wiederverwenden, keine zweite SQLite-Verbindung.
        $issues = $db->all('issue', ['resolved_at' => null], max(1, $openCount));
        if (count($issues) !== $openCount) {
            // Ein parallel laufender Geräte-Check kann die Daten während des Lesens ändern.
            // Lieber beim nächsten Timer-Aufruf erneut versuchen als einen Teilbericht senden.
            throw new RuntimeException('Die offenen Incidents haben sich beim Lesen geändert; erneuter Versuch folgt.');
        }

        $devices = [];
        foreach ($issues as $issue) {
            $id = (int) $issue['device_id'];
            if (!array_key_exists($id, $devices)) {
                $devices[$id] = $db->get('device', $id);
            }
        }

        return self::format($issues, $devices, $now);
    }

    /**
     * Reine Formatierungsfunktion; unabhängig von Netzwerk und DB testbar.
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>|null> $devices
     * @return array{subject:string,body:string,count:int,device_count:int}
     */
    public static function format(array $issues, array $devices, DateTimeImmutable $now): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            // Zusätzliche Absicherung bei Verwendung unabhängig von fromDatabase().
            if (($issue['resolved_at'] ?? null) !== null) {
                continue;
            }
            $id = (int) ($issue['device_id'] ?? 0);
            $groups[$id][] = $issue;
        }

        uksort($groups, static function (int $a, int $b) use ($devices): int {
            $nameA = trim((string) ($devices[$a]['hostname'] ?? ''));
            $nameB = trim((string) ($devices[$b]['hostname'] ?? ''));
            $nameA = $nameA !== '' ? $nameA : 'Gerät #' . $a;
            $nameB = $nameB !== '' ? $nameB : 'Gerät #' . $b;
            return strnatcasecmp($nameA, $nameB) ?: ($a <=> $b);
        });

        $total = array_sum(array_map('count', $groups));
        $date = $now->format('d.m.Y');
        $subject = 'NetLab Guardian – Offene Incidents – ' . $date . ' (' . $total . ')';
        $lines = [
            'NetLab Guardian – Täglicher Incident-Bericht',
            'Stand: ' . $now->format('d.m.Y H:i T'),
            'Offene Incidents: ' . $total,
            'Betroffene Geräte: ' . count($groups),
            '',
        ];

        if ($total === 0) {
            $lines[] = 'Aktuell sind keine offenen Incidents vorhanden.';
        }

        foreach ($groups as $id => $deviceIssues) {
            $device = $devices[$id] ?? [];
            $name = trim((string) ($device['hostname'] ?? ''));
            $name = $name !== '' ? $name : 'Gerät #' . $id;
            $ip = trim((string) ($device['management_ip'] ?? ''));
            $deviceType = trim((string) ($device['device_type'] ?? ''));
            $label = $name . ' (ID ' . $id . ')';
            if ($ip !== '') {
                $label .= ' | IP: ' . $ip;
            }
            if ($deviceType !== '') {
                $label .= ' | Typ: ' . $deviceType;
            }
            $lines[] = $label . ' – ' . count($deviceIssues) . ' offene(r) Incident(s)';
            $lines[] = str_repeat('-', 50);

            usort($deviceIssues, static function (array $a, array $b): int {
                return strcmp((string) ($a['detected_at'] ?? ''), (string) ($b['detected_at'] ?? ''))
                    ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
            });
            foreach ($deviceIssues as $issue) {
                $lines[] = '- #' . (int) ($issue['id'] ?? 0) . ' | ' . self::singleLine((string) ($issue['type'] ?? 'Incident'));
                $lines[] = '  Beschreibung: ' . self::multiLine((string) ($issue['description'] ?? ''));
                $lines[] = '  Erkannt: ' . ((string) ($issue['detected_at'] ?? '') ?: 'unbekannt');
                if (!empty($issue['last_seen_at'])) {
                    $lines[] = '  Zuletzt gesehen: ' . $issue['last_seen_at'];
                }
            }
            $lines[] = '';
        }

        $lines[] = 'Automatisch erstellt von NetLab Guardian. Nicht auf diese E-Mail antworten.';
        return [
            'subject' => $subject,
            'body' => implode("\n", $lines) . "\n",
            'count' => $total,
            'device_count' => count($groups),
        ];
    }

    private static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function multiLine(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        return str_replace("\n", "\n                ", $text);
    }
}
