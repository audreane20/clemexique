<?php

namespace App\Service;

use App\Helper\Locale;

class GoogleTranslateService
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        $text = trim($text);
        $sourceLanguage = Locale::normalize($sourceLanguage);
        $targetLanguage = Locale::normalize($targetLanguage);

        if ($text === '' || $sourceLanguage === $targetLanguage) {
            return $text;
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException('Google Translate API key is not configured.');
        }

        $payload = http_build_query([
            'q' => $text,
            'source' => $sourceLanguage,
            'target' => $targetLanguage,
            'format' => 'text',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(
            'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($this->apiKey),
            false,
            $context
        );

        if ($response === false) {
            throw new \RuntimeException('Google Translate API request failed.');
        }

        $decoded = json_decode($response, true);
        $translated = $decoded['data']['translations'][0]['translatedText'] ?? null;

        if (!is_string($translated) || trim($translated) === '') {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown Google Translate API error.';
            throw new \RuntimeException('Google Translate API error: ' . $errorMessage);
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
