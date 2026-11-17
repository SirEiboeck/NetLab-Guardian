<?php
declare(strict_types=1);

// Direkte Incident-Aktionen auf details.php; keine Navigation zur Geräteseite nötig.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
requireAdmin();
checkCsrf();

$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['resolve_issue', 'delete_issue'], true)) {
    http_response_code(400);
    exit('Ungültige Incident-Aktion.');
}
$issueId = positiveId($_POST['issue_id'] ?? null);
$issue = $issueId > 0 ? $db->get('issue', $issueId) : null;
if (!$issue) {
    http_response_code(404);
    exit('Incident nicht gefunden.');
}
if ($action === 'resolve_issue') {
    if ($issue['resolved_at'] === null) {
        $db->resolveIssue($issueId);
        flash('Incident #' . $issueId . ' als gelöst markiert.');
    } else {
        flash('Incident #' . $issueId . ' ist bereits gelöst.');
    }
} else {
    // Wie auf device.php: nur manuell erstellte Incidents löschen;
    // automatische Incidents bleiben für die Check-Historie erhalten.
    if ($issue['source_key'] !== null || str_starts_with((string) $issue['type'], 'Python-Check: ')) {
        http_response_code(403);
        exit('Automatische Incidents können nicht gelöscht werden.');
    }
    $db->delete('issue', $issueId);
    flash('Manueller Incident #' . $issueId . ' gelöscht.');
}
$filter = (string) ($_POST['incidents'] ?? 'open');
if (!in_array($filter, ['open', 'resolved', 'all'], true)) $filter = 'open';
redirect('details.php?incidents=' . rawurlencode($filter) . '#issues');
