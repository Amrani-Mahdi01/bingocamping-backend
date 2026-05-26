<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared Google reCAPTCHA v2 verification. Lives as a trait so any
 * controller exposing a publicly-reachable form (checkout, register,
 * future contact form, …) can gate itself with `$this->verifyRecaptcha(...)`
 * without duplicating the dev-mode workarounds for missing CA bundles.
 *
 * Local-dev convenience: when RECAPTCHA_SECRET_KEY is empty in .env we
 * skip the round-trip entirely so flows still work without any captcha
 * configuration. In production the secret must be set.
 */
trait VerifiesRecaptcha
{
    protected function verifyRecaptcha(?string $token, ?string $remoteIp): bool
    {
        $secret = (string) config('services.recaptcha.secret', '');
        if ($secret === '') {
            Log::info('reCAPTCHA: skipped (no secret configured)');
            return true;
        }
        if (! $token) {
            Log::warning('reCAPTCHA: rejected — no token sent by client');
            return false;
        }
        try {
            // Windows + PHP local dev often ships without a CA bundle
            // configured in php.ini → cURL error 60. Support either:
            //   1. cacert.pem sitting at the project root → point cURL at it
            //   2. APP_ENV=local fallback → skip verification entirely
            // Production with a properly configured php.ini hits neither
            // branch and verifies against the system trust store.
            $pending = Http::asForm()->timeout(5);
            $localCa = base_path('cacert.pem');
            if (file_exists($localCa)) {
                $pending = $pending->withOptions(['verify' => $localCa]);
            } elseif (app()->environment('local')) {
                $pending = $pending->withoutVerifying();
            }
            $response = $pending
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);
            $body = $response->json();
            $success = (bool) ($body['success'] ?? false);
            Log::info('reCAPTCHA verify result', [
                'success' => $success,
                'hostname' => $body['hostname'] ?? null,
                'error_codes' => $body['error-codes'] ?? [],
                'http_status' => $response->status(),
                'token_prefix' => substr($token, 0, 12).'…',
            ]);
            return $success;
        } catch (\Throwable $e) {
            // Network blip → log + treat as failure (safer than letting
            // through; the user can retry).
            Log::warning('reCAPTCHA: HTTP call to Google failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
