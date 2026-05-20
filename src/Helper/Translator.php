<?php

namespace App\Helper;

class Translator
{
    private array $messages = [];

    public function __construct(string $lang)
    {
        $language = Locale::normalize($lang);
        $file = __DIR__ . '/../translations/messages.' . $language . '.php';

        if (!file_exists($file)) {
            $file = __DIR__ . '/../translations/messages.' . Locale::DEFAULT . '.php';
        }

        if (file_exists($file)) {
            $this->messages = require $file;
        }
    }

    public function trans(string $key): string
    {
        return $this->messages[$key] ?? $key;
    }
}
