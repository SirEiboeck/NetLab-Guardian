<?php
declare(strict_types=1);

/** Interner SQLite-Zugriff: Diese Datei nur per require_once einbinden. */
final class DBInternalAPI
{
    private PDO $db;
    private const TABLES = ['device', 'issue', 'credential', 'device_credential', 'recipient', 'command'];

    public function __construct(?string $path = null)
    {
        $path ??= __DIR__ . '/storage/inventory.sqlite';
        $new = !is_file($path);
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        if ($new) {
            @chmod($path, 0600);
            $this->init();
        }
    }

    /** Erstellt die Tabellen automatisch, falls die SQLite-Datei noch nicht existiert. */
    private function init(): void
    {
        $schema = file_get_contents(__DIR__ . '/schema.sql');

        if ($schema === false) {
            throw new RuntimeException('Datenbankschema konnte nicht geladen werden.');
        }

        $this->db->exec($schema);
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

    public function deviceCredentials(int $deviceId): array
    {
        $stmt = $this->db->prepare('SELECT c.* FROM credential c JOIN device_credential dc ON dc.credential_id = c.id WHERE dc.device_id = ?');
        $stmt->execute([$deviceId]);
        return $stmt->fetchAll(); // Enthält pwd im Klartext, wie im Schema vorgegeben.
    }

    public function assignCredential(int $deviceId, int $credentialId): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO device_credential (device_id, credential_id) VALUES (?, ?)');
        $stmt->execute([$deviceId, $credentialId]);
    }

    public function unassignCredential(int $deviceId, int $credentialId): void
    {
        $stmt = $this->db->prepare('DELETE FROM device_credential WHERE device_id = ? AND credential_id = ?');
        $stmt->execute([$deviceId, $credentialId]);
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
            throw new InvalidArgumentException('device_credential: assignCredential()/unassignCredential() benutzen.');
        }
        return $table === 'command' ? 'command' : 'id';
    }
}
