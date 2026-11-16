<?php
declare(strict_types=1);

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
    $scriptDir = ProjectPaths::pythonScripts();
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
        if (trim($output[1]) !== '') {
            // Auch bei Exit-Code 0 können einzelne Geräte/Befehle scheitern.
            // Tracebacks ausschließlich im Webserver-Log, nie im HTML anzeigen.
            $safeLog = $output[1];
            $secrets = [];
            foreach ($config['credentials'] ?? [] as $row) {
                if (is_array($row) && is_string($row['password'] ?? null) && $row['password'] !== '') {
                    $secrets[$row['password']] = true;
                }
            }
            $passwords = array_keys($secrets);
            usort($passwords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($passwords as $secret) {
                $safeLog = str_replace($secret, '[Passwort verborgen]', $safeLog);
            }
            error_log('NetLab-Check Python-Diagnose: ' . substr($safeLog, 0, 9000));
        }
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

/** Begrenzt und maskiert extern gelieferte Texte vor ihrer Speicherung. */
