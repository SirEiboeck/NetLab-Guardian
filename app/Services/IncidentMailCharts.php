<?php
declare(strict_types=1);

/**
 * Hochauflösende, antialiaste PNG-Donuts für Outlook.
 * Erst in 3-facher Größe zeichnen, dann auf 440px herunterskalieren:
 * E-Mail-Clients zeigen sie mit 190 CSS-Pixeln an (Retina-Qualität).
 */
final class IncidentMailCharts
{
    /** @param list<array{count:int,color:string}> $segments */
    public static function donut(array $segments, int $center, string $caption = ''): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return null; // Statistik und Legende bleiben ohne GD vollständig lesbar.
        }
        $outputSize = 440;
        $scale = 3;
        $size = $outputSize * $scale;
        $im = imagecreatetruecolor($size, $size);
        if ($im === false) return null;

        $bg = self::color($im, '#151f30');
        $track = self::color($im, '#29374b');
        $fg = self::color($im, '#f1f7ff');
        $muted = self::color($im, '#9eb6c8');
        imagefill($im, 0, 0, $bg);
        if (function_exists('imageantialias')) imageantialias($im, true);

        $mid = (int) ($size / 2);
        $outer = 370 * $scale;
        $inner = 258 * $scale;
        imagefilledellipse($im, $mid, $mid, $outer, $outer, $track);

        $total = array_sum(array_map(static fn(array $part): int => max(0, (int) ($part['count'] ?? 0)), $segments));
        $start = -90.0;
        if ($total > 0) {
            $nonEmpty = count(array_filter($segments, static fn(array $segment): bool => (int) ($segment['count'] ?? 0) > 0));
            foreach ($segments as $segment) {
                $count = max(0, (int) ($segment['count'] ?? 0));
                if ($count === 0) continue;
                $extent = 360.0 * $count / $total;
                $gap = $nonEmpty > 1 && $extent > 3.0 ? 0.9 : 0.0;
                imagefilledarc($im, $mid, $mid, $outer, $outer,
                    (int) round($start + $gap), (int) round($start + $extent - $gap),
                    self::color($im, (string) $segment['color']), IMG_ARC_PIE);
                $start += $extent;
            }
        }
        imagefilledellipse($im, $mid, $mid, $inner, $inner, $bg);

        // Freie Systemschrift (falls installiert) für geglättete Zahlen und Labels.
        $font = null;
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        ] as $candidate) {
            if (is_readable($candidate)) {
                $font = $candidate;
                break;
            }
        }
        $label = (string) $center;
        $caption = trim($caption);
        if ($font && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $valueSize = (strlen($label) > 4 ? 40 : 55) * $scale;
            self::centerText($im, $font, $valueSize, $fg, $label,
                $mid, $mid + ($caption !== '' ? -4 : 13) * $scale);
            if ($caption !== '') {
                self::centerText($im, $font, 16 * $scale, $muted, strtoupper($caption),
                    $mid, $mid + 49 * $scale);
            }
        }

        $pngImage = imagecreatetruecolor($outputSize, $outputSize);
        if ($pngImage === false) {
            imagedestroy($im);
            return null;
        }
        imagecopyresampled($pngImage, $im, 0, 0, 0, 0,
            $outputSize, $outputSize, $size, $size);
        imagedestroy($im);

        // Lesbare Fallback-Beschriftung ohne System-TTF (benötigt keine zusätzlichen Assets).
        if (!$font || !function_exists('imagettftext')) {
            $x = (int) (($outputSize - strlen($label) * imagefontwidth(5)) / 2);
            imagestring($pngImage, 5, $x, (int) ($outputSize / 2 - 9), $label, self::color($pngImage, '#f1f7ff'));
        }
        ob_start();
        imagepng($pngImage, null, 6);
        $result = ob_get_clean();
        imagedestroy($pngImage);
        return is_string($result) && $result !== '' ? $result : null;
    }

    /** @param GdImage $image */
    private static function centerText($image, string $font, int $fontSize, int $color,
        string $text, int $centerX, int $baselineY): void
    {
        $bounds = imagettfbbox($fontSize, 0, $font, $text);
        if ($bounds === false) return;
        $width = $bounds[2] - $bounds[0];
        imagettftext($image, $fontSize, 0, (int) ($centerX - $width / 2),
            $baselineY, $color, $font, $text);
    }

    /** @param GdImage $image */
    private static function color($image, string $hex): int
    {
        return imagecolorallocate($image, hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }
}
