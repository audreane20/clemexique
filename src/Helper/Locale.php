<?php

namespace App\Helper;

class Locale
{
    public const DEFAULT = 'fr';

    /** @var string[] */
    private const SUPPORTED = ['fr', 'en', 'es'];

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return self::SUPPORTED;
    }

    public static function normalize(?string $language, string $default = self::DEFAULT): string
    {
        $language = strtolower(trim((string) $language));

        return in_array($language, self::SUPPORTED, true) ? $language : $default;
    }

    public static function preferredLanguageLabel(string $language): string
    {
        return match (self::normalize($language)) {
            'en' => 'Anglais',
            'es' => 'Espagnol',
            default => 'Français',
        };
    }
}
