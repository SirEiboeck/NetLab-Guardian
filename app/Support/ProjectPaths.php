<?php
declare(strict_types=1);

/** Absolute Projektpfade – unabhängig vom Verzeichnis des ausführenden PHP-Skripts. */
final class ProjectPaths
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function storage(): string
    {
        return self::root() . '/storage';
    }

    public static function database(): string
    {
        return self::storage() . '/inventory.sqlite';
    }

    public static function rackLock(): string
    {
        return self::storage() . '/rack_check.lock';
    }

    public static function schema(): string
    {
        return self::root() . '/schema.sql';
    }

    public static function pythonScripts(): string
    {
        return self::root() . '/python_scripts';
    }
}
