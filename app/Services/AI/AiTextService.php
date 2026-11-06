<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiTextService
{
    /**
     * Ask the configured text service for a JSON object. The service is kept
     * behind this small boundary so the rest of Bagoo never depends on a
     * provider-specific response shape.
     */
    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        return $this->generateJsonParts($systemPrompt, [['text' => $userPrompt]]);
    }

    public function generateJsonWithImage(string $systemPrompt, string $userPrompt, string $mimeType, string $imageBase64): array
    {
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 422);

        return $this->generateJsonParts($systemPrompt, [['text' => $userPrompt], [
            'inline_data' => ['mime_type' => $mimeType, 'data' => $imageBase64],
        ]]);
    }

    private function generateJsonParts(string $systemPrompt, array $parts): array
    {
        $apiKey = (string) config('ai.api_key');
        $model = (string) config('ai.model');

        if ($apiKey === '' || $model === '') {
            throw new RuntimeException('The assistant is not configured yet.');
        }

        $endpoint = rtrim((string) config('ai.base_url'), '/') . '/models/' . rawurlencode($model) . ':generateContent';

        try {
            $response = Http::timeout((int) config('ai.timeout', 20))
                ->retry(1, 250)
                ->acceptJson()
                ->post($endpoint . '?key=' . rawurlencode($apiKey), [
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => $parts,
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('The assistant could not respond right now.', 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('The assistant could not respond right now.');
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter(fn ($part) => is_string($part))
            ->implode('');

        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? '');
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The assistant returned an invalid response.');
        }

        return $decoded;
    }
}
