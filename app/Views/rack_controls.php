<?php
declare(strict_types=1);

function rackLockIcon(bool $excluded): string
{
    $shackle = $excluded
        ? '<path d="M8 11V7a4 4 0 0 1 8 0v4"/>'
        : '<path d="M8 11V7a4 4 0 0 1 7.5-2"/>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/>' . $shackle . '</svg>';
}

/** Separates POST-Formular, damit ein Klick auf das Schloss keine Geräteseite öffnet. */
function rackLockControl(array $device, int $rack): void
{
    $excluded = (int) ($device['check_excluded'] ?? 0) === 1;
    if (!isAdmin()) {
        if ($excluded) {
            echo '<span class="device-lock-readonly" title="Von Prüfung und IP-Scan ausgeschlossen">' . rackLockIcon(true) . '</span>';
        }
        return;
    }
    $name = (string) ($device['hostname'] ?: 'Gerät ' . $device['id']);
    $nextExcluded = $excluded ? 0 : 1;
    $label = $excluded
        ? $name . ': Ausschluss aufheben (wieder prüfen und scannen)'
        : $name . ': von Prüfung und IP-Scan ausschließen';
    ?>
    <form class="device-lock-form" method="post" action="index.php#rack-<?= $rack ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="rack" value="<?= $rack ?>">
        <input type="hidden" name="mode" value="toggle_exclusion">
        <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
        <input type="hidden" name="exclude" value="<?= $nextExcluded ?>">
        <button class="device-lock-button <?= $excluded ? 'is-locked' : '' ?>" type="submit"
            title="<?= h($label) ?>" aria-label="<?= h($label) ?>"
            aria-pressed="<?= $excluded ? 'true' : 'false' ?>"><?= rackLockIcon($excluded) ?></button>
    </form>
    <?php
}

