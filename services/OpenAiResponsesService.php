<?php

final class OpenAiResponsesService
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? getenv('OPENAI_API_KEY')));
    }

    public function respond(string $model, int $maxOutputTokens, array $history, string $question, string $verifiedContext): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('The AI Agent is not configured. Set OPENAI_API_KEY on the server.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for AI Agent mode.');
        }

        $input = [];
        foreach (array_slice($history, -10) as $message) {
            $role = ($message['sender'] ?? '') === 'user' ? 'user' : 'assistant';
            $text = trim((string) ($message['message_text'] ?? ''));
            if ($text !== '') {
                $input[] = ['role' => $role, 'content' => mb_substr($text, 0, 2500)];
            }
        }
        $input[] = [
            'role' => 'user',
            'content' => "Question: {$question}\n\nVerified permission-scoped system result:\n{$verifiedContext}",
        ];

        $requestPayload = [
            'model' => $model,
            'instructions' => 'You are the MyFreeman church management assistant. Answer concisely and professionally. Treat the supplied verified system result as authoritative. Never invent names, figures, permissions, database records, or completed actions. Explain when a request is outside the available system data. Do not reveal implementation details or secrets.',
            'input' => $input,
            'max_output_tokens' => $maxOutputTokens,
        ];
        if ($model === 'gpt-6-luna') {
            // Luna supports reasoning effort "none". This keeps the focused
            // assistant fast and prevents hidden reasoning tokens consuming
            // the small output budget used by the connection test.
            $requestPayload['reasoning'] = ['effort' => 'none'];
        }
        $payload = json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode the AI request.');
        }

        $responseHeaders = [];
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);
        $raw = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $curlError !== '') {
            throw new RuntimeException('The AI Agent could not be reached.');
        }
        $decoded = json_decode($raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $providerMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            error_log('OpenAI Responses error HTTP ' . $httpCode . ': ' . $providerMessage);
            throw new RuntimeException($this->safeHttpError($httpCode));
        }

        $text = '';
        foreach (($decoded['output'] ?? []) as $output) {
            if (($output['type'] ?? '') !== 'message') {
                continue;
            }
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $text .= (string) ($content['text'] ?? '');
                }
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('The AI Agent returned an empty response.');
        }

        return [
            'text' => $text,
            'request_id' => (string) ($responseHeaders['x-request-id'] ?? ''),
        ];
    }

    public function testConnection(string $model): array
    {
        return $this->respond(
            $model,
            16,
            [],
            'Reply with exactly OK.',
            'This is an administrator connection test. No church or member data is included.'
        );
    }

    private function safeHttpError(int $httpCode): string
    {
        return match ($httpCode) {
            400 => 'OpenAI rejected the test request. Check the selected model and request settings.',
            401 => 'OpenAI rejected the API key. Replace the revoked or invalid server key.',
            403 => 'This OpenAI project is not permitted to use the selected model.',
            404 => 'The selected OpenAI model is unavailable to this project.',
            429 => 'OpenAI rejected the request because of a rate limit, quota, or billing restriction.',
            default => $httpCode >= 500
                ? 'OpenAI is temporarily unavailable. Try again later.'
                : 'The OpenAI connection test failed (HTTP ' . $httpCode . ').',
        };
    }
}
