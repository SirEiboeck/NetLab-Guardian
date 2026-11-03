<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();

// Die bestehende DBInternalAPI bietet für site_user nur Login und Upsert,
// aber keine Auflistung oder Löschfunktion. Daher erfolgt die Verwaltung hier
// direkt über die vorhandene SQLite-Datei, ohne andere Projektdateien zu ändern.
$usersDb = new PDO('sqlite:' . dirname(__DIR__) . '/storage/inventory.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
]);
$usersDb->exec('PRAGMA foreign_keys = ON');

// Auch eine bereits bestehende Sitzung darf nach Löschung/Herabstufung des
// eigenen Kontos nicht mehr auf die Benutzerverwaltung zugreifen.
$currentAdmin = $db->siteUser((string) $_SESSION['user']);
if (!$currentAdmin || $currentAdmin['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff nur für aktive Administratoren. Bitte neu anmelden.');
}

$error = '';
$editName = (string) ($_GET['edit'] ?? '');
$deleteName = (string) ($_GET['delete'] ?? '');
$editing = null;
$deleting = null;

if ($editName !== '' && $editName !== 'new') {
    $stmt = $usersDb->prepare('SELECT username, role FROM site_user WHERE username = ?');
    $stmt->execute([$editName]);
    $editing = $stmt->fetch() ?: null;
    if ($editing === null) {
        http_response_code(404);
        exit('Benutzer nicht gefunden.');
    }
}
if ($deleteName !== '') {
    $stmt = $usersDb->prepare('SELECT username, role FROM site_user WHERE username = ?');
    $stmt->execute([$deleteName]);
    $deleting = $stmt->fetch() ?: null;
    if ($deleting === null) {
        http_response_code(404);
        exit('Benutzer nicht gefunden.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $originalName = (string) ($_POST['original_username'] ?? '');
            $username = trim((string) ($_POST['username'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D', $username)) {
                throw new RuntimeException('Benutzername: 3–40 Zeichen, nur Buchstaben, Zahlen, _, - und .');
            }
            if (!in_array($role, ['admin', 'viewer'], true)) {
                throw new RuntimeException('Ungültige Benutzerrolle.');
            }
            if (($originalName === '' || $password !== '') && (strlen($password) < 8 || strlen($password) > 1024)) {
                throw new RuntimeException('Das Passwort muss 8 bis 1024 Zeichen haben.');
            }
            if (($originalName === '' && $editName !== 'new') ||
                ($originalName !== '' && ($editName !== $originalName || $username !== $originalName))) {
                throw new RuntimeException('Ungültige Bearbeitungsanfrage.');
            }

            // Die Prüfung und die Änderung erfolgen in einer Transaktion.
            $usersDb->beginTransaction();
            if ($originalName === '') {
                $stmt = $usersDb->prepare('SELECT 1 FROM site_user WHERE username = ?');
                $stmt->execute([$username]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
                }
                $stmt = $usersDb->prepare('INSERT INTO site_user (username, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
                $message = 'Benutzer wurde angelegt.';
            } else {
                $stmt = $usersDb->prepare('SELECT role FROM site_user WHERE username = ?');
                $stmt->execute([$originalName]);
                $oldRole = $stmt->fetchColumn();
                if ($oldRole === false) {
                    throw new RuntimeException('Benutzer nicht gefunden.');
                }
                if ($originalName === (string) $_SESSION['user'] && $role !== 'admin') {
                    throw new RuntimeException('Die eigene Administratorrolle kann nicht entfernt werden.');
                }
                if ($oldRole === 'admin' && $role !== 'admin') {
                    $adminCount = (int) $usersDb->query("SELECT COUNT(*) FROM site_user WHERE role = 'admin'")->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new RuntimeException('Der letzte Administrator muss erhalten bleiben.');
                    }
                }
                if ($password !== '') {
                    $stmt = $usersDb->prepare('UPDATE site_user SET role = ?, password_hash = ? WHERE username = ?');
                    $stmt->execute([$role, password_hash($password, PASSWORD_DEFAULT), $originalName]);
                } else {
                    $stmt = $usersDb->prepare('UPDATE site_user SET role = ? WHERE username = ?');
                    $stmt->execute([$role, $originalName]);
                }
                $message = 'Benutzer wurde aktualisiert.';
            }
            $usersDb->commit();
            flash($message);
            redirect('site_users.php');
        } elseif ($action === 'delete') {
            $username = (string) ($_POST['username'] ?? '');
            if ($username === '' || $username !== $deleteName) {
                throw new RuntimeException('Ungültige Löschanfrage.');
            }
            if ($username === (string) $_SESSION['user']) {
                throw new RuntimeException('Das eigene Konto kann nicht gelöscht werden.');
            }
            $usersDb->beginTransaction();
            $stmt = $usersDb->prepare('SELECT role FROM site_user WHERE username = ?');
            $stmt->execute([$username]);
            $oldRole = $stmt->fetchColumn();
            if ($oldRole === false) {
                throw new RuntimeException('Benutzer nicht gefunden.');
            }
            if ($oldRole === 'admin') {
                $adminCount = (int) $usersDb->query("SELECT COUNT(*) FROM site_user WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    throw new RuntimeException('Der letzte Administrator darf nicht gelöscht werden.');
                }
            }
            $stmt = $usersDb->prepare('DELETE FROM site_user WHERE username = ?');
            $stmt->execute([$username]);
            $usersDb->commit();
            flash('Benutzer wurde gelöscht.');
            redirect('site_users.php');
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (PDOException $e) {
        if ($usersDb->inTransaction()) {
            $usersDb->rollBack();
        }
        $error = 'Datenbankfehler. Die Änderung wurde nicht gespeichert.';
    } catch (RuntimeException $e) {
        if ($usersDb->inTransaction()) {
            $usersDb->rollBack();
        }
        $error = $e->getMessage();
    }
}

$users = $usersDb->query('SELECT username, role FROM site_user ORDER BY username COLLATE NOCASE')->fetchAll();
$flash = takeFlash();
$showEditor = $editName === 'new' || $editing !== null;
$formName = $showEditor && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save'
    ? trim((string) ($_POST['username'] ?? ''))
    : (string) ($editing['username'] ?? '');
$formRole = $showEditor && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save'
    ? (string) ($_POST['role'] ?? 'viewer')
    : (string) ($editing['role'] ?? 'viewer');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzerverwaltung — NetLab Guardian</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-page">
    <header class="header">
        <a class="brand" href="index.php">NetLab Guardian</a>
        <span class="admin-page-label">Benutzerverwaltung</span>
        <div class="admin-actions">
            <a class="button outline" href="admin.php">Zum Adminbereich</a>
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
                <div class="eyebrow muted">ADMINISTRATOREN</div>
                <h1>Website-Benutzer</h1>
                <p class="subtle">Konten anlegen, Rollen vergeben, Passwörter zurücksetzen und Benutzer löschen.</p>
            </div>
            <a class="button light" href="site_users.php?edit=new#editor">Benutzer hinzufügen</a>
        </div>
        <p class="admin-note">Angemeldet als <?= h($_SESSION['user']) ?>. Nur Administratoren haben Zugriff.</p>
        <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <?php if ($showEditor): ?>
            <section class="panel editor-panel" id="editor">
                <div class="section-top">
                    <h2><?= $editing ? 'Benutzer bearbeiten' : 'Benutzer hinzufügen' ?></h2>
                    <a class="text-button" href="site_users.php">Schließen ×</a>
                </div>
                <form class="device-form" method="post" action="site_users.php?edit=<?= rawurlencode($editName) ?>#editor">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="original_username" value="<?= h($editing['username'] ?? '') ?>">
                    <div class="form-row">
                        <label>Benutzername *
                            <input name="username" required minlength="3" maxlength="40"
                                pattern="[a-zA-Z0-9_.-]{3,40}" autocomplete="off"
                                <?= $editing ? 'readonly' : '' ?> value="<?= h($formName) ?>">
                        </label>
                        <label>Rolle *
                            <select name="role" required>
                                <option value="viewer" <?= $formRole === 'viewer' ? 'selected' : '' ?>>Viewer (nur lesen)</option>
                                <option value="admin" <?= $formRole === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            </select>
                        </label>
                    </div>
                    <label><?= $editing ? 'Neues Passwort (leer lassen = unverändert)' : 'Passwort *' ?>
                        <input name="password" type="password" autocomplete="new-password"
                            minlength="8" maxlength="1024" <?= $editing ? '' : 'required' ?>>
                    </label>
                    <p class="subtle">Mindestens 8 Zeichen. Passwörter werden nur als sicherer Hash gespeichert.
                        <?= $editing ? 'Der Benutzername ist nach dem Anlegen nicht änderbar.' : '' ?></p>
                    <div class="dialog-actions">
                        <a href="site_users.php" class="button outline">Abbrechen</a>
                        <button class="button light" type="submit">Speichern</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($deleting): ?>
            <section class="panel" id="delete">
                <h2>Benutzer wirklich löschen?</h2>
                <p>Benutzer <strong><?= h($deleting['username']) ?></strong> (<?= h($deleting['role']) ?>) wird dauerhaft entfernt.</p>
                <form method="post" action="site_users.php?delete=<?= rawurlencode($deleting['username']) ?>#delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="username" value="<?= h($deleting['username']) ?>">
                    <div class="dialog-actions">
                        <a href="site_users.php" class="button outline">Abbrechen</a>
                        <button type="submit" class="button danger">Endgültig löschen</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <div class="table-scroll admin-table-wrap">
            <table>
                <thead><tr><th>Benutzername</th><th>Rolle</th><th>Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= h($user['username']) ?><?= $user['username'] === $_SESSION['user'] ? ' (Du)' : '' ?></td>
                        <td><?= $user['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></td>
                        <td>
                            <a class="text-button" href="site_users.php?edit=<?= rawurlencode($user['username']) ?>#editor">Bearbeiten</a>
                            <?php if ($user['username'] !== $_SESSION['user']): ?>
                                <a class="text-button delete-button" href="site_users.php?delete=<?= rawurlencode($user['username']) ?>#delete">Löschen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="3">Keine Benutzer vorhanden.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
