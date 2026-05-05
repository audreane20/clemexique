<?php

namespace App\Helper;

class Translator
{
    private array $messages = [];

    public function __construct(string $lang)
    {
        $file = __DIR__ . '/../translations/messages.' . $lang . '.php';

        if (!file_exists($file)) {
            $file = __DIR__ . '/../translations/messages.fr.php';
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