<?php
declare(strict_types=1);

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

$formUsername = (string) ($editing['username'] ?? '');
$formPassword = ''; // Passwort nicht an die Oberfläche zurückgeben.
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
            // Leeres Passwort beim Bearbeiten: bisheriges Passwort unverändert lassen.
            $password = $_POST['password'] === '' && $id ? (string) $db->get('credential', $id)['pwd'] : $_POST['password'];
            $formUsername = $username;
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
                flash('Zugangsdaten aktualisiert.');
            } else {
                $db->insert('credential', ['username' => $username, 'pwd' => $password]);
                flash('Zugangsdaten gespeichert.');
            }
            redirect('credentials.php');
        } elseif ($action === 'delete') {
            $id = positiveId($_POST['id'] ?? null);
            if (!$id || !$db->get('credential', $id)) {
                throw new RuntimeException('Credential nicht gefunden.');
            }
            $db->delete('credential', $id);
            flash('Zugangsdaten gelöscht.');
            redirect('credentials.php');
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
$flash = takeFlash();
$showEditor = $editParam === 'new' || $editing !== null;
