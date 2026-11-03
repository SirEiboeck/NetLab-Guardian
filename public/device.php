<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
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
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($device['hostname'] ?: 'Gerät ' . $id) ?> — NetLab Guardian</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <header class="header"><a class="brand" href="index.php">NetLab Guardian</a>
        <div class="header-actions"><a class="button outline" href="index.php#inventory">Zur
                Übersicht</a><?php if (isAdmin()): ?><a class="button outline"
                    href="admin.php?edit=<?= $id ?>#editor">Bearbeiten</a><?php endif; ?>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf"
                    value="<?= h(csrf()) ?>"><button class="button outline">Abmelden</button></form>
        </div>
    </header>
    <main class="workspace detail-workspace">
        <div class="eyebrow muted">GERÄTEDETAILS</div>
        <div class="section-top">
            <h1><?= h($device['hostname'] ?: 'Gerät ' . $id) ?></h1><span
                class="pill <?= pillClass($status) ?>"><?= h(statusLabel($status)) ?></span>
        </div>
        <?php if ($flash): ?>
            <p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?>
            <p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <div class="panel">
            <h2>Geräteinformationen</h2>
            <div class="dialog-grid">
                <?php foreach ([
                    'Gerätetyp' => $device['device_type'],
                    'Modell' => $device['model'],
                    'Management-IP' => $device['management_ip'],
                    'Rackposition' => $device['rack_position'],
                    'Seriennummer' => $device['serial_number'],
                    'Firmware' => $device['firmware'],
                    'Konsolenport' => $device['console_port'],
                    'Inventarnummer' => $device['inventory_number'],
                    'Erstmals gesehen' => $device['first_seen'],
                    'Zuletzt geprüft' => $device['last_checked_at'],
                ] as $label => $value): ?>
                    <div><span><?= h($label) ?></span><b><?= h($value ?: '—') ?></b></div><?php endforeach; ?>
            </div>
        </div>
        <div class="panel">
            <h2>Notizen</h2><?php if (trim((string) $device['notes']) !== ''): ?>
                <div class="saved-note">
                    <p><?= h($device['notes']) ?></p>
                </div><?php else: ?>
                <p class="subtle">Keine Notizen hinterlegt.</p><?php endif; ?>
            <?php if (isAdmin()): ?>
                <form class="device-form" method="post" action="device.php?id=<?= $id ?>"><input type="hidden" name="csrf"
                        value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="notes"><textarea name="notes"
                        maxlength="4000" rows="5" aria-label="Notizen"><?= h($device['notes']) ?></textarea>
                    <div class="dialog-actions"><button class="button light" type="submit">Notizen speichern</button></div>
                </form><?php endif; ?>
        </div>
        <div class="panel">
            <h2>Störungen <span class="pill red-pill"><?= count($openIssues) ?> offen</span></h2>
            <?php foreach ($allIssues as $issue): ?>
                <div class="issue-row"><span class="issue-icon <?= issueCritical($issue) ? '' : 'warn' ?>">!</span>
                    <div class="issue-copy"><strong><?= h($issue['type']) ?>
                            <?= $issue['resolved_at'] !== null ? '· Behoben' : '· Offen' ?></strong>
                        <p><?= h($issue['description']) ?></p>
                        <p>Erkannt:
                            <?= h($issue['detected_at']) ?>    <?= $issue['resolved_at'] ? ' · Behoben: ' . h($issue['resolved_at']) : '' ?>
                        </p>
                    </div>
                    <?php if (isAdmin() && $issue['resolved_at'] === null): ?>
                        <form method="post" action="device.php?id=<?= $id ?>"><input type="hidden" name="csrf"
                                value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="resolve_issue"><input
                                type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>"><button type="submit"
                                class="button outline">Behoben</button></form><?php endif; ?>
                </div><?php endforeach; ?>
            <?php if (!$allIssues): ?>
                <p class="subtle">Zu diesem Gerät sind keine Störungen erfasst.</p><?php endif; ?>
            <?php if (isAdmin()): ?>
                <h3>Neue Störung erfassen</h3>
                <form class="device-form" method="post" action="device.php?id=<?= $id ?>"><input type="hidden" name="csrf"
                        value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="add_issue"><label>Typ *<select
                            name="type" required>
                            <option value="Warnung">Warnung</option>
                            <option value="Kritisch">Kritisch</option>
                            <option value="Nicht erreichbar">Nicht erreichbar</option>
                            <option value="Info">Info</option>
                        </select></label><label>Beschreibung *<textarea name="description" maxlength="2000" rows="4"
                            required><?= h($_POST['description'] ?? '') ?></textarea></label>
                    <div class="dialog-actions"><button type="submit" class="button light">Störung hinzufügen</button></div>
                </form><?php endif; ?>
        </div>
        <p class="subtle">Status wird aus offenen Störungen und dem letzten Prüfzeitpunkt abgeleitet; kein automatischer
            Netzwerkcheck.</p>
    </main>
</body>

</html>