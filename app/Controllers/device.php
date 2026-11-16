<?php
declare(strict_types=1);

requireLogin();
$id = positiveId($_GET['id'] ?? $_POST['device_id'] ?? null);
$device = $id ? $db->get('device', $id) : null;
if (!$device || $device['removed_at'] !== null) {
    http_response_code(404);
    exit('Gerät nicht gefunden.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    checkCsrf();
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'notes':
                $notes = trim((string) ($_POST['notes'] ?? ''));
                if (strlen($notes) > 4000)
                    throw new RuntimeException('Notiz ist zu lang (maximal 4000 Zeichen).');
                $db->update('device', $id, ['notes' => $notes === '' ? null : $notes]);
                flash('Notizen wurden gespeichert.');
                break;
            case 'add_issue':
                $type = trim((string) ($_POST['type'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                if ($type === '' || $description === '' || strlen($type) > 100 || strlen($description) > 2000) {
                    throw new RuntimeException('Bitte Typ (max. 100 Zeichen) und Beschreibung (max. 2000 Zeichen) angeben.');
                }
                $db->insert('issue', ['device_id' => $id, 'type' => $type, 'description' => $description]);
                flash('Störung wurde erfasst.');
                break;
            case 'resolve_issue':
                $issueId = positiveId($_POST['issue_id'] ?? null);
                $issue = $issueId ? $db->get('issue', $issueId) : null;
                if (!$issue || (int) $issue['device_id'] !== $id)
                    throw new RuntimeException('Störung gehört nicht zu diesem Gerät.');
                $db->resolveIssue($issueId);
                flash('Störung als behoben markiert.');
                break;
            default:
                throw new RuntimeException('Unbekannte Aktion.');
        }
        redirect('device.php?id=' . $id);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Änderung konnte nicht gespeichert werden.';
    }
}
$device = $db->get('device', $id);
$allIssues = $db->all('issue', ['device_id' => $id], 10000);
usort($allIssues, static fn(array $a, array $b) => strcmp($b['detected_at'], $a['detected_at']));
$openIssues = array_values(array_filter($allIssues, static fn(array $issue): bool => $issue['resolved_at'] === null));
$status = statusFor($id, $openIssues, $device['last_checked_at']);
$flash = takeFlash();
