<?php
declare(strict_types=1);

require_once __DIR__ . '/../Checks/bootstrap.php';

/**
 * Prüft vor dem Mailversand alle bekannten, aktiven und nicht ausgeschlossenen Geräte.
 * Verwendet exakt den Rack-Check-Pfad der Weboberfläche (inkl. Credential-Fallback,
 * Python-Ausführung, Metadaten-Update und Incident-Synchronisierung).
 * Es findet ausdrücklich KEIN Discovery-/IP-Scan statt.
 */
final class IncidentMailDeviceCheck
{
    /** @return array{total:int,attempted:int,excluded:int,invalid_ip:int,statuses:array<string,int>,checked_at:string} */
    public static function run(DBInternalAPI $db, DateTimeImmutable $now, bool $waitForLock = false): array
    {
        $lock = fopen(ProjectPaths::rackLock(), 'c');
        if ($lock === false) {
            throw new RuntimeException('Die Rack-Check-Sperrdatei kann nicht geöffnet werden (storage/-Rechte prüfen).');
        }
        if (!flock($lock, $waitForLock ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
            fclose($lock);
            throw new RuntimeException('Es läuft bereits ein Gerätecheck. Kein Versand mit möglicherweise veralteten Incidents.');
        }

        try {
            // Unter derselben Sperre frisch einlesen: kein veralteter Gerätestand
            // und keine parallelen Veränderungen an check_excluded.
            $devices = $db->all('device', ['removed_at' => null], 10000);
            $excluded = 0;
            $invalidIp = 0;
            $eligible = 0;
            foreach ($devices as $device) {
                if ((int) ($device['check_excluded'] ?? 0) === 1) {
                    ++$excluded;
                } elseif (!filter_var(trim((string) ($device['management_ip'] ?? '')), FILTER_VALIDATE_IP)) {
                    ++$invalidIp;
                } else {
                    ++$eligible;
                }
            }
            $summary = [
                'total' => count($devices),
                'attempted' => 0,
                'excluded' => $excluded,
                'invalid_ip' => $invalidIp,
                'statuses' => [],
                'checked_at' => $now->format('d.m.Y H:i T'),
            ];
            if ($eligible === 0) {
                // Bei leerem Inventar oder ausschließlich explizit gesperrten
                // Geräten ist kein Python-Aufruf notwendig. Ungültige IPs
                // werden im Bericht dennoch sichtbar als ungeprüft markiert.
                $summary['checked_at'] = (new DateTimeImmutable('now', $now->getTimezone()))->format('d.m.Y H:i T');
                return $summary;
            }

            $commands = array_values(array_map(
                static fn(array $row): string => (string) $row['command'],
                $db->all('command', [], 50)
            ));
            sort($commands);
            $credentials = $db->all('credential', [], 10000);
            // Die vorhandene Rack-Konfiguration kann auch sämtliche bekannten
            // Geräte in einem Lauf planen, unabhängig von ihrer Rack-Position.
            $settings = rackEditableConfig(1, $devices, $commands, $credentials);
            $settings['devices'] = array_fill_keys(array_keys((array) $settings['devices']), []);
            [$defaults, $plans, $retries] = rackExecutionPlans($db, $devices, $settings, false);
            if (count($plans) !== $eligible) {
                throw new RuntimeException('Nicht alle bekannten, freigegebenen Geräte konnten für den Check eingeplant werden.');
            }

            $summary['attempted'] = count($plans);
            // Kein zusätzlicher Scan: alle Ziele sind bereits inventarisierte IPs.
            // Der Rack-Wert wird in saveRackCheckResults nur für neu gefundene
            // Scan-Geräte benötigt; bei scanOnlyIps=[] wird keines angelegt.
            $results = runRackPlans($defaults, $plans, $retries, 900);
            [$counts] = saveRackCheckResults($db, $devices, $plans, $retries, $results, 1, []);
            $summary['statuses'] = $counts;
            $summary['checked_at'] = (new DateTimeImmutable('now', $now->getTimezone()))->format('d.m.Y H:i T');
            return $summary;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
