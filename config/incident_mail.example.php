<?php
declare(strict_types=1);

// Diese Vorlage nach config/incident_mail.php kopieren (passiert automatisch bei install.sh).
// Das Passwort gehört NICHT hierher. Mit sudo bash install.sh --set-mail-password speichern.
return [
    'timezone' => 'Europe/Vienna',
    'send_time' => '07:00',       // täglich ab 07:00 Uhr

    // SMTP-Daten des Mailanbieters. Microsoft 365 erlaubt Passwort-SMTP nur,
    // wenn die Schul-/Firmen-IT SMTP AUTH für dieses Postfach freigegeben hat.
    'smtp_host' => 'smtp.office365.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'starttls', // 587: starttls, 465: ssl

    'sender' => 'netlab@example.com', // gleichzeitig SMTP-Benutzername
    // 'smtp_username' => 'anderer-login@example.com', // optional
    'recipients' => [
        'admin@example.com',
        // 'zweite-person@example.com',
    ],
];
