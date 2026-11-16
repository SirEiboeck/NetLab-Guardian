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
                                        <button type="submit" name="action" value="check" class="rack-run-button" title="Nur vorhandene, nicht ausgeschlossene Rack-Geräte prüfen">Prüfen</button>
                                        <button type="submit" name="action" value="scan" class="rack-run-button rack-run-scan" title="IP-Bereiche scannen, neue Geräte registrieren und alle zulässigen Ziele prüfen">IP-Scan</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (isAdmin()): ?>
                            <details class="rack-settings">
                                        <summary aria-label="Erweiterte Einstellungen · Rack <?= $rack ?>" title="Erweiterte Einstellungen · Rack <?= $rack ?>">⚙</summary>
                                        <div class="rack-settings-panel">
                                            <form method="post" action="index.php#rack-<?= $rack ?>">
                                                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                                <input type="hidden" name="rack" value="<?= $rack ?>">
                                                <input type="hidden" name="mode" value="custom">
                                                <label for="rack-json-<?= $rack ?>">JSON-Konfiguration für Rack <?= $rack ?></label>
                                                <p>Diese Einstellungen gelten nur für den aktuellen Prüflauf.</p>
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
                                <form class="rack-dismiss-form" method="post" action="index.php#rack-<?= $rack ?>">
                                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                    <input type="hidden" name="rack" value="<?= $rack ?>">
                                    <input type="hidden" name="mode" value="dismiss_notice">
                                    <button type="submit" class="notice-close" aria-label="Meldung schließen" title="Schließen">×</button>
                                </form>
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
                            <?php for ($unit = 1; $unit <= $rackDisplayUnits; ++$unit): ?>
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
                                            <a href="<?= 'device.php?id=' . (int) $unpositioned['id'] ?>"><?= h($unpositioned['hostname'] ?: $unpositioned['management_ip']) ?></a>
                                            <?php rackLockControl($unpositioned, $rack); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                            </div>
                        <?php endif; ?>
                        <div class="rack-footer"><span><b><?= count($rackDevices) ?> Geräte</b> · <?= $switches ?> Switches · <?= $routers ?> Router</span><span><?= count($rackSlots) ?> belegt · <?= $rackDisplayUnits ?> HE angezeigt</span></div>
                    </article>
                