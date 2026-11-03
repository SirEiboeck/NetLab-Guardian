<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireLogin();

/** Im Browser niemals echte DB-Passwörter ausgeben. */
const RACK_DB_PASSWORD = '[aus Datenbank]';

/** Editierbares JSON: globale Credentials sichtbar, DB-Passwörter als Platzhalter. */
function rackEditableConfig(int $rack, array $rackDevices, array $commands, array $credentialRows): array
{
    $ips = [];
    foreach ($rackDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $ips[$ip] = new stdClass();
        }
    }
    return [
        'credentials' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password' => RACK_DB_PASSWORD,
        ], $credentialRows),
        // Ohne zusätzliche Konfiguration wird das zum Rack gehörende /24 geprüft.
        // Diese Adressbereiche können vor jedem Lauf im JSON geändert werden.
        'discover' => [
            'ranges' => [['start' => "10.78.$rack.1", 'end' => "10.78.$rack.254"]],
            'exclude' => [],
        ],
        'defaults' => [
            'port' => 23,
            'device_type' => 'cisco_ios_telnet',
            'commands' => $commands ?: ['show version'],
        ],
        'devices' => (object) $ips,
    ];
}

/** Nur die vom Check-Skript unterstützten JSON-Attribute erlauben. */
function validCheckOptions(mixed $options, bool $partial = false): array
{
    if (!is_array($options)) {
        throw new RuntimeException('Die Einstellungen müssen ein JSON-Objekt sein.');
    }
    foreach (array_keys($options) as $key) {
        if (!in_array($key, ['port', 'device_type', 'commands'], true)) {
            throw new RuntimeException('Unbekanntes JSON-Attribut: ' . (string) $key);
        }
    }
    if (!$partial && count($options) !== 3) {
        throw new RuntimeException('Unter defaults sind port, device_type und commands erforderlich.');
    }
    if (array_key_exists('port', $options) && (!is_int($options['port']) || $options['port'] < 1 || $options['port'] > 65535)) {
        throw new RuntimeException('port muss eine Zahl zwischen 1 und 65535 sein.');
    }
    if (array_key_exists('device_type', $options) && (!is_string($options['device_type']) || !preg_match('/^[a-zA-Z0-9_]{3,64}$/D', $options['device_type']))) {
        throw new RuntimeException('device_type enthält ungültige Zeichen.');
    }
    if (array_key_exists('commands', $options)) {
        $commands = $options['commands'];
        if (!is_array($commands) || !array_is_list($commands) || !$commands || count($commands) > 50) {
            throw new RuntimeException('commands muss eine Liste mit 1 bis 50 Befehlen sein.');
        }
        foreach ($commands as $command) {
            if (!is_string($command) || trim($command) === '' || strlen($command) > 256 || preg_match('/[\x00-\x1f\x7f]/', $command)) {
                throw new RuntimeException('Ein command ist leer, zu lang oder enthält Steuerzeichen.');
            }
        }
    }
    return $options;
}

/**
 * Wandelt discover.ranges/exclude aus dem vorhandenen JSON-Format in IPv4-Ziele um.
 * Die obere Grenze verhindert versehentliche riesige Netzwerkscans.
 * Ausschlüsse gelten ausschließlich für den IP-Scan, nicht für explizite devices.
 */
function rackDiscoveryTargets(mixed $discover): array
{
    if ($discover === null) return [];
    if (!is_array($discover) || array_diff(array_keys($discover), ['ranges', 'exclude']) ||
        !isset($discover['ranges']) || !is_array($discover['ranges']) || !array_is_list($discover['ranges'])) {
        throw new RuntimeException('discover benötigt ranges als Liste; exclude ist optional.');
    }
    $parseRanges = static function (mixed $ranges, string $field): array {
        if (!is_array($ranges) || !array_is_list($ranges) || count($ranges) > 64) {
            throw new RuntimeException("discover.$field muss eine Liste mit höchstens 64 Bereichen sein.");
        }
        $parsed = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) !== 2 ||
                array_diff(array_keys($range), ['start', 'end']) ||
                !isset($range['start'], $range['end']) ||
                !is_string($range['start']) || !is_string($range['end']) ||
                !filter_var($range['start'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
                !filter_var($range['end'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("discover.$field: Jeder Bereich benötigt start und end als gültige IPv4-Adressen.");
            }
            $start = (int) sprintf('%u', ip2long($range['start']));
            $end = (int) sprintf('%u', ip2long($range['end']));
            if ($end < $start) {
                throw new RuntimeException("discover.$field: start darf nicht größer als end sein.");
            }
            $parsed[] = [$start, $end];
        }
        return $parsed;
    };
    $ranges = $parseRanges($discover['ranges'], 'ranges');
    $excluded = $parseRanges($discover['exclude'] ?? [], 'exclude');
    $targets = [];
    foreach ($ranges as [$start, $end]) {
        if ($end - $start + 1 > 2048) {
            throw new RuntimeException('Ein einzelner IP-Bereich darf höchstens 2048 Adressen enthalten.');
        }
        for ($ip = $start; $ip <= $end; ++$ip) {
            $ignored = false;
            foreach ($excluded as [$excludeStart, $excludeEnd]) {
                if ($ip >= $excludeStart && $ip <= $excludeEnd) {
                    $ignored = true;
                    break;
                }
            }
            if (!$ignored) {
                $targets[long2ip($ip)] = true;
                if (count($targets) > 2048) {
                    throw new RuntimeException('Der IP-Scan darf insgesamt höchstens 2048 Adressen umfassen.');
                }
            }
        }
    }
    return $targets;
}

/**
 * Erstellt Prüfpläne für Rack-Geräte und zusätzliche IPs aus discover.ranges.
 * Standardzugangsdaten stammen aus SQLite; im Browser erscheinen nur Platzhalter.
 * Geräten explizit zugeordnete Credentials haben Vorrang vor dem globalen Pool.
 * Das unveränderte Python-Config-Modul identifiziert Credentials am Benutzernamen.
 */
function rackExecutionPlans(DBInternalAPI $db, array $rackDevices, array $settings): array
{
    if (!is_array($settings) || array_diff(array_keys($settings), ['credentials', 'defaults', 'devices', 'discover']) ||
        !isset($settings['defaults'], $settings['devices']) || !is_array($settings['devices'])) {
        throw new RuntimeException('Das JSON benötigt defaults und devices; credentials ist optional.');
    }
    $defaults = validCheckOptions($settings['defaults']);
    $known = [];
    foreach ($rackDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (isset($known[$ip])) {
                throw new RuntimeException('Doppelte Management-IP in diesem Rack: ' . $ip);
            }
            $known[$ip] = $device;
        }
    }

    // Globale DB-Credentials; eine Gerätezuordnung schränkt die Auswahl pro Gerät ein.
    $credentialRows = $db->all('credential', [], 10000);
    $credentialsById = [];
    foreach ($credentialRows as $row) {
        $credentialsById[(int) $row['id']] = $row;
    }
    if (array_key_exists('credentials', $settings)) {
        $entries = $settings['credentials'];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 10000) {
            throw new RuntimeException('credentials muss eine JSON-Liste sein.');
        }
    } else {
        $entries = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password' => RACK_DB_PASSWORD,
        ], $credentialRows);
    }

    // Die IDs dienen der dauerhaften Zuordnung eines Credentials zu einem Gerät.
    // Ein Eintrag ohne ID bleibt weiterhin als temporäres JSON-Credential möglich.
    $effectiveCredentials = [];
    foreach ($entries as $credential) {
        if (!is_array($credential) || array_diff(array_keys($credential), ['id', 'username', 'password']) ||
            !isset($credential['username'], $credential['password']) ||
            !is_string($credential['username']) || !is_string($credential['password'])) {
            throw new RuntimeException('Jedes Credential benötigt username und password (optional: id).');
        }
        $id = $credential['id'] ?? null;
        if ($id !== null && (!is_int($id) || !isset($credentialsById[$id]))) {
            throw new RuntimeException('Ungültige Credential-id in der JSON-Konfiguration.');
        }
        $username = trim($credential['username']);
        $password = $credential['password'];
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x1f\x7f]/', $username) ||
            strlen($password) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
            throw new RuntimeException('Credential enthält einen ungültigen Benutzernamen oder ein ungültiges Passwort.');
        }
        if ($password === RACK_DB_PASSWORD) {
            if ($id === null) {
                throw new RuntimeException('Für [aus Datenbank] ist eine gültige Credential-id erforderlich.');
            }
            $password = (string) $credentialsById[$id]['pwd'];
        }
        $effectiveCredentials[] = ['id' => $id, 'username' => $username, 'password' => $password];
    }
    if (!$effectiveCredentials) {
        throw new RuntimeException('Keine Zugangsdaten ausgewählt. Bitte Credentials unter „Credentials verwalten“ anlegen oder im JSON ergänzen.');
    }

    $scanTargets = rackDiscoveryTargets($settings['discover'] ?? null);
    // Auch ein Gerät in einem anderen Rack zählt als bereits inventarisiert,
    // falls ein Administrator dessen IP-Bereich explizit zum Scan hinzufügt.
    $knownGlobally = [];
    foreach ($db->all('device', ['removed_at' => null], 10000) as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (isset($knownGlobally[$ip]) && (int) $knownGlobally[$ip]['id'] !== (int) $device['id']) {
                throw new RuntimeException('Mehrere aktive Geräte haben dieselbe Management-IP: ' . $ip);
            }
            $knownGlobally[$ip] = $device;
        }
    }
    $scanOnlyIps = [];
    $targetOptions = [];
    foreach ($settings['devices'] as $ip => $options) {
        if (!is_string($ip) || !isset($known[$ip])) {
            throw new RuntimeException('Unter devices dürfen nur Management-IPs des gewählten Racks stehen.');
        }
        $targetOptions[$ip] = validCheckOptions($options, true);
    }
    // Im Bereich erkannte bekannte Geräte immer prüfen, auch wenn sie nicht
    // ausdrücklich unter devices stehen. Neue IPs werden nach einem gültigen
    // Erkennungsergebnis im gewählten Rack inventarisiert.
    foreach ($scanTargets as $ip => $_) {
        if (!isset($targetOptions[$ip])) {
            $targetOptions[$ip] = [];
        }
        if (!isset($knownGlobally[$ip])) {
            $scanOnlyIps[$ip] = true;
        }
    }

    $primary = [];
    $retries = [];
    $skipped = 0;
    $assignmentCache = [];
    foreach ($targetOptions as $ip => $options) {
        // Nur bei expliziter Gerätezuordnung die globale Liste einschränken.
        // Geräte ohne Zuordnung und noch nicht inventarisierte Scan-IPs
        // verwenden weiterhin alle Credentials der JSON-Konfiguration.
        $assignedIds = [];
        if (isset($knownGlobally[$ip])) {
            $deviceId = (int) $knownGlobally[$ip]['id'];
            if (!array_key_exists($deviceId, $assignmentCache)) {
                $assignmentCache[$deviceId] = array_fill_keys(array_map(
                    static fn(array $row): int => (int) $row['id'],
                    $db->deviceCredentials($deviceId)
                ), true);
            }
            $assignedIds = $assignmentCache[$deviceId];
        }
        $byUsername = [];
        foreach ($effectiveCredentials as $credential) {
            if ($assignedIds && ($credential['id'] === null || !isset($assignedIds[$credential['id']]))) {
                continue;
            }
            $username = $credential['username'];
            $password = $credential['password'];
            if (!isset($byUsername[$username]) || !in_array($password, $byUsername[$username], true)) {
                $byUsername[$username][] = $password;
            }
        }
        if (!$byUsername) {
            throw new RuntimeException('Für ' . $ip . ' sind keine zugeordneten Credentials im JSON enthalten. Bitte die Credential-Auswahl prüfen.');
        }
        // Gleiche Benutzernamen mit mehreren Passwörtern werden nacheinander probiert.
        $selected = [];
        foreach ($byUsername as $username => $passwords) {
            $selected[$username] = $passwords[0];
            for ($i = 1, $n = count($passwords); $i < $n; ++$i) {
                $retries[$ip][] = [
                    'ip' => $ip, 'options' => $options,
                    'credentials' => [$username => $passwords[$i]],
                ];
            }
        }
        $primary[] = ['ip' => $ip, 'options' => $options, 'credentials' => $selected];
    }
    if (!$primary && !$scanTargets) {
        throw new RuntimeException('Keine Prüfziele gefunden. Bitte Management-IPs oder discover.ranges hinterlegen.');
    }
    return [$defaults, $primary, $retries, $skipped, $scanOnlyIps, count($scanTargets)];
}

/**
 * Schneller TCP-Vortest für noch nicht inventarisierte IPs aus discover.
 * Das bestehende Python-Programm verbindet sich ausschließlich über Port 23.
 * 96 parallele, nicht blockierende Verbindungen verhindern, dass ein /24 mit
 * überwiegend unbelegten Adressen minutenlange Netmiko-Timeouts verursacht.
 * Bekannte Geräte werden NICHT vorgefiltert: Dort sind Verbindungsfehler relevant.
 * Ein negativer Porttest bedeutet nur: derzeit kein erreichbarer Telnet-Dienst.
 */
function rackProbeUnknownIps(array $ips): array
{
    $reachable = [];
    foreach (array_chunk(array_keys($ips), 96) as $batch) {
        $pending = [];
        foreach ($batch as $ip) {
            $connection = @stream_socket_client(
                'tcp://' . $ip . ':23', $errorNo, $errorText, 0.5,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if (is_resource($connection)) {
                stream_set_blocking($connection, false);
                $pending[(int) $connection] = ['ip' => $ip, 'stream' => $connection];
            }
        }
        $deadline = microtime(true) + 0.5;
        while ($pending && microtime(true) < $deadline) {
            $write = array_column($pending, 'stream');
            $read = [];
            $except = $write;
            $remainingUs = max(0, (int) (($deadline - microtime(true)) * 1_000_000));
            $ready = @stream_select($read, $write, $except, 0, $remainingUs);
            if ($ready === false) break;
            if ($ready === 0) continue;
            foreach (array_merge($write, $except) as $stream) {
                $key = (int) $stream;
                if (!isset($pending[$key])) continue;
                // Ein schreibbereiter Socket kann auch ECONNREFUSED melden.
                if (function_exists('socket_import_stream')) {
                    $socket = @socket_import_stream($stream);
                    $connected = $socket !== false && @socket_get_option($socket, SOL_SOCKET, SO_ERROR) === 0;
                } else {
                    $connected = @stream_socket_get_name($stream, true) !== false;
                }
                if ($connected) $reachable[$pending[$key]['ip']] = true;
                fclose($stream);
                unset($pending[$key]);
            }
        }
        foreach ($pending as $item) fclose($item['stream']);
    }
    return $reachable;
}

/** Entfernt aus dem Python-Check nur nicht erreichbare, noch unbekannte Scan-IPs. */
function rackFilterDiscoveryPlans(array $primary, array $retries, array $scanOnlyIps): array
{
    if (!$scanOnlyIps) return [$primary, $retries, 0];
    $reachable = rackProbeUnknownIps($scanOnlyIps);
    $primary = array_values(array_filter($primary, static fn(array $plan): bool =>
        !isset($scanOnlyIps[$plan['ip']]) || isset($reachable[$plan['ip']])));
    foreach ($scanOnlyIps as $ip => $_) {
        if (!isset($reachable[$ip])) unset($retries[$ip]);
    }
    return [$primary, $retries, count($reachable)];
}

/**
 * Gruppiert Geräte mit kompatiblen globalen Zugangsdaten.
 * Die ausgewählten globalen Zugangsdaten werden an den bestehenden Python-Parser übergeben.
 * Mehrere Einträge mit identischem Username und unterschiedlichen Passwörtern werden getrennt ausgeführt.
 */
function rackCheckBatches(array $plans, array $defaults): array
{
    $groups = [];
    foreach ($plans as $plan) {
        $assigned = false;
        foreach ($groups as &$group) {
            // Nur Geräte mit exakt derselben globalen Credential-Menge gruppieren.
            if ($plan['credentials'] !== $group['credentials']) {
                continue;
            }
            $group['devices'][$plan['ip']] = (object) $plan['options'];
            $assigned = true;
            break;
        }
        unset($group);
        if (!$assigned) {
            $groups[] = [
                'credentials' => $plan['credentials'],
                'devices' => [$plan['ip'] => (object) $plan['options']],
            ];
        }
    }

    $batches = [];
    foreach ($groups as $group) {
        $credentials = [];
        foreach ($group['credentials'] as $username => $password) {
            $credentials[] = ['username' => (string) $username, 'password' => $password];
        }
        $batches[] = [
            'credentials' => $credentials,
            'defaults' => $defaults,
            'devices' => (object) $group['devices'],
        ];
    }
    return $batches;
}

/** Führt alle zu einem Rack gehörenden JSON-Pakete mit dem bestehenden main.py aus. */
function runRackPlans(array $defaults, array $primary, array $retries): array
{
    $results = [];
    foreach (rackCheckBatches($primary, $defaults) as $config) {
        $results = array_replace($results, executeRackCheck($config));
    }
    // Bei einem Gerät mit mehreren Kennwörtern desselben Benutzers weitere
    // Kennwörter nur dann prüfen, wenn die bisherigen nicht akzeptiert wurden.
    $round = 0;
    do {
        $retryPlans = [];
        $hasMore = false;
        foreach ($retries as $ip => $options) {
            if (isset($options[$round])) {
                $hasMore = true;
                $status = $results[$ip]['status'] ?? null;
                if (is_array($status)) $status = $status[0] ?? null;
                if ($status === 'authentication_failed') {
                    $retryPlans[] = $options[$round];
                }
            }
        }
        if ($retryPlans) {
            foreach (rackCheckBatches($retryPlans, $defaults) as $config) {
                $results = array_replace($results, executeRackCheck($config));
            }
        }
        ++$round;
    } while ($hasMore);
    return $results;
}

/** Nur vordefinierte, nicht-sensitive Python-Diagnosen im Browser ausgeben. */
function rackPythonFailureHint(string $stderr): string
{
    if (preg_match('/ModuleNotFoundError:\s*No module named [\'\"]([a-zA-Z0-9_.]+)[\'\"]/', $stderr, $match)) {
        return 'Python-Modul ' . $match[1] . ' fehlt. Installiere es in python_scripts/.venv (oder im über NETLAB_PYTHON konfigurierten Interpreter).';
    }
    if (str_contains($stderr, 'ImportError:')) {
        return 'Python konnte ein Modul nicht importieren. Bitte die Python-Umgebung prüfen.';
    }
    if (str_contains($stderr, 'JSONDecodeError:') || str_contains($stderr, 'KeyError:')) {
        return 'Python konnte die JSON-Konfiguration nicht verarbeiten. Prüfe die erwarteten Felder.';
    }
    if (str_contains($stderr, 'PermissionError:')) {
        return 'Python hat keine ausreichenden Dateiberechtigungen.';
    }
    if (str_contains($stderr, "can't open file")) {
        return 'Die Python-Datei main.py ist im Projektverzeichnis nicht erreichbar.';
    }
    return 'Bitte die Python-Umgebung und das Webserver-Fehlerlog prüfen.';
}

/** Startet ausschließlich das fest hinterlegte Python-Skript, ohne Shell oder Benutzereingaben als Argumente. */
function executeRackCheck(array $config): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP darf keine Prozesse starten (proc_open deaktiviert).');
    }
    $payload = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $scriptDir = dirname(__DIR__) . '/python_scripts';
    $script = $scriptDir . '/main.py';

    // PHP aktiviert virtuelle Umgebungen nicht automatisch. Den Python-Interpreter
    // der .venv direkt verwenden; NETLAB_PYTHON kann ihn bei Bedarf überschreiben.
    $configuredPython = trim((string) (getenv('NETLAB_PYTHON') ?: ''));
    $python = $configuredPython !== ''
        ? $configuredPython
        : $scriptDir . (DIRECTORY_SEPARATOR === '\\' ? '/.venv/Scripts/python.exe' : '/.venv/bin/python');

    if (!is_file($script)) {
        throw new RuntimeException('Das Python-Skript python_scripts/main.py wurde nicht gefunden.');
    }
    // Für einen explizit konfigurierten Befehlsnamen (z. B. python3) ist PATH erlaubt.
    // Bei einem absoluten/relativen Pfad hingegen Fehler frühzeitig melden.
    if (str_contains($python, '/') || str_contains($python, '\\')) {
        if (!is_file($python)) {
            throw new RuntimeException($configuredPython !== ''
                ? 'Der über NETLAB_PYTHON konfigurierte Python-Interpreter wurde nicht gefunden.'
                : 'Python-Umgebung fehlt: python_scripts/.venv/bin/python (Windows: .venv/Scripts/python.exe).');
        }
        if (!is_executable($python)) {
            throw new RuntimeException('Der Python-Interpreter ist für den Webserver-Benutzer nicht ausführbar. Bitte Dateirechte prüfen.');
        }
    }
    $process = @proc_open([$python, $script], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, dirname($script), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Python konnte nicht gestartet werden.');
    }
    $finished = false;
    try {
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        unset($pipes[0]);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $output = ['', ''];
        $start = microtime(true);
        while ($pipes) {
            if (microtime(true) - $start > 90) {
                throw new RuntimeException('Python-Check nach 90 Sekunden abgebrochen.');
            }
            $ready = array_values($pipes);
            $write = $except = [];
            $available = @stream_select($ready, $write, $except, 1);
            if ($available === false) {
                throw new RuntimeException('Python-Ausgabe konnte nicht gelesen werden.');
            }
            foreach ($ready as $stream) {
                $chunk = stream_get_contents($stream);
                $key = isset($pipes[1]) && $stream === $pipes[1] ? 0 : 1;
                $output[$key] .= $chunk;
                if (strlen($output[$key]) > ($key === 0 ? 6_000_000 : 100_000)) {
                    throw new RuntimeException('Python-Ausgabe ist zu groß.');
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($pipes[$key === 0 ? 1 : 2]);
                }
            }
        }
        $exitCode = proc_close($process);
        $finished = true;
        if ($exitCode !== 0) {
            // Stderr kann Passwörter enthalten und darf weder ins HTML noch ungefiltert ins Log.
            $hint = rackPythonFailureHint($output[1]);
            error_log('NetLab-Check: Python Exit-Code ' . $exitCode . '; ' . $hint);
            throw new RuntimeException('Python-Check fehlgeschlagen (Exit-Code ' . $exitCode . '): ' . $hint);
        }
        $result = json_decode($output[0], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('Das Python-Skript hat kein gültiges Ergebnis-Objekt geliefert.');
        }
        return $result;
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        if (!$finished) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

/**
 * Die Python-Ausgabe enthält pro Management-IP ein Ergebnis mit einem Status.
 * Nur Fehlertexte übernehmen, niemals die ebenfalls zurückgelieferten Credentials
 * oder die (möglicherweise umfangreichen) Command-Ausgaben.
 */
function rackCheckOutcome(mixed $response, array $passwords): array
{
    if (!is_array($response)) {
        return ['error', 'Python-Check: Fehler', 'Für dieses Gerät wurde kein gültiges Prüfergebnis zurückgegeben.'];
    }
    $status = $response['status'] ?? null;
    $statusDetail = null;
    // Optional bereits für einen späteren Status der Form ["ok", "..."] vorbereitet.
    if (is_array($status) && array_is_list($status)) {
        $statusDetail = $status[1] ?? null;
        $status = $status[0] ?? null;
    }
    if ($status === 'ok') {
        return ['ok', '', ''];
    }
    if ($status === 'unreachable') {
        return ['unreachable', 'Python-Check: Nicht erreichbar', 'Das Gerät war beim Netzwerkcheck nicht erreichbar.'];
    }
    if ($status === 'authentication_failed') {
        return ['authentication_failed', 'Python-Check: Anmeldung fehlgeschlagen', 'Die Anmeldung mit den verwendeten Zugangsdaten ist fehlgeschlagen.'];
    }

    if ($status === 'error') {
        $detail = is_string($response['error'] ?? null)
            ? $response['error']
            : (is_string($statusDetail) ? $statusDetail : 'Python hat einen Fehler gemeldet.');
    } else {
        $detail = is_string($status) && $status !== ''
            ? 'Unbekannter Prüfstatus: ' . $status
            : 'Python hat keinen gültigen Prüfstatus geliefert.';
    }
    // Ein Netmiko-Fehlertext kann Zugangsdaten enthalten. Vor Speicherung maskieren.
    foreach ($passwords as $password) {
        if ($password !== '') {
            $detail = str_replace($password, '[Passwort verborgen]', $detail);
        }
    }
    $detail = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $detail) ?? 'Unlesbare Python-Fehlermeldung.';
    $detail = trim(substr($detail, 0, 1800));
    return ['error', 'Python-Check: Fehler', $detail !== '' ? $detail : 'Python hat einen Fehler gemeldet.'];
}

/** Liefert auch für automatisch erfasste Rack-Geräte ohne HE die Rack-Nummer. */
function rackScanPosition(?string $position): array
{
    [$rack, $unit] = rackAndUnit($position);
    if ($rack !== null) return [$rack, $unit];
    if (preg_match('/^Rack ([1-3]) \/ Ohne HE$/D', trim((string) $position), $match)) {
        return [(int) $match[1], null];
    }
    return [null, null];
}

/**
 * Speichert Check-Fehler der vorhandenen Geräte und legt durch den IP-Scan
 * neu erkannte Geräte automatisch im ausgewählten Rack an. Eine IP wird nur
 * nach einer tatsächlichen Antwort des Python-Checks übernommen (ok,
 * authentication_failed oder error), niemals bei unreachable/fehlender Antwort.
 * Bereits inventarisierte IPs werden weder verschoben noch doppelt angelegt.
 * Die HE ist mangels physischer Erkennung zunächst eine vorläufige Zuordnung.
 */
function saveRackCheckResults(
    DBInternalAPI $db,
    array $knownDevices,
    array $plans,
    array $retries,
    array $results,
    int $rack,
    array $scanOnlyIps
): array {
    $devicesByIp = [];
    $occupiedUnits = [];
    foreach ($knownDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $devicesByIp[$ip] = $device;
        }
        [$deviceRack, $unit] = rackScanPosition($device['rack_position'] ?? null);
        if ($deviceRack === $rack && $unit !== null) {
            $occupiedUnits[$unit] = true;
        }
    }

    $passwords = [];
    foreach ($plans as $plan) {
        foreach ($plan['credentials'] as $password) {
            $passwords[$password] = $password;
        }
    }
    foreach ($retries as $options) {
        foreach ($options as $plan) {
            foreach ($plan['credentials'] as $password) {
                $passwords[$password] = $password;
            }
        }
    }
    // Längere Passwörter zuerst ersetzen, damit auch überlappende Werte maskiert werden.
    $passwords = array_values($passwords);
    usort($passwords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $counts = [];
    $failures = [];
    $registered = [];
    $withoutUnit = 0;
    foreach ($plans as $plan) {
        $ip = $plan['ip'];
        [$status, $type, $description] = rackCheckOutcome($results[$ip] ?? null, $passwords);
        $device = $devicesByIp[$ip] ?? null;
        if ($device === null) {
            // Nur tatsächlich erreichte IP-Scan-Ziele registrieren. Die Verbindung
            // zu Port 23 wurde zuvor geprüft; Python muss einen Status liefern.
            // Inaktive, unbelegte oder nicht antwortende IPs bleiben ignoriert.
            if (!isset($scanOnlyIps[$ip]) ||
                !is_array($results[$ip] ?? null) ||
                !in_array($status, ['ok', 'authentication_failed', 'error'], true)) {
                continue;
            }
            $rawStatus = $results[$ip]['status'] ?? null;
            if (is_array($rawStatus) && array_is_list($rawStatus)) {
                $rawStatus = $rawStatus[0] ?? null;
            }
            if (!in_array($rawStatus, ['ok', 'authentication_failed', 'error'], true)) {
                continue;
            }

            $unit = null;
            for ($candidate = 1; $candidate <= 30; ++$candidate) {
                if (!isset($occupiedUnits[$candidate])) {
                    $unit = $candidate;
                    $occupiedUnits[$candidate] = true;
                    break;
                }
            }
            $position = $unit === null ? "Rack $rack / Ohne HE" : "Rack $rack / HE $unit";
            if ($unit === null) ++$withoutUnit;
            $hostname = 'Scan-' . str_replace('.', '-', $ip);
            $deviceId = (int) $db->insert('device', [
                'hostname' => $hostname,
                'device_type' => 'Unbekannt',
                'management_ip' => $ip,
                'rack_position' => $position,
                'notes' => $unit === null
                    ? 'Automatisch per IP-Scan erkannt. Keine freie HE; Position und Gerätetyp bitte manuell zuweisen.'
                    : 'Automatisch per IP-Scan erkannt. HE vorläufig zugeordnet; Position und Gerätetyp bitte prüfen.',
            ]);
            $device = ['id' => $deviceId, 'hostname' => $hostname, 'management_ip' => $ip];
            $devicesByIp[$ip] = $device;
            $registered[] = ['id' => $deviceId, 'ip' => $ip, 'position' => $position];
        }
        $deviceId = (int) $device['id'];
        $counts[$status] = ($counts[$status] ?? 0) + 1;

        $existing = array_values(array_filter(
            $db->all('issue', ['device_id' => $deviceId, 'resolved_at' => null], 10000),
            static fn(array $issue): bool => str_starts_with((string) $issue['type'], 'Python-Check: ')
        ));
        $matchingIssueId = null;
        foreach ($existing as $issue) {
            if ($status !== 'ok' && $issue['type'] === $type && $matchingIssueId === null) {
                $matchingIssueId = (int) $issue['id'];
                if ($issue['description'] !== $description) {
                    $db->update('issue', $matchingIssueId, ['description' => $description]);
                }
            } else {
                $db->resolveIssue((int) $issue['id']);
            }
        }
        if ($status !== 'ok') {
            if ($matchingIssueId === null) {
                $db->insert('issue', [
                    'device_id' => $deviceId,
                    'type' => $type,
                    'description' => $description,
                ]);
            }
            $deviceName = trim((string) $device['hostname']);
            $issueLabel = substr($type, strlen('Python-Check: '));
            $failures[] = ($deviceName !== '' ? $deviceName . ' (' . $ip . ')' : $ip)
                . ': ' . $issueLabel . ' – ' . $description;
        }
        $db->update('device', $deviceId, ['last_checked_at' => gmdate('Y-m-d H:i:s')]);
    }
    return [$counts, $failures, $registered, $withoutUnit];
}

$devices = $db->all('device', ['removed_at' => null], 10000);
$issues = $db->openIssues();
// Befehle nur bei angemeldeten Admins für die Check-UI laden.
$checkCredentialRows = isAdmin() ? $db->all('credential', [], 10000) : [];
$checkCommands = isAdmin()
    ? array_values(array_map(static fn(array $row): string => (string) $row['command'], $db->all('command', [], 50)))
    : [];
sort($checkCommands);
$checkNotice = isAdmin() ? ($_SESSION['rack_check_notice'] ?? null) : null;
unset($_SESSION['rack_check_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    checkCsrf();
    $rack = positiveId($_POST['rack'] ?? null);
    if ($rack < 1 || $rack > 3) {
        http_response_code(400);
        exit('Ungültiges Rack.');
    }
    $mode = (string) ($_POST['mode'] ?? '');
    try {
        $rackDevices = array_values(array_filter($devices, static fn(array $device): bool => rackScanPosition($device['rack_position'])[0] === $rack));
        if ($mode === 'default') {
            $settings = rackEditableConfig($rack, $rackDevices, $checkCommands, $checkCredentialRows);
            $settings['devices'] = (array) $settings['devices'];
            foreach ($settings['devices'] as &$options) $options = [];
            unset($options);
        } elseif ($mode === 'custom') {
            $raw = (string) ($_POST['settings_json'] ?? '');
            if (strlen($raw) > 64_000) {
                throw new RuntimeException('JSON-Konfiguration darf maximal 64 KB groß sein.');
            }
            $settings = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } else {
            throw new RuntimeException('Unbekannte Prüfaktion.');
        }
        [$defaults, $primary, $retries, $skipped, $scanOnlyIps, $scanSize] = rackExecutionPlans($db, $rackDevices, $settings);
        foreach ($rackDevices as $device) {
            if (!filter_var(trim((string) $device['management_ip']), FILTER_VALIDATE_IP)) {
                ++$skipped;
            }
        }
        // Ein aktiver Check zur Zeit, damit nicht mehrere Anfragen dieselben Geräte parallel belasten.
        $lock = fopen(dirname(__DIR__) . '/storage/rack_check.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Es läuft bereits ein Rack-Check. Bitte danach erneut starten.');
        }
        try {
            set_time_limit(130);
            // Unbekannte IPs ohne Telnet-Dienst nicht erst mit Netmiko abfragen.
            // Vorhandene Rack-Geräte werden auch bei Nichterreichbarkeit geprüft.
            [$primary, $retries, $scanResponsive] = rackFilterDiscoveryPlans($primary, $retries, $scanOnlyIps);
            $results = $primary ? runRackPlans($defaults, $primary, $retries) : [];
            // Ergebnisse noch unter dem Rack-Check-Lock speichern, damit ein
            // zeitgleicher Check keine neueren Befunde überschreiben kann.
            [$counts, $failures, $registered, $withoutUnit] = saveRackCheckResults(
                $db, $devices, $primary, $retries, $results, $rack, $scanOnlyIps
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $ok = $counts['ok'] ?? 0;
        $total = array_sum($counts);
        $message = "Rack $rack: $ok von $total Geräten erfolgreich geprüft";
        if ($scanSize) {
            $message .= "; IP-Scan: $scanSize Adressen, $scanResponsive bisher unbekannte IPs mit offenem Telnet-Port";
            $message .= ', ' . count($registered) . ' neue Geräte im Rack registriert';
            if ($withoutUnit) $message .= " ($withoutUnit ohne freie HE)";
        }
        if ($skipped) $message .= ", $skipped ohne gültige Management-IP übersprungen";
        $message .= '.';
        $_SESSION['rack_check_notice'] = [
            'rack' => $rack, 'success' => !$failures && !$skipped,
            'message' => $message,
            'failures' => array_slice($failures, 0, 20),
            'registered' => array_slice($registered, 0, 40),
            'registered_count' => count($registered),
        ];
    } catch (Throwable $error) {
        $_SESSION['rack_check_notice'] = [
            'rack' => $rack, 'success' => false, 'message' => $error instanceof JsonException
                ? 'Ungültige JSON-Konfiguration: ' . $error->getMessage() : $error->getMessage(),
            'failures' => [],
        ];
    }
    redirect('index.php#rack-' . $rack);
}
foreach ($devices as &$device) {
    [$device['rack'], $device['unit']] = rackScanPosition($device['rack_position']);
    $device['status'] = statusFor((int) $device['id'], $issues, $device['last_checked_at']);
}
unset($device);
$deviceById = array_column($devices, null, 'id');
$statuses = array_count_values(array_column($devices, 'status'));
$statusCount = static fn(string $status): int => $statuses[$status] ?? 0;
$problemRacks = [];
foreach ($devices as $d) {
    if ($d['rack'] !== null && in_array($d['status'], ['warning', 'critical'], true))
        $problemRacks[$d['rack']] = true;
}
$filter = ($_GET['filter'] ?? '') === 'issues' ? 'issues' : 'all';
$query = trim((string) ($_GET['q'] ?? ''));
$visible = array_values(array_filter($devices, static function (array $d) use ($query): bool {
    if ($query === '')
        return true;
    return str_contains(strtolower(implode(' ', [
        $d['hostname'],
        $d['management_ip'],
        $d['device_type'],
        $d['model'],
        $d['serial_number'],
        $d['rack_position'],
        statusLabel($d['status']),
    ])), strtolower($query));
}));
usort($visible, static fn(array $a, array $b): int => (($b['rack'] ?? 0) <=> ($a['rack'] ?? 0)) ?: (($b['unit'] ?? 0) <=> ($a['unit'] ?? 0)));
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#09090b">
    <meta name="description" content="NetLab Guardian Netzwerkgeräteinventar">
    <title>NetLab Guardian — Netzwerk im Blick</title>
    <link rel="stylesheet" href="style.css?v=20261102-2">
</head>

<body>
    <header class="header"><a class="brand" href="index.php">NetLab Guardian</a>
        <nav aria-label="Hauptnavigation"><a class="active" href="#overview">Übersicht</a><a
                href="#inventory">Geräteinventar</a><a href="#issues">Probleme <span
                    class="nav-count"><?= count($issues) ?></span></a></nav>
        <div class="header-actions"><span class="user-badge"><?= h($_SESSION['user']) ?> ·
                <?= h($_SESSION['role']) ?></span><?php if (isAdmin()): ?><a class="button outline"
                    href="admin.php">Adminbereich</a><a class="button outline" href="credentials.php">Credentials</a><?php endif; ?>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf"
                    value="<?= h(csrf()) ?>"><button class="button outline" type="submit">Abmelden</button></form>
        </div>
    </header>
    <main>
        <section class="hero" aria-labelledby="heroTitle">
            <div class="hero-art" aria-hidden="true"></div>
            <div class="hero-overlay" aria-hidden="true"></div>
            <div class="hero-copy">
                <div class="eyebrow">Netzwerkverwaltung</div>
                <h1 id="heroTitle">Das Labornetzwerk<br>im Überblick</h1>
                <p>Inventar und bekannte Probleme der Switches und Router in drei Labor-Racks.</p><a class="hero-link"
                    href="#overview">Zum Rack-Monitor <span>↓</span></a>
            </div>
            <div class="hero-bottom"><span>SQLite-Inventar</span><span>3 Racks <b>/</b> <?= count($devices) ?> Geräte
                    <b>/</b> Labor-Netzwerk</span></div>
        </section>
        <section class="workspace" id="overview">
            <div class="section-top">
                <div>
                    <div class="eyebrow muted">LABORÜBERSICHT</div>
                    <h2>Rack-Übersicht</h2>
                </div>
                <div class="sync">Inventardaten aus SQLite · Rack-Checks manuell starten</div>
            </div>
            <div class="stats">
                <div><span>Geräte insgesamt</span><strong><?= count($devices) ?> <small><?= $statusCount('unknown') ?>
                            nicht geprüft</small></strong></div>
                <div><span>Zuletzt geprüft</span><strong><?= $statusCount('checked') ?> <small>Ohne offene
                            Probleme</small></strong></div>
                <div><span>Warnungen</span><strong><?= $statusCount('warning') ?> <small>Offene
                            Meldungen</small></strong></div>
                <div><span>Kritisch</span><strong class="red-text"><?= $statusCount('critical') ?> <small>Aktion
                            erforderlich</small></strong></div>
            </div>
            <div class="rack-toolbar">
                <div class="segmented" aria-label="Rack-Filter"><a class="<?= $filter === 'all' ? 'selected' : '' ?>"
                        href="index.php?filter=all#overview">Alle Racks <span>3</span></a><a
                        class="<?= $filter === 'issues' ? 'selected' : '' ?>"
                        href="index.php?filter=issues#overview">Mit Problemen
                        <span><?= count($problemRacks) ?></span></a></div><span class="rack-hint">Gerät auswählen für
                    Details</span>
            </div>
            <div class="racks">
                <?php $displayRacks = $filter === 'issues' ? array_keys($problemRacks) : [3, 2, 1];
                rsort($displayRacks); ?>
                <?php foreach ($displayRacks as $rack): ?>
                    <?php
                    $rackDevices = array_values(array_filter($devices, static fn(array $d): bool => $d['rack'] === $rack));
                    $rackStatuses = array_column($rackDevices, 'status');
                    $rackStatus = in_array('critical', $rackStatuses, true) ? 'critical' : (in_array('warning', $rackStatuses, true) ? 'warning' : (in_array('unknown', $rackStatuses, true) ? 'unknown' : 'checked'));
                    $rackSlots = [];
                    $rackUnpositioned = [];
                    foreach ($rackDevices as $d) {
                        if ($d['unit'] === null) {
                            $rackUnpositioned[] = $d;
                        } else {
                            $rackSlots[$d['unit']] = $d;
                        }
                    }
                    $switches = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'switch'));
                    $routers = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'router'));
                    $editorJson = isAdmin()
                        ? json_encode(rackEditableConfig($rack, $rackDevices, $checkCommands, $checkCredentialRows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                        : ''; 
                    ?>
                    <article class="rack-card" id="rack-<?= $rack ?>">
                        <div class="rack-heading">
                            <div class="rack-heading-title">
                                <h3>Rack <?= $rack ?> — <?= h(rackLabel($rack)) ?></h3>
                                <span class="pill <?= pillClass($rackStatus) ?>"><?= h(statusLabel($rackStatus)) ?></span>
                            </div>
                            <?php if (isAdmin()): ?>
                                <div class="rack-actions">
                                    <form method="post" action="index.php#rack-<?= $rack ?>">
                                        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                        <input type="hidden" name="rack" value="<?= $rack ?>">
                                        <input type="hidden" name="mode" value="default">
                                        <button type="submit" class="rack-run-button" title="Rack-Geräte und das zugehörige IP-Netz prüfen">▶ Prüfen + IP-Scan</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (isAdmin()): ?>
                            <details class="rack-settings">
                                        <summary aria-label="JSON-Einstellungen für Rack <?= $rack ?>" title="JSON-Einstellungen für Rack <?= $rack ?>">⚙</summary>
                                        <div class="rack-settings-panel">
                                            <form method="post" action="index.php#rack-<?= $rack ?>">
                                                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                                <input type="hidden" name="rack" value="<?= $rack ?>">
                                                <input type="hidden" name="mode" value="custom">
                                                <label for="rack-json-<?= $rack ?>">JSON-Konfiguration für Rack <?= $rack ?></label>
                                                <p>Temporäre JSON-Einstellungen: <code>discover.ranges</code> definiert die zu scannenden IPv4-Bereiche und <code>discover.exclude</code> nimmt IPs nur vom Range-Scan aus (explizite <code>devices</code> bleiben aktiv). Das Rack-Netz 10.78.<?= $rack ?>.1–254 ist voreingestellt. Unter <code>devices</code> bleiben vorhandene Geräte separat konfigurierbar. Unbelegte IPs erzeugen keine Fehler. Erreichbare, vom Python-Skript erkannte neue Geräte werden im Rack registriert, vorläufig einer freien HE zugewiesen und können anschließend im Adminbereich bearbeitet werden. Falls das Rack voll ist, werden sie ohne HE registriert. Bereits vorhandene Geräte bleiben in ihrem Rack; gemeldete Fehler werden gespeichert. Die Passwörter bleiben mit <code>[aus Datenbank]</code> verborgen und werden beim Start serverseitig ergänzt. Du kannst Username oder Passwort im Editor nur für diesen einen Prüflauf ändern oder neue Einträge ohne id hinzufügen. Die JSON-Einstellungen werden nicht gespeichert; Inventar und Prüfergebnisse werden in SQLite gespeichert. Gespeicherte Zugangsdaten lassen sich unter <a href="credentials.php">Credentials verwalten</a> bearbeiten und einzelnen Geräten zuordnen. Ohne Gerätezuordnung werden alle globalen Credentials verwendet; bei einer Zuordnung nur diese. Hinweis: Das unveränderte Python-Skript verwendet derzeit immer Port 23.</p>
                                                <textarea id="rack-json-<?= $rack ?>" name="settings_json" rows="13" maxlength="64000" spellcheck="false" required><?= h($editorJson) ?></textarea>
                                                <div class="rack-settings-buttons">
                                                    <button type="reset" class="rack-reset-button">Zurücksetzen</button>
                                                    <button type="submit" class="rack-run-button rack-run-custom">Mit Einstellungen prüfen</button>
                                                </div>
                                            </form>
                                        </div>
                            </details>
                        <?php endif; ?>
                        <?php if (is_array($checkNotice) && (int) $checkNotice['rack'] === $rack): ?>
                            <div class="rack-check-notice notice <?= $checkNotice['success'] ? 'success' : 'error' ?>" role="status">
                                <strong><?= h($checkNotice['message']) ?></strong>
                                <?php if ($checkNotice['failures']): ?>
                                    <p><?= h(implode(' · ', $checkNotice['failures'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($checkNotice['registered'])): ?>
                                    <p>Neu im Rack registriert (<?= (int) $checkNotice['registered_count'] ?>):
                                        <?php foreach ($checkNotice['registered'] as $newDevice): ?>
                                            <a href="device.php?id=<?= (int) $newDevice['id'] ?>"><?= h($newDevice['ip']) ?></a>
                                            (<?= h($newDevice['position']) ?>)
                                        <?php endforeach; ?>
                                        <?= $checkNotice['registered_count'] > count($checkNotice['registered']) ? ' …' : '' ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="rack-frame">
                            <?php for ($unit = 1; $unit <= 30; ++$unit): ?>
                                <?php if (!isset($rackSlots[$unit])): ?>
                                    <div class="empty-unit"><span
                                            class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span><span>Frei</span>
                                    </div>
                                <?php else:
                                    $d = $rackSlots[$unit];
                                    $isRouter = strtolower($d['device_type']) === 'router'; ?>
                                    <a class="device <?= h($d['status']) ?> <?= $isRouter ? 'router' : '' ?> <?= trim((string) $d['notes']) !== '' ? 'has-note' : '' ?>"
                                        href="device.php?id=<?= (int) $d['id'] ?>"
                                        aria-label="<?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?>, <?= h(statusLabel($d['status'])) ?>, Details öffnen"><span
                                            class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span><span
                                            class="device-name"><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?></span><?php if (trim((string) $d['notes']) !== ''): ?><span
                                                class="note-marker" title="Notiz vorhanden">N</span><?php endif; ?><span class="ports"
                                            aria-hidden="true"><?php for ($p = 0; $p < ($isRouter ? 4 : 16); ++$p): ?><i
                                                    class="port"></i><?php endfor; ?></span></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <?php if ($rackUnpositioned): ?>
                            <div class="rack-footer">
                                <span><b>Ohne HE zugeordnet:</b>
                                    <?php foreach ($rackUnpositioned as $unpositioned): ?>
                                        <a href="<?= isAdmin() ? 'admin.php?edit=' . (int) $unpositioned['id'] . '#editor' : 'device.php?id=' . (int) $unpositioned['id'] ?>"><?= h($unpositioned['hostname'] ?: $unpositioned['management_ip']) ?></a>
                                    <?php endforeach; ?>
                                </span>
                                <span>HE im Adminbereich zuweisen</span>
                            </div>
                        <?php endif; ?>
                        <div class="rack-footer"><span><b><?= count($rackDevices) ?> Geräte</b> / <?= $switches ?> Switches
                                / <?= $routers ?> Router</span><span><?= count($rackSlots) ?> / 30 HE</span></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$displayRacks): ?>
                    <p class="subtle">Keine Racks mit offenen Problemen.</p><?php endif; ?>
            </div>
            <div class="legend"><span>Geprüft</span><span>Warnung</span><span>Kritisch</span><span>Nicht
                    geprüft</span><span>19″ Racks · 30 HE · schematische Darstellung</span></div>
            <section class="issues" id="issues">
                <div class="section-top">
                    <div>
                        <div class="eyebrow muted">AKTUELLE MELDUNGEN</div>
                        <h2>Offene Probleme <span class="pill red-pill"><?= count($issues) ?></span></h2>
                    </div><span class="subtle">Aus der SQLite-Tabelle issue</span>
                </div>
                <?php foreach ($issues as $issue):
                    $d = $deviceById[$issue['device_id']] ?? null; ?>
                    <div class="issue-row"><span class="issue-icon <?= issueCritical($issue) ? '' : 'warn' ?>">!</span>
                        <div class="issue-copy"><strong><?= h($issue['type']) ?></strong>
                            <p><?= h($d['hostname'] ?? 'Gerät ' . $issue['device_id']) ?> — <?= h($issue['description']) ?>
                            </p>
                        </div><?php if ($d): ?><a class="text-button" href="device.php?id=<?= (int) $d['id'] ?>">Prüfen
                                ↗</a><?php else: ?><span class="subtle">Gerät nicht aktiv</span><?php endif; ?>
                    </div><?php endforeach; ?>
                <?php if (!$issues): ?>
                    <p class="subtle">Keine offenen Probleme erfasst.</p><?php endif; ?>
            </section>
            <section class="inventory" id="inventory">
                <div class="section-top">
                    <div>
                        <div class="eyebrow muted">INVENTAR / SQLite</div>
                        <h2>Geräteinventar</h2>
                    </div>
                    <form class="search" method="get" action="index.php#inventory"><span>⌕</span><input name="q"
                            type="search" value="<?= h($query) ?>" placeholder="Gerät oder IP suchen …"
                            aria-label="Gerät oder IP suchen"><button type="submit" class="text-button">Suchen</button>
                    </form>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Gerät</th>
                                <th>Typ / Modell</th>
                                <th>IP-Adresse</th>
                                <th>Standort</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visible as $d): ?>
                                <tr>
                                    <td><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?><?= trim((string) $d['notes']) !== '' ? ' Ⓝ' : '' ?>
                                    </td>
                                    <td><?= h($d['model'] ?: $d['device_type']) ?></td>
                                    <td><?= h($d['management_ip'] ?: '—') ?></td>
                                    <td><?= h($d['rack_position'] ?: 'Ohne Rack') ?></td>
                                    <td><span
                                            class="pill <?= pillClass($d['status']) ?>"><?= h(statusLabel($d['status'])) ?></span>
                                    </td>
                                    <td><a class="text-button" href="device.php?id=<?= (int) $d['id'] ?>">Details ↗</a></td>
                                </tr><?php endforeach; ?>
                            <?php if (!$visible): ?>
                                <tr>
                                    <td colspan="6">Keine Geräte gefunden.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="subtle"><?= count($visible) ?> von <?= count($devices) ?> Geräten</p>
            </section>
        </section>
    </main>
    <footer><a class="brand" href="index.php">NetLab Guardian</a><span>Netzwerkverwaltung für den
            Unterricht</span><span>Inventardaten · manuelle Rack-Prüfungen</span></footer>
</body>

</html>