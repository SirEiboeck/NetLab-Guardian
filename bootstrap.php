<?php
declare(strict_types=1);

require_once __DIR__ . '/db_internal_api.php';

// Datenbank liegt außerhalb von /public und ist nicht per URL abrufbar.
$db = new DBInternalAPI();

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; script-src 'none'; object-src 'none'");
    header('Cache-Control: no-store, private');
    session_name('netlab_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf(): string
{
    if (!isset($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function checkCsrf(): void
{
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Ungültige Anfrage (CSRF).');
    }
}
function loggedIn(): bool
{
    return isset($_SESSION['user'], $_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'viewer'], true);
}
function isAdmin(): bool
{
    return loggedIn() && $_SESSION['role'] === 'admin';
}
function requireLogin(): void
{
    if (!loggedIn()) {
        header('Location: login.php', true, 303);
        exit;
    }
}
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        exit('Zugriff nur für Administratoren.');
    }
}
function redirect(string $destination): never
{
    header('Location: ' . $destination, true, 303);
    exit;
}
function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}
function takeFlash(): string
{
    $message = (string) ($_SESSION['flash'] ?? '');
    unset($_SESSION['flash']);
    return $message;
}
function positiveId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : $id;
}
function rackAndUnit(?string $position): array
{
    if (preg_match('/^Rack ([1-3]) \/ HE ([1-9]|[12][0-9]|30)$/', trim((string) $position), $m)) {
        return [(int) $m[1], (int) $m[2]];
    }
    return [null, null];
}
function rackLabel(int $rack): string
{
    return '10.78.' . $rack . '.x';
}
function statusFor(int $deviceId, array $openIssues, ?string $lastChecked): string
{
    $level = 'unknown';
    foreach ($openIssues as $issue) {
        if ((int) $issue['device_id'] !== $deviceId)
            continue;
        if (preg_match('/critical|kritisch|offline|down|ausfall|nicht erreichbar|error/i', (string) $issue['type']))
            return 'critical';
        $level = 'warning';
    }
    return $level === 'unknown' && $lastChecked !== null ? 'checked' : $level;
}
function statusLabel(string $status): string
{
    return ['critical' => 'Kritisch', 'warning' => 'Warnung', 'checked' => 'Geprüft', 'unknown' => 'Nicht geprüft'][$status] ?? 'Unbekannt';
}
function pillClass(string $status): string
{
    return ['critical' => 'red-pill', 'warning' => 'amber-pill', 'checked' => 'green-pill', 'unknown' => 'neutral-pill'][$status] ?? 'neutral-pill';
}
function issueCritical(array $issue): bool
{
    return (bool) preg_match('/critical|kritisch|offline|down|ausfall|nicht erreichbar|error/i', (string) $issue['type']);
}
