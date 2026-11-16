<?php
/**
 * Einzelnes, serverseitig gerendertes Donutdiagramm.
 * Erwartet: $chartId, $chartTitle, $chartTotal, $chartCenter, $chartCenterLabel,
 *           $chartSegments = [['label' => ..., 'count' => ..., 'tone' => ...], ...].
 * Kein Chart-Paket und kein Inline-JavaScript erforderlich.
 */
$chartTotal = max(0, (int) $chartTotal);
$chartCenter = max(0, (int) $chartCenter);
$circumference = 2 * pi() * 60;
$offset = 0.0;
$formatPercent = static fn(int $count, int $total): string => number_format($total > 0 ? $count * 100 / $total : 0, 1, ',', '.');
?>
<figure class="donut-card" aria-labelledby="<?= h($chartId) ?>-heading">
    <figcaption id="<?= h($chartId) ?>-heading"><?= h($chartTitle) ?></figcaption>
    <div class="donut-stage">
        <svg class="donut-svg" viewBox="0 0 180 180" role="group" aria-label="<?= h($chartTitle) ?>: <?= $chartTotal ?> insgesamt">
            <circle class="donut-track" cx="90" cy="90" r="60" fill="none" stroke-width="20" />
            <?php foreach ($chartSegments as $segment):
                $count = max(0, (int) $segment['count']);
                if ($chartTotal === 0 || $count === 0) continue;
                $length = $count / $chartTotal * $circumference;
                $percent = $formatPercent($count, $chartTotal);
                $label = (string) $segment['label'];
                $description = "$label: $count (" . $percent . ' %)';
            ?>
                <g class="donut-segment donut-<?= h($segment['tone']) ?>" tabindex="0"
                   aria-label="<?= h($description) ?>" data-donut-label="<?= h($label) ?>"
                   data-donut-value="<?= $count ?>" data-donut-percent="<?= h($percent) ?>">
                    <title><?= h($description) ?></title>
                    <circle cx="90" cy="90" r="60" fill="none" stroke-width="20"
                            stroke-dasharray="<?= number_format($length, 3, '.', '') ?> <?= number_format($circumference, 3, '.', '') ?>"
                            stroke-dashoffset="<?= number_format(-$offset, 3, '.', '') ?>" transform="rotate(-90 90 90)" />
                </g>
            <?php $offset += $length; endforeach; ?>
            <text class="donut-center-value" x="90" y="92" text-anchor="middle"><?= $chartCenter ?></text>
            <text class="donut-center-label" x="90" y="111" text-anchor="middle"><?= h($chartCenterLabel) ?></text>
        </svg>
        <div class="donut-tooltip" aria-live="polite" data-donut-tooltip>Details beim Hover</div>
    </div>
    <ul class="donut-legend" aria-label="<?= h($chartTitle) ?> – Werte">
        <?php foreach ($chartSegments as $segment):
            $count = max(0, (int) $segment['count']);
            $percent = $formatPercent($count, $chartTotal);
        ?>
            <li class="donut-legend-row" tabindex="0" data-donut-label="<?= h($segment['label']) ?>"
                data-donut-value="<?= $count ?>" data-donut-percent="<?= h($percent) ?>">
                <span class="donut-legend-name"><i class="donut-swatch donut-<?= h($segment['tone']) ?>" aria-hidden="true"></i><?= h($segment['label']) ?></span>
                <span class="donut-legend-value"><?= $count ?> <small><?= h($percent) ?> %</small></span>
            </li>
        <?php endforeach; ?>
    </ul>
</figure>
