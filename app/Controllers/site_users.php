<?php
declare(strict_types=1);

requireAdmin();

// Die bestehende DBInternalAPI bietet für site_user nur Login und Upsert,
// aber keine Auflistung oder Löschfunktion. Daher erfolgt die Verwaltung hier
// direkt über die vorhandene SQLite-Datei, ohne andere Projektdateien zu ändern.
$usersDb = new PDO('sqlite:' . ProjectPaths::database(), null, null, [
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
