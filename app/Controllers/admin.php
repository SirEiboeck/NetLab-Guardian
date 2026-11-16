<?php
declare(strict_types=1);

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
