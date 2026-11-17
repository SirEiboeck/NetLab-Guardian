<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/ProjectPaths.php';

/** Interner SQLite-Zugriff: Diese Datei nur per require_once einbinden. */
final class DBInternalAPI
{
    private PDO $db;
    private const TABLES = ['device', 'issue', 'credential', 'device_credential', 'recipient', 'command'];

    public function __construct(?string $path = null)
    {
        $path ??= ProjectPaths::database();
        $new = !is_file($path);
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        if ($new) {
            // Gemeinsamer Zugriff von Deploy-Benutzer und PHP-Webserver-Gruppe.
            // Der Installer setzt das Storage-Verzeichnis zusätzlich auf setgid (2770).
            @chmod($path, 0660);
            $this->init();
        } else {
            $this->migrateCheckSchema();
        }
    }

    /** Erstellt die Tabellen automatisch, falls die SQLite-Datei noch nicht existiert. */
    private function init(): void
    {
        $schema = file_get_contents(ProjectPaths::schema());

        if ($schema === false) {
            throw new RuntimeException('Datenbankschema konnte nicht geladen werden.');
        }

        $this->db->exec($schema);
    }

    /** Ergänzt neue Geräte-/Issue-Felder in bestehenden Inventaren automatisch. */
    private function migrateCheckSchema(): void
    {
        // Ältere Inventar-Datenbanken besitzen die Login-Tabelle eventuell noch nicht.
        $this->db->exec("CREATE TABLE IF NOT EXISTS site_user (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('admin', 'viewer'))
        )");
        $addedLastSeen = false;
        foreach ([
            ['device', 'check_excluded', 'INTEGER NOT NULL DEFAULT 0 CHECK (check_excluded IN (0, 1))'],
            ['device', 'discovered_hostname', 'TEXT'],
            ['device', 'os_family', 'TEXT'],
            ['device', 'uptime', 'TEXT'],
            ['device', 'software_image', 'TEXT'],
            ['device', 'last_discovered_at', 'TEXT'],
            ['issue', 'source_key', 'TEXT'],
            ['issue', 'last_seen_at', 'TEXT'],
        ] as [$table, $column, $definition]) {
            $fields = $this->db->query("PRAGMA table_info($table)")->fetchAll();
            if (in_array($column, array_column($fields, 'name'), true)) {
                continue;
            }
            try {
                $this->db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
                if ($table === 'issue' && $column === 'last_seen_at') {
                    $addedLastSeen = true;
                }
            } catch (PDOException $error) {
                // Andere PHP-Requests können die Migration bereits durchgeführt haben.
                $fields = $this->db->query("PRAGMA table_info($table)")->fetchAll();
                if (!in_array($column, array_column($fields, 'name'), true)) {
                    throw $error;
                }
            }
        }
        // Nicht automatisch ermittelbare Inventarfelder aus Alt-Datenbanken entfernen.
        // SQLite >= 3.35 unterstützt DROP COLUMN; bei älteren Versionen
        // keinen risikoreichen Tabellen-Neuaufbau im laufenden Webrequest ausführen.
        foreach (['console_port', 'inventory_number'] as $obsoleteColumn) {
            $existingColumns = array_column($this->db->query('PRAGMA table_info("device")')->fetchAll(), 'name');
            if (!in_array($obsoleteColumn, $existingColumns, true)) continue;
            try {
                $this->db->exec('ALTER TABLE "device" DROP COLUMN "' . $obsoleteColumn . '"');
            } catch (PDOException $error) {
                $after = array_column($this->db->query('PRAGMA table_info("device")')->fetchAll(), 'name');
                if (in_array($obsoleteColumn, $after, true)) {
                    throw new RuntimeException(
                        'Inventar-Migration benötigt SQLite 3.35+ (Spalte ' . $obsoleteColumn . ').',
                        0, $error
                    );
                }
            }
        }
        // Nur beim Upgrade schreiben – normale Seitenaufrufe bleiben lesend.
        if ($addedLastSeen) {
            $this->db->exec('UPDATE issue SET last_seen_at = detected_at WHERE last_seen_at IS NULL');
        }
        // Pro Gerät darf ein bestimmter automatischer Fehler nur einmal offen sein.
        // Manuelle Issues besitzen keinen source_key und sind davon unberührt.
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_issue_open_source_key
            ON issue(device_id, source_key)
            WHERE source_key IS NOT NULL AND resolved_at IS NULL');
    }

    /** Liefert Spalten und Eigenschaften (name, type, notnull, dflt_value, pk). */
    public function fields(string $table): array
    {
        $this->checkTable($table);
        return $this->db->query("PRAGMA table_info(\"$table\")")->fetchAll();
    }

    /** Für Webformulare: Pflichtfelder ohne Datenbank-Standardwert. */
    public function requiredFields(string $table): array
    {
        return array_values(array_map(
            fn(array $f) => $f['name'],
            array_filter(
                $this->fields($table),
                fn(array $f) =>
                    $f['dflt_value'] === null &&
                    ($f['notnull'] || ($f['pk'] && strtoupper($f['type']) !== 'INTEGER'))
            )
        ));
    }

    /** Alle passenden Datensätze; Filter wie ['device_type' => 'Switch', 'removed_at' => null]. */
    public function all(string $table, array $where = [], int $limit = 500): array
    {
        $this->checkColumns($table, array_keys($where));
        $clauses = [];
        $values = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $clauses[] = "\"$column\" IS NULL";
            } else {
                $clauses[] = "\"$column\" = ?";
                $values[] = $value;
            }
        }
        $sql = "SELECT * FROM \"$table\"" . ($clauses ? ' WHERE ' . implode(' AND ', $clauses) : '');
        $sql .= ' LIMIT ' . max(1, min($limit, 10000));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchAll();
    }

    /** Einzelner Datensatz per ID; für command wird der Befehl als Schlüssel übergeben. */
    public function get(string $table, int|string $id): ?array
    {
        $key = $this->key($table);
        return $this->all($table, [$key => $id], 1)[0] ?? null;
    }

    /** Legt einen Datensatz an; gibt die ID bzw. den Befehlsnamen zurück. */
    public function insert(string $table, array $data): int|string|null
    {
        $this->checkColumns($table, array_keys($data));
        if (!$data) {
            throw new InvalidArgumentException('Keine Daten angegeben.');
        }
        $columns = implode(', ', array_map(fn($c) => "\"$c\"", array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $this->db->prepare("INSERT INTO \"$table\" ($columns) VALUES ($marks)");
        $stmt->execute(array_values($data));
        if ($table === 'command')
            return $data['command'] ?? null;
        if ($table === 'device_credential')
            return null;
        return (int) $this->db->lastInsertId();
    }

    /** Aktualisiert Datensatz per ID. */
    public function update(string $table, int|string $id, array $data): bool
    {
        $key = $this->key($table);
        $this->checkColumns($table, array_keys($data));
        if (!$data)
            return false;
        $set = implode(', ', array_map(fn($c) => "\"$c\" = ?", array_keys($data)));
        $stmt = $this->db->prepare("UPDATE \"$table\" SET $set WHERE \"$key\" = ?");
        $stmt->execute([...array_values($data), $id]);
        return $stmt->rowCount() > 0;
    }

    /** Löscht Datensatz per ID. Fremdschlüssel-Regeln gelten weiterhin. */
    public function delete(string $table, int|string $id): bool
    {
        $key = $this->key($table);
        $stmt = $this->db->prepare("DELETE FROM \"$table\" WHERE \"$key\" = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function activeDevices(): array
    {
        return $this->all('device', ['removed_at' => null]);
    }
    public function openIssues(): array
    {
        return $this->all('issue', ['resolved_at' => null]);
    }

    public function resolveIssue(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE issue SET resolved_at = datetime('now') WHERE id = ? AND resolved_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Synchronisiert nur automatisch erzeugte Python-Incidents eines Geräts.
     *
     * - Gleiches (Gerät, source_key) bleibt ein und derselbe offene Incident.
     * - Ein wieder sichtbarer Fehler aktualisiert nur Text und last_seen_at.
     * - Ein fehlender Fehler wird nur für erfolgreich ausgewertete Befehle gelöst.
     * - Timeout, Authentifizierungsfehler und kaputte Python-Antworten dürfen
     *   bisherige Diagnose-Incidents nicht fälschlich als behoben markieren.
     * - Manuell erfasste Incidents werden niemals automatisch verändert.
     *
     * Alle Änderungen eines Geräts sind eine SQLite-Transaktion.
     * $desired: [source_key => ['type' => ..., 'description' => ...]]
     * $outcome stammt aus rackCheckOutcome() und ist bereits validiert.
     */
    public function syncPythonIssues(int $deviceId, array $desired, array $outcome): void
    {
        if ($deviceId < 1) {
            throw new InvalidArgumentException('Ungültige Geräte-ID.');
        }
        foreach ($desired as $key => $fields) {
            if (!is_string($key) || !str_starts_with($key, 'python|') ||
                !is_array($fields) || !is_string($fields['type'] ?? null) ||
                !is_string($fields['description'] ?? null)) {
                throw new InvalidArgumentException('Ungültige Python-Incident-Daten.');
            }
        }

        $valid = ($outcome['valid_response'] ?? false) === true;
        $structured = ($outcome['structured'] ?? false) === true;
        $evaluated = array_fill_keys($outcome['evaluated'] ?? [], true);
        $failed = [];
        $cliErrorCodes = ['access_denied', 'cli_error', 'invalid_output', 'unsupported_command', 'execution_failed'];
        $hasCommandResult = false;
        foreach ($outcome['issues'] ?? [] as $current) {
            if (!is_array($current) || !isset($current['command'], $current['code'])) {
                continue;
            }
            $command = $current['command'];
            $code = $current['code'];
            if ($command !== '_transport' && $command !== '_system') {
                $hasCommandResult = true;
            }
            if (in_array($code, $cliErrorCodes, true)) {
                $failed[$command] = true;
            }
        }

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT * FROM issue WHERE device_id = ? AND resolved_at IS NULL');
            $select->execute([$deviceId]);
            $existing = $select->fetchAll();
            $existingByKey = [];
            $resolve = $this->db->prepare("UPDATE issue SET resolved_at = datetime('now') WHERE id = ? AND resolved_at IS NULL");
            foreach ($existing as $old) {
                $id = (int) $old['id'];
                $key = $old['source_key'];
                if ($key === null) {
                    // Einmaliger Übergang von alten Python-Sammelmeldungen.
                    if ($valid && $structured && $evaluated &&
                        str_starts_with((string) $old['type'], 'Python-Check: ')) {
                        $resolve->execute([$id]);
                    }
                    continue;
                }
                if (!str_starts_with((string) $key, 'python|')) {
                    continue;
                }
                if (isset($desired[$key])) {
                    $existingByKey[$key] = $old;
                    continue;
                }
                if (!$valid) {
                    continue;
                }
                $parts = explode('|', $key, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                $command = rawurldecode($parts[1]);
                $code = rawurldecode($parts[2]);
                $mayResolve = false;
                if ($command === '_transport') {
                    // Sobald eine neue valide Antwort vorliegt, ist der alte
                    // Verbindungsstatus nicht mehr aktuell.
                    $mayResolve = true;
                } elseif ($command === '_system') {
                    // Ein Systemfehler ist durch einen auswertbaren Check oder
                    // eine konkrete CLI-Meldung ersetzt, nicht durch Timeout.
                    $mayResolve = $structured && ($evaluated || $hasCommandResult);
                } elseif (isset($evaluated[$command])) {
                    $mayResolve = true;
                } elseif (isset($failed[$command]) && in_array($code, $cliErrorCodes, true)) {
                    // CLI-Fehler wird durch einen neuen CLI-Fehler abgelöst,
                    // nicht jedoch ein früherer Hardwarefehler.
                    $mayResolve = true;
                }
                if ($mayResolve) {
                    $resolve->execute([$id]);
                }
            }

            $update = $this->db->prepare("UPDATE issue
                SET type = ?, description = ?, last_seen_at = datetime('now')
                WHERE id = ? AND resolved_at IS NULL");
            $insert = $this->db->prepare("INSERT INTO issue
                (device_id, source_key, type, description, last_seen_at)
                VALUES (?, ?, ?, ?, datetime('now'))");
            foreach ($desired as $key => $fields) {
                if (isset($existingByKey[$key])) {
                    $update->execute([$fields['type'], $fields['description'], (int) $existingByKey[$key]['id']]);
                } else {
                    $insert->execute([$deviceId, $key, $fields['type'], $fields['description']]);
                }
            }
            $checked = $this->db->prepare("UPDATE device SET last_checked_at = datetime('now') WHERE id = ?");
            $checked->execute([$deviceId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** Incidents inklusive Historie; mit Gerätedaten für die Tabellenansicht. */
    public function incidentOverview(string $filter = 'open', int $limit = 200): array
    {
        if (!in_array($filter, ['open', 'resolved', 'all'], true)) {
            throw new InvalidArgumentException('Ungültiger Incident-Filter.');
        }
        $where = match ($filter) {
            'open' => ' WHERE i.resolved_at IS NULL',
            'resolved' => ' WHERE i.resolved_at IS NOT NULL',
            default => '',
        };
        $limit = max(1, min($limit, 500));
        return $this->db->query('SELECT i.*, d.hostname AS device_name, d.management_ip AS device_ip
            FROM issue i JOIN device d ON d.id = i.device_id' . $where . '
            ORDER BY COALESCE(i.resolved_at, i.last_seen_at, i.detected_at) DESC, i.id DESC
            LIMIT ' . $limit)->fetchAll();
    }

    public function incidentCounts(): array
    {
        $row = $this->db->query('SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS open,
            SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved
            FROM issue')->fetch();
        return ['all' => (int) $row['total'], 'open' => (int) $row['open'],
            'resolved' => (int) $row['resolved']];
    }

    /** Interne Website-Anmeldung; niemals das Klartextfeld credential.pwd verwenden. */
    public function siteUser(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT username, password_hash, role FROM site_user WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    /** Nur für das lokale CLI-Einrichtungsskript vorgesehen. */
    public function setSiteUser(string $username, string $passwordHash, string $role): void
    {
        if (!in_array($role, ['admin', 'viewer'], true) || $username === '') {
            throw new InvalidArgumentException('Ungültiger Benutzer oder Rolle.');
        }
        $stmt = $this->db->prepare('INSERT INTO site_user (username, password_hash, role) VALUES (?, ?, ?)
            ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, role = excluded.role');
        $stmt->execute([$username, $passwordHash, $role]);
    }

    private function checkTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("Unbekannte Tabelle: $table");
        }
    }

    private function checkColumns(string $table, array $columns): void
    {
        $valid = array_column($this->fields($table), 'name');
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, $valid, true)) {
                throw new InvalidArgumentException('Ungültiges Datenbankfeld.');
            }
        }
    }

    private function key(string $table): string
    {
        $this->checkTable($table);
        if ($table === 'device_credential') {
            throw new InvalidArgumentException('Veraltete Zuordnungstabelle ist nicht über die Website bearbeitbar.');
        }
        return $table === 'command' ? 'command' : 'id';
    }
}
