<?php
declare(strict_types=1);

final class IncidentMailSchedule
{
    public static function validateTime(string $time): void
    {
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new InvalidArgumentException('send_time muss das Format HH:MM (00:00 bis 23:59) haben.');
        }
    }

    /** Von systemd direkt einmal täglich aufrufbare Kalenderzeit. */
    public static function calendarExpression(string $sendTime, string $timezone): string
    {
        self::validateTime($sendTime);
        // Erlaubt IANA-Zeitzonen, aber keine Steuerzeichen oder systemd-Ausdrücke.
        if (!preg_match('/^[A-Za-z0-9_+\/-]+$/D', $timezone)) {
            throw new InvalidArgumentException('timezone muss eine gültige IANA-Zeitzone sein.');
        }
        new DateTimeZone($timezone);
        return '*-*-* ' . $sendTime . ':00 ' . $timezone;
    }

    public static function due(DateTimeImmutable $now, string $sendTime, ?string $lastSentDate): bool
    {
        self::validateTime($sendTime);
        return $now->format('H:i') >= $sendTime && $lastSentDate !== $now->format('Y-m-d');
    }
}
