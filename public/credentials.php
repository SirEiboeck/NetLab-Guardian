<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();

// Zugangsdaten dürfen nur aktive Administratoren lesen, auch bei einer alten Sitzung.
$currentAdmin = $db->siteUser((string) $_SESSION['user']);
if (!$currentAdmin || $currentAdmin['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff nur für aktive Administratoren. Bitte neu anmelden.');
}

$error = '';
$editParam = (string) ($_GET['edit'] ?? '');
$deleteParam = (string) ($_GET['delete'] ?? '');
$editId = positiveId($editParam);
$deleteId = positiveId($deleteParam);
$selectedDeviceId = positiveId($_GET['device'] ?? null);
if (($editParam !== '' && $editParam !== 'new' && !$editId) || ($deleteParam !== '' && !$deleteId)) {
    http_response_code(400);
    exit('Ungültige ID.');
}
$editing = $editId ? $db->get('credential', $editId) : null;
$deleting = $deleteId ? $db->get('credential', $deleteId) : null;
if (($editId && !$editing) || ($deleteId && !$deleting)) {
    http_response_code(404);
    exit('Credential nicht gefunden.');
}

$devices = $db->all('device', ['removed_at' => null], 10000);
usort($devices, static fn(array $a, array $b): int => strnatcasecmp(
    (string) ($a['hostname'] ?: $a['management_ip'] ?: $a['id']),
    (string) ($b['hostname'] ?: $b['management_ip'] ?: $b['id'])
));
$devicesById = array_column($devices, null, 'id');
if ($selectedDeviceId && !isset($devicesById[$selectedDeviceId])) {
    http_response_code(404);
    exit('Gerät nicht gefunden.');
}

$formUsername = (string) ($editing['username'] ?? '');
$formPassword = (string) ($editing['pwd'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $id = positiveId($_POST['id'] ?? null);
            if ($id && !$db->get('credential', $id)) {
                throw new RuntimeException('Credential nicht gefunden.');
            }
            if (!is_string($_POST['username'] ?? null) || !is_string($_POST['password'] ?? null)) {
                throw new RuntimeException('Benutzername und Passwort sind erforderlich.');
            }
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $formUsername = $username;
            $formPassword = $password;
            if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
                throw new RuntimeException('Benutzername: maximal 128 Zeichen, keine Steuerzeichen.');
            }
            if (strlen($password) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
                throw new RuntimeException('Passwort: maximal 1024 Zeichen, keine Steuerzeichen.');
            }
            foreach ($db->all('credential', [], 10000) as $row) {
                if ((int) $row['id'] !== $id && $row['username'] === $username && $row['pwd'] === $password) {
                    throw new RuntimeException('Dieses Benutzername-Passwort-Paar ist bereits gespeichert.');
                }
            }
            if ($id) {
                $db->update('credential', $id, ['username' => $username, 'pwd' => $password]);
                flash('Credential wurde aktualisiert. Alle Gerätezuordnungen bleiben erhalten.');
            } else {
                $db->insert('credential', ['username' => $username, 'pwd' => $password]);
                flash('Credential wurde angelegt und steht für Geräte-Checks zur Verfügung.');
            }
            redirect('credentials.php');
        } elseif ($action === 'delete') {
            $id = positiveId($_POST['id'] ?? null);
            if (!$id || !$db->get('credential', $id)) {
                throw new RuntimeException('Credential nicht gefunden.');
            }
            $db->delete('credential', $id);
            flash('Credential und seine Gerätezuordnungen wurden gelöscht.');
            redirect('credentials.php');
        } elseif ($action === 'assign') {
            $deviceId = positiveId($_POST['device_id'] ?? null);
            if (!$deviceId || !isset($devicesById[$deviceId])) {
                throw new RuntimeException('Gerät nicht gefunden.');
            }
            $postedIds = $_POST['credential_ids'] ?? [];
            if (!is_array($postedIds) || count($postedIds) > 10000) {
                throw new RuntimeException('Ungültige Credential-Auswahl.');
            }
            $validIds = array_fill_keys(array_map(
                static fn(array $row): int => (int) $row['id'],
                $db->all('credential', [], 10000)
            ), true);
            $chosen = [];
            foreach ($postedIds as $value) {
                $credentialId = positiveId($value);
                if (!$credentialId || !isset($validIds[$credentialId])) {
                    throw new RuntimeException('Die Auswahl enthält ein unbekanntes Credential.');
                }
                $chosen[$credentialId] = $credentialId;
            }
            $db->setDeviceCredentials($deviceId, array_values($chosen));
            flash($chosen
                ? 'Credential-Auswahl für das Gerät wurde gespeichert.'
                : 'Gerätezuordnung aufgehoben: Dieses Gerät verwendet wieder alle globalen Credentials.');
            redirect('credentials.php?device=' . $deviceId . '#assignment');
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (PDOException $e) {
        $error = 'Datenbankfehler: Änderung wurde nicht gespeichert.';
    } catch (RuntimeException | InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$credentials = $db->all('credential', [], 10000);
usort($credentials, static fn(array $a, array $b): int => strcmp($a['username'], $b['username']) ?: ((int) $a['id'] <=> (int) $b['id']));
$assignedCount = [];
foreach ($db->all('device_credential', [], 10000) as $row) {
    $id = (int) $row['credential_id'];
    $assignedCount[$id] = ($assignedCount[$id] ?? 0) + 1;
}
$selectedCredentials = [];
if ($selectedDeviceId) {
    foreach ($db->deviceCredentials($selectedDeviceId) as $credential) {
        $selectedCredentials[(int) $credential['id']] = true;
    }
}
$flash = takeFlash();
$showEditor = $editParam === 'new' || $editing !== null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Geräte-Credentials — NetLab Guardian</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-page credentials-page">
    <header class="header">
        <a class="brand" href="index.php">NetLab Guardian</a>
        <span class="admin-page-label">Geräte-Credentials</span>
        <div class="admin-actions">
            <a class="button outline" href="admin.php">Adminbereich</a>
            <a class="button outline" href="index.php">Zur Übersicht</a>
            <form class="logout-form" method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <button class="button outline" type="submit">Abmelden</button>
            </form>
        </div>
    </header>
    <main class="workspace admin-workspace">
        <div class="section-top">
            <div>
                <div class="eyebrow muted">NETZWERKGERÄTE</div>
                <h1>Credentials verwalten</h1>
                <p class="subtle">Zugangsdaten für den Rack-Check und die automatisch erkannten Geräte.</p>
            </div>
            <a class="button light" href="credentials.php?edit=new#editor">Credential hinzufügen</a>
        </div>
        <p class="admin-note">Benutzernamen und Passwörter werden auf dieser Admin-Seite im Klartext angezeigt und in SQLite unter <code>credential</code> unverschlüsselt gespeichert. Website-Logins sind davon unabhängig.</p>
        <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <?php if ($showEditor): ?>
            <section class="panel editor-panel" id="editor">
                <div class="section-top">
                    <h2><?= $editing ? 'Credential bearbeiten' : 'Credential hinzufügen' ?></h2>
                    <a class="text-button" href="credentials.php">Schließen ×</a>
                </div>
                <form class="device-form" method="post" action="credentials.php<?= $editing ? '?edit=' . (int) $editing['id'] : '?edit=new' ?>#editor" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                    <div class="form-row">
                        <label>Benutzername *
                            <input name="username" maxlength="128" required autocomplete="off" value="<?= h($formUsername) ?>">
                        </label>
                        <label>Passwort (Klartext)
                            <input name="password" type="text" maxlength="1024" autocomplete="off" spellcheck="false" value="<?= h($formPassword) ?>">
                        </label>
                    </div>
                    <p class="subtle">Leeres Passwort ist erlaubt. Ändern eines Credentials aktualisiert die Zugangsdaten für alle Geräte, denen es zugeordnet ist.</p>
                    <div class="dialog-actions">
                        <a href="credentials.php" class="button outline">Abbrechen</a>
                        <button class="button light" type="submit">Speichern</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($deleting): ?>
            <section class="panel" id="delete">
                <h2>Credential wirklich löschen?</h2>
                <p>Credential <strong><?= h($deleting['username']) ?> (#<?= (int) $deleting['id'] ?>)</strong> wird dauerhaft gelöscht. Die Zuordnung zu <?= (int) ($assignedCount[$deleteId] ?? 0) ?> Geräten wird ebenfalls entfernt.</p>
                <form method="post" action="credentials.php?delete=<?= $deleteId ?>#delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $deleteId ?>">
                    <div class="dialog-actions">
                        <a class="button outline" href="credentials.php">Abbrechen</a>
                        <button class="button danger" type="submit">Endgültig löschen</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel" aria-label="Gespeicherte Credentials">
            <div class="section-top"><h2>Gespeicherte Zugangsdaten <span class="pill neutral-pill"><?= count($credentials) ?></span></h2></div>
            <div class="table-scroll admin-table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Benutzername</th><th>Passwort (Klartext)</th><th>Explizite Gerätezuordnungen</th><th>Aktionen</th></tr></thead>
                    <tbody>
                        <?php foreach ($credentials as $credential): ?>
                            <tr>
                                <td><?= (int) $credential['id'] ?></td>
                                <td><?= h($credential['username']) ?></td>
                                <td><code class="credential-secret"><?= h($credential['pwd']) ?></code></td>
                                <td><?= (int) ($assignedCount[(int) $credential['id']] ?? 0) ?></td>
                                <td><div class="action-cell">
                                    <a class="text-button" href="credentials.php?edit=<?= (int) $credential['id'] ?>#editor">Bearbeiten</a>
                                    <a class="text-button delete-button" href="credentials.php?delete=<?= (int) $credential['id'] ?>#delete">Löschen</a>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$credentials): ?><tr><td colspan="5">Noch keine Credentials vorhanden. Bitte zuerst Zugangsdaten hinzufügen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="subtle">Die Anzahl rechts zählt nur explizite Zuordnungen. Ohne eine Zuordnung stehen die Credentials automatisch allen Geräten zur Verfügung.</p>
        </section>

        <section class="panel" id="assignment">
            <h2>Credentials einem Gerät zuordnen</h2>
            <p class="subtle">Wähle ein Gerät aus. Sobald mindestens ein Credential angehakt ist, wird für dieses Gerät beim Rack-Check <strong>nur diese Auswahl</strong> verwendet. Ohne Häkchen gelten wieder alle global gespeicherten Credentials. Neu entdeckte Geräte verwenden zunächst ebenfalls alle globalen Credentials.</p>
            <form method="get" action="credentials.php#assignment" class="device-form">
                <label for="selected-device">Gerät
                    <select name="device" id="selected-device" required>
                        <option value="">Gerät auswählen …</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= (int) $device['id'] ?>" <?= $selectedDeviceId === (int) $device['id'] ? 'selected' : '' ?>><?= h(($device['hostname'] ?: 'Gerät ' . $device['id']) . ' · ' . ($device['management_ip'] ?: 'ohne IP') . ' · ' . ($device['rack_position'] ?: 'ohne Rack')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="dialog-actions"><button class="button outline" type="submit">Gerät laden</button></div>
            </form>
            <?php if ($selectedDeviceId): ?>
                <form class="device-form" method="post" action="credentials.php?device=<?= $selectedDeviceId ?>#assignment">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="device_id" value="<?= $selectedDeviceId ?>">
                    <h3><?= h($devicesById[$selectedDeviceId]['hostname'] ?: 'Gerät ' . $selectedDeviceId) ?></h3>
                    <div class="credential-choices">
                        <?php foreach ($credentials as $credential): ?>
                            <label class="credential-choice">
                                <input type="checkbox" name="credential_ids[]" value="<?= (int) $credential['id'] ?>" <?= isset($selectedCredentials[(int) $credential['id']]) ? 'checked' : '' ?>>
                                <span><?= h($credential['username']) ?> <small>#<?= (int) $credential['id'] ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$credentials): ?><p class="subtle">Zuerst mindestens ein Credential hinzufügen.</p><?php endif; ?>
                    <p class="subtle">Aktuell: <?= $selectedCredentials ? count($selectedCredentials) . ' ausgewählte Credentials' : 'alle globalen Credentials (keine gesonderte Zuordnung)' ?>.</p>
                    <div class="dialog-actions"><button type="submit" class="button light">Zuordnung speichern</button></div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
