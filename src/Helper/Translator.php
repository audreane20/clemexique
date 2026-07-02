<?php

namespace App\Helper;

class Translator
{
    private array $messages = [];
    private string $language;

    public function __construct(string $lang)
    {
        $this->language = Locale::normalize($lang);
        $file = __DIR__ . '/../translations/messages.' . $this->language . '.php';

        if (!file_exists($file)) {
            $file = __DIR__ . '/../translations/messages.' . Locale::DEFAULT . '.php';
        }

        if (file_exists($file)) {
            $this->messages = require $file;
        }
    }

    public function trans(string $key, array $replacements = []): string
    {
        $message = $this->messages[$key] ?? $key;

        if ($replacements === []) {
            return $message;
        }

        return strtr($message, $replacements);
    }

    public function getLanguage(): string
    {
        return $this->language;
    }
}
