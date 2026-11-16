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
