<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EmailBroadcasterClient
{
    public function billing(array $message): array
    {
        return $this->notify(array_merge(['method' => 'billing'], $message));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function billingBulk(array $messages): array
    {
        return $this->notify([
            'method' => 'billing',
            'messages' => array_values($messages),
        ]);
    }

    public function notify(array $payload): array
    {
        $base = rtrim((string) config('services.email_broadcaster.url'), '/');
        if ($base === '') {
            throw new RuntimeException('EMAIL_BROADCASTER_URL belum diisi.');
        }

        $url = $base . '/api/notify.php';
        $token = trim((string) config('services.email_broadcaster.token'));
        $timeout = (int) config('services.email_broadcaster.timeout', 45);

        try {
            $request = Http::timeout($timeout)->acceptJson()->asJson();
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('email-broadcaster.request.failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Tidak dapat terhubung ke Email Broadcaster: ' . $e->getMessage(), 0, $e);
        }

        $json = $this->decode($response);

        Log::info('email-broadcaster.notify', [
            'url' => $url,
            'status' => $response->status(),
            'queued' => $json['queued'] ?? null,
            'failed' => $json['failed'] ?? null,
            'ok' => $json['ok'] ?? null,
        ]);

        if ($response->failed() || ($json['ok'] ?? false) !== true) {
            $error = $json['message'] ?? $json['error'] ?? ('HTTP ' . $response->status());

            throw new RuntimeException('Antrian email gagal: ' . (is_string($error) ? $error : json_encode($error)));
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
