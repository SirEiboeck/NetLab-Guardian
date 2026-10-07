<?php

$result = null;
$error = null;
$inputJson = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $host = $_POST['host'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $deviceType = $_POST['device_type'];
    $port = $_POST['port'];
    $commands = array_filter(
        array_map('trim', explode("\n", $_POST['commands']))
    );

    $data = [
        'credentials' => [
            [
                'username' => $username,
                'password' => $password
            ]
        ],

        'discover' => [
            'ranges' => [],
            'exclude' => []
        ],

        'defaults' => [
            'port' => $port,
            'device_type' => $deviceType,
            'commands' => $commands
        ],

        'devices' => [
            $host => (object) []
        ]
    ];

    $inputJson = json_encode($data, JSON_PRETTY_PRINT);

    $python = __DIR__ . '/python/.venv/bin/python';
    $script = __DIR__ . '/python/main.py';

    $process = proc_open(
        escapeshellarg($python) . ' ' . escapeshellarg($script),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes,
        __DIR__ . '/python'
    );

    if (is_resource($process)) {

        fwrite($pipes[0], $inputJson);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            $result = $output;
        } else {
            $error = $stderr;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Network Device Test</title>
</head>

<body>

<h2>Network Device Test</h2>

<form method="post">

    <p>
        <label>IP-Adresse</label><br>
        <input
            type="text"
            name="host"
            value="<?= htmlspecialchars($_POST['host'] ?? '') ?>"
        >
    </p>

    <p>
        <label>Username</label><br>
        <input
            type="text"
            name="username"
            value="<?= htmlspecialchars($_POST['username'] ?? 'cisco') ?>"
        >
    </p>

    <p>
        <label>Password</label><br>
        <input
            type="password"
            name="password"
            value="<?= htmlspecialchars($_POST['password'] ?? 'cisco') ?>"
        >
    </p>

    <p>
        <label>Device Type</label><br>
        <input
            type="text"
            name="device_type"
            value="<?= htmlspecialchars($_POST['device_type'] ?? 'cisco_ios_telnet') ?>"
        >
    </p>

    <p>
        <label>Port</label><br>
        <input
            type="number"
            name="port"
            value="<?= htmlspecialchars($_POST['port'] ?? '23') ?>"
        >
    </p>

    <p>
        <label>Commands (einer pro Zeile)</label><br>

        <textarea name="commands" rows="8" cols="60"><?= htmlspecialchars(
            $_POST['commands'] ?? "show version\nshow ip interface brief"
        ) ?></textarea>
    </p>

    <button type="submit">Test starten</button>

</form>


<?php if ($inputJson): ?>

    <h3>An Python gesendet</h3>

    <pre><?= htmlspecialchars($inputJson) ?></pre>

<?php endif; ?>


<?php if ($result): ?>

    <h3>Python Output</h3>

    <pre><?= htmlspecialchars(
        json_encode(
            json_decode($result, true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    ) ?></pre>

<?php endif; ?>


<?php if ($error): ?>

    <h3>Python Fehler</h3>

    <pre><?= htmlspecialchars($error) ?></pre>

<?php endif; ?>

</body>
</html>