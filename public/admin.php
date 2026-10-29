<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireAdmin();
$devices = $db->activeDevices();
$error = '';
$editing = null;
$removeDevice = null;
$input = [];
$editId = positiveId($_GET['edit'] ?? null);
$removeId = positiveId($_GET['remove'] ?? null);
if ($editId)
    $editing = $db->get('device', $editId);
if ($removeId)
    $removeDevice = $db->get('device', $removeId);
if (($editId && !$editing) || ($removeId && !$removeDevice)) {
    http_response_code(404);
    exit('Gerät nicht gefunden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = positiveId($_POST['id'] ?? null);
    try {
        if ($action === 'save') {
            $current = $id ? $db->get('device', $id) : null;
            if ($id && (!$current || $current['removed_at'] !== null))
                throw new RuntimeException('Gerät nicht gefunden.');
            $hostname = trim((string) ($_POST['hostname'] ?? ''));
            $type = trim((string) ($_POST['device_type'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? ''));
            $ip = trim((string) ($_POST['management_ip'] ?? ''));
            $rack = positiveId($_POST['rack'] ?? null);
            $unit = positiveId($_POST['unit'] ?? null);
            if ($hostname === '' || strlen($hostname) > 80)
                throw new RuntimeException('Bitte einen Hostnamen mit maximal 80 Zeichen angeben.');
            if ($type === '' || strlen($type) > 80)
                throw new RuntimeException('Bitte einen Gerätetyp angeben.');
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP))
                throw new RuntimeException('Ungültige Management-IP-Adresse.');
            if ($rack < 1 || $rack > 3 || $unit < 1 || $unit > 30)
                throw new RuntimeException('Rack und HE müssen zwischen 1–3 bzw. 1–30 liegen.');
            foreach ($devices as $d) {
                if ((int) $d['id'] === $id)
                    continue;
                if (strtolower((string) $d['hostname']) === strtolower($hostname))
                    throw new RuntimeException('Hostname bereits vergeben.');
                if ($ip !== '' && $d['management_ip'] === $ip)
                    throw new RuntimeException('Management-IP bereits vergeben.');
                if (rackAndUnit($d['rack_position']) === [$rack, $unit])
                    throw new RuntimeException('Die gewählte Höheneinheit ist belegt.');
            }
            $nullable = static fn(string $key): ?string => (($v = trim((string) ($_POST[$key] ?? ''))) === '' ? null : $v);
            $input = [
                'hostname' => $hostname,
                'device_type' => $type,
                'model' => $nullable('model'),
                'serial_number' => $nullable('serial_number'),
                'firmware' => $nullable('firmware'),
                'management_ip' => $ip !== '' ? $ip : null,
                'rack_position' => "Rack {$rack} / HE {$unit}",
                'console_port' => $nullable('console_port'),
                'inventory_number' => $nullable('inventory_number'),
                'notes' => $nullable('notes'),
            ];
            if ($id)
                $db->update('device', $id, $input);
            else
                $id = (int) $db->insert('device', $input);
            flash('Gerät wurde gespeichert.');
            redirect('admin.php');
        } elseif ($action === 'remove') {
            if (!$id || !($current = $db->get('device', $id)) || $current['removed_at'] !== null)
                throw new RuntimeException('Gerät nicht gefunden.');
            $db->update('device', $id, ['removed_at' => gmdate('Y-m-d H:i:s')]);
            flash('Gerät wurde aus dem aktiven Inventar entfernt.');
            redirect('admin.php');
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (PDOException $e) {
        $error = 'Speichern fehlgeschlagen. Bitte Eindeutigkeit von Seriennummer und andere Daten prüfen.';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    if ($action === 'save') {
        if ($id)
            $editing = $db->get('device', $id) ?: [];
        else
            $editing = [];
        $editing = array_merge($editing, $input, $_POST);
        $editId = $id;
    }
    if ($action === 'remove')
        $removeDevice = $db->get('device', $id);
}
$flash = takeFlash();
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adminbereich — NetLab Guardian</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="admin-page">
    <header class="header"><a class="brand" href="index.php">NetLab Guardian</a><span
            class="admin-page-label">Adminbereich</span>
        <div class="admin-actions"><a class="button outline" href="index.php">Zur Übersicht</a>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf"
                    value="<?= h(csrf()) ?>"><button class="button outline" type="submit">Abmelden</button></form>
        </div>
    </header>
    <main class="workspace admin-workspace">
        <div class="section-top">
            <div>
                <div class="eyebrow muted">GERÄTEVERWALTUNG</div>
                <h1>Adminbereich</h1>
                <p class="subtle">Geräte hinzufügen, bearbeiten und aus dem aktiven Inventar entfernen.</p>
            </div><a class="button light" href="admin.php?edit=new#editor">Gerät hinzufügen</a>
        </div>
        <p class="admin-note">Angemeldet als <?= h($_SESSION['user']) ?>. Änderungen werden serverseitig in SQLite
            gespeichert.</p>
        <?php if ($flash): ?>
            <p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?>
            <p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <div class="admin-summary"><?php foreach ([3, 2, 1] as $rack): ?>
                <div><span>Rack <?= $rack ?> —
                        <?= h(rackLabel($rack)) ?></span><strong><?= count(array_filter($devices, static fn($d) => rackAndUnit($d['rack_position'])[0] === $rack)) ?>
                        / 30</strong></div><?php endforeach; ?>
        </div>
        <?php if ($editing !== null || (isset($_GET['edit']) && $_GET['edit'] === 'new')): ?>
            <?php
            $device = $editing ?? [];
            [$oldRack, $oldUnit] = rackAndUnit($device['rack_position'] ?? null);
            $rack = positiveId($device['rack'] ?? $oldRack ?? 1);
            $unit = positiveId($device['unit'] ?? $oldUnit ?? 1);
            ?>
            <section class="panel editor-panel" id="editor">
                <div class="section-top">
                    <h2><?= $editId ? 'Gerät bearbeiten' : 'Gerät hinzufügen' ?></h2><a class="text-button"
                        href="admin.php">Schließen ×</a>
                </div>
                <form class="device-form" method="post"
                    action="admin.php<?= $editId ? '?edit=' . $editId : '?edit=new' ?>#editor"><input type="hidden"
                        name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="save"><input
                        type="hidden" name="id" value="<?= (int) $editId ?>">
                    <div class="form-row"><label>Hostname *<input name="hostname" required maxlength="80"
                                value="<?= h($device['hostname'] ?? '') ?>"></label><label>Gerätetyp *<select
                                name="device_type"
                                required><?php foreach (array_unique(['Switch', 'Router', 'Firewall', 'Access Point', (string) ($device['device_type'] ?? '')]) as $type):
                                    if ($type === '')
                                        continue; ?>
                                    <option value="<?= h($type) ?>" <?= ($device['device_type'] ?? 'Switch') === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?>
                            </select></label></div>
                    <div class="form-row"><label>Modell<input name="model" maxlength="120"
                                value="<?= h($device['model'] ?? '') ?>"></label><label>Management-IP<input
                                name="management_ip" maxlength="45"
                                value="<?= h($device['management_ip'] ?? '') ?>"></label></div>
                    <div class="form-row"><label>Rack *<select name="rack" required><?php foreach ([1, 2, 3] as $r): ?>
                                    <option value="<?= $r ?>" <?= $rack === $r ? 'selected' : '' ?>>Rack <?= $r ?> ·
                                        <?= h(rackLabel($r)) ?></option><?php endforeach; ?>
                            </select></label>
                        <label>Höheneinheit *<select name="unit" required><?php for ($u = 1; $u <= 30; ++$u): ?>
                                    <option value="<?= $u ?>" <?= $unit === $u ? 'selected' : '' ?>>HE <?= $u ?></option>
                                <?php endfor; ?>
                            </select></label>
                    </div>
                    <div class="form-row"><label>Seriennummer<input name="serial_number" maxlength="120"
                                value="<?= h($device['serial_number'] ?? '') ?>"></label><label>Firmware<input
                                name="firmware" maxlength="120" value="<?= h($device['firmware'] ?? '') ?>"></label></div>
                    <div class="form-row"><label>Konsolenport<input name="console_port" maxlength="120"
                                value="<?= h($device['console_port'] ?? '') ?>"></label><label>Inventarnummer<input
                                name="inventory_number" maxlength="120"
                                value="<?= h($device['inventory_number'] ?? '') ?>"></label></div>
                    <label>Notizen<textarea name="notes" maxlength="4000"
                            rows="4"><?= h($device['notes'] ?? '') ?></textarea></label>
                    <p class="subtle">* Pflichtfeld. Die Rack-Belegung wird beim Speichern geprüft.</p>
                    <div class="dialog-actions"><a href="admin.php" class="button outline">Abbrechen</a><button
                            type="submit" class="button light">Speichern</button></div>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($removeDevice): ?>
            <section class="panel" id="remove">
                <h2>Gerät entfernen?</h2>
                <p><?= h($removeDevice['hostname'] ?: 'Gerät ' . $removeDevice['id']) ?> —
                    <?= h($removeDevice['rack_position']) ?></p>
                <p class="subtle">Das Gerät wird als entfernt markiert. Störungen bleiben in der Datenbank erhalten.</p>
                <form method="post" action="admin.php?remove=<?= (int) $removeDevice['id'] ?>#remove"><input type="hidden"
                        name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="remove"><input
                        type="hidden" name="id" value="<?= (int) $removeDevice['id'] ?>">
                    <div class="dialog-actions"><a href="admin.php" class="button outline">Abbrechen</a><button
                            type="submit" class="button danger">Gerät entfernen</button></div>
                </form>
            </section><?php endif; ?>
        <div class="table-scroll admin-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Gerät</th>
                        <th>Typ / Modell</th>
                        <th>IP-Adresse</th>
                        <th>Rackposition</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><?= h($device['hostname'] ?: 'Gerät ' . $device['id']) ?></td>
                            <td><?= h($device['model'] ?: $device['device_type']) ?></td>
                            <td><?= h($device['management_ip'] ?: '—') ?></td>
                            <td><?= h($device['rack_position'] ?: '—') ?></td>
                            <td><a class="text-button"
                                    href="admin.php?edit=<?= (int) $device['id'] ?>#editor">Bearbeiten</a><a
                                    class="text-button delete-button"
                                    href="admin.php?remove=<?= (int) $device['id'] ?>#remove">Entfernen</a></td>
                        </tr><?php endforeach; ?>
                    <?php if (!$devices): ?>
                        <tr>
                            <td colspan="5">Noch keine Geräte vorhanden. Über „Gerät hinzufügen“ kannst du beginnen.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>

</html>