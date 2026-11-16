                    <?php
                    $rackDevices = array_values(array_filter($devices, static fn(array $d): bool => $d['rack'] === $rack));
                    $rackStatuses = array_column($rackDevices, 'status');
                    $rackStatus = in_array('critical', $rackStatuses, true) ? 'critical' : (in_array('warning', $rackStatuses, true) ? 'warning' : (in_array('unknown', $rackStatuses, true) ? 'unknown' : 'checked'));
                    $rackSlots = [];
                    $rackUnpositioned = [];
                    foreach ($rackDevices as $d) {
                        if ($d['unit'] === null) {
                            $rackUnpositioned[] = $d;
                        } else {
                            $rackSlots[$d['unit']] = $d;
                        }
                    }
                    $switches = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'switch'));
                    $routers = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'router'));
                    $editorJson = isAdmin()
                        ? json_encode(rackEditableConfig($rack, $rackDevices, $checkCommands, $checkCredentialRows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                        : ''; 
                    ?>
                    <article class="rack-card" id="rack-<?= $rack ?>">
                        <div class="rack-heading">
                            <div class="rack-heading-title">
                                <h3>Rack <?= $rack ?> — <?= h(rackLabel($rack)) ?></h3>
                                <span class="pill <?= pillClass($rackStatus) ?>"><?= h(statusLabel($rackStatus)) ?></span>
                            </div>
                            <?php if (isAdmin()): ?>
                                <div class="rack-actions">
                                    <form method="post" action="index.php#rack-<?= $rack ?>">
                                        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                        <input type="hidden" name="rack" value="<?= $rack ?>">
                                        <input type="hidden" name="mode" value="default">
                                        <button type="submit" name="action" value="check" class="rack-run-button" title="Nur vorhandene, nicht ausgeschlossene Rack-Geräte prüfen">▶ Prüfen</button>
                                        <button type="submit" name="action" value="scan" class="rack-run-button rack-run-scan" title="IP-Bereiche scannen, neue Geräte registrieren und alle zulässigen Ziele prüfen">⌕ IP-Scan + Prüfen</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (isAdmin()): ?>
                            <details class="rack-settings">
                                        <summary aria-label="JSON-Einstellungen für Rack <?= $rack ?>" title="JSON-Einstellungen für Rack <?= $rack ?>">⚙</summary>
                                        <div class="rack-settings-panel">
                                            <form method="post" action="index.php#rack-<?= $rack ?>">
                                                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                                <input type="hidden" name="rack" value="<?= $rack ?>">
                                                <input type="hidden" name="mode" value="custom">
                                                <label for="rack-json-<?= $rack ?>">JSON-Konfiguration für Rack <?= $rack ?></label>
                                                <p>„Nur Geräte prüfen“ verwendet ausschließlich die vorhandenen Rack-Geräte aus <code>devices</code> – ohne Netzwerk-Scan. „IP-Scan + Prüfen“ verwendet zusätzlich <code>discover.ranges</code> und <code>discover.exclude</code> (nur für unbekannte Scan-Ziele). Dauerhaft ausgeschlossene Geräte werden bei beiden Aktionen immer übersprungen, auch wenn sie explizit unter <code>devices</code> stehen. Das Rack-Netz 10.78.<?= $rack ?>.1–254 ist voreingestellt. Unter <code>devices</code> bleiben vorhandene Geräte separat konfigurierbar. Unbelegte IPs erzeugen keine Fehler. Erreichbare, vom Python-Skript erkannte neue Geräte werden im Rack registriert, vorläufig einer freien HE zugewiesen und können anschließend im Adminbereich bearbeitet werden. Falls das Rack voll ist, werden sie ohne HE registriert. Bereits vorhandene Geräte bleiben in ihrem Rack; gemeldete Fehler werden gespeichert. Die Passwörter bleiben mit <code>[aus Datenbank]</code> verborgen und werden beim Start serverseitig ergänzt. Du kannst Username oder Passwort im Editor nur für diesen einen Prüflauf ändern oder neue Einträge ohne id hinzufügen. Die JSON-Einstellungen werden nicht gespeichert; Inventar und Prüfergebnisse werden in SQLite gespeichert. Gespeicherte Zugangsdaten lassen sich unter <a href="credentials.php">Credentials verwalten</a> bearbeiten und einzelnen Geräten zuordnen. Ohne Gerätezuordnung werden alle globalen Credentials verwendet; bei einer Zuordnung nur diese. Der unter defaults.port bzw. devices[IP].port eingestellte Port wird für den Gerätezugriff verwendet.</p>
                                                <textarea id="rack-json-<?= $rack ?>" name="settings_json" rows="13" maxlength="64000" spellcheck="false" required><?= h($editorJson) ?></textarea>
                                                <div class="rack-settings-buttons">
                                                    <button type="reset" class="rack-reset-button">Zurücksetzen</button>
                                                    <button type="submit" name="action" value="check" class="rack-run-button rack-run-custom">Nur Geräte prüfen</button>
                                                    <button type="submit" name="action" value="scan" class="rack-run-button rack-run-scan rack-run-custom">IP-Scan + Prüfen</button>
                                                </div>
                                            </form>
                                        </div>
                            </details>
                        <?php endif; ?>
                        <?php if (is_array($checkNotice) && (int) $checkNotice['rack'] === $rack): ?>
                            <div class="rack-check-notice notice <?= $checkNotice['success'] ? 'success' : 'error' ?>" role="status">
                                <strong><?= h($checkNotice['message']) ?></strong>
                                <?php if ($checkNotice['failures']): ?>
                                    <ul class="rack-check-errors">
                                        <?php foreach ($checkNotice['failures'] as $failure): ?>
                                            <li><?= h($failure) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($checkNotice['registered'])): ?>
                                    <p>Neu im Rack registriert (<?= (int) $checkNotice['registered_count'] ?>):
                                        <?php foreach ($checkNotice['registered'] as $newDevice): ?>
                                            <a href="device.php?id=<?= (int) $newDevice['id'] ?>"><?= h($newDevice['ip']) ?></a>
                                            (<?= h($newDevice['position']) ?>)
                                        <?php endforeach; ?>
                                        <?= $checkNotice['registered_count'] > count($checkNotice['registered']) ? ' …' : '' ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="rack-frame">
                            <?php for ($unit = 1; $unit <= 30; ++$unit): ?>
                                <?php if (!isset($rackSlots[$unit])): ?>
                                    <div class="empty-unit"><span
                                            class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span><span>Frei</span>
                                    </div>
                                <?php else:
                                    $d = $rackSlots[$unit];
                                    $isRouter = strtolower($d['device_type']) === 'router';
                                    $isExcluded = (int) ($d['check_excluded'] ?? 0) === 1; ?>
                                    <div class="device <?= h($d['status']) ?> <?= $isRouter ? 'router' : '' ?> <?= trim((string) $d['notes']) !== '' ? 'has-note' : '' ?> <?= $isExcluded ? 'check-excluded' : '' ?>">
                                        <a class="device-row-link" href="device.php?id=<?= (int) $d['id'] ?>"
                                            aria-label="<?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?>, <?= h(statusLabel($d['status'])) ?>, Details öffnen"></a>
                                        <span class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span>
                                        <span class="device-name"><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?></span>
                                        <?php rackLockControl($d, $rack); ?>
                                        <?php if (trim((string) $d['notes']) !== ''): ?>
                                            <a class="note-marker" href="device.php?id=<?= (int) $d['id'] ?>" title="Notiz vorhanden">N</a>
                                        <?php endif; ?>
                                        <span class="ports" aria-hidden="true"><?php for ($p = 0; $p < ($isRouter ? 4 : 16); ++$p): ?><i class="port"></i><?php endfor; ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <?php if ($rackUnpositioned): ?>
                            <div class="rack-footer">
                                <div class="unpositioned-devices"><b>Ohne HE zugeordnet:</b>
                                    <?php foreach ($rackUnpositioned as $unpositioned): ?>
                                        <div class="unpositioned-device">
                                            <a href="<?= isAdmin() ? 'admin.php?edit=' . (int) $unpositioned['id'] . '#editor' : 'device.php?id=' . (int) $unpositioned['id'] ?>"><?= h($unpositioned['hostname'] ?: $unpositioned['management_ip']) ?></a>
                                            <?php rackLockControl($unpositioned, $rack); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <span>HE im Adminbereich zuweisen</span>
                            </div>
                        <?php endif; ?>
                        <div class="rack-footer"><span><b><?= count($rackDevices) ?> Geräte</b> / <?= $switches ?> Switches
                                / <?= $routers ?> Router</span><span><?= count($rackSlots) ?> / 30 HE</span></div>
                    </article>
                