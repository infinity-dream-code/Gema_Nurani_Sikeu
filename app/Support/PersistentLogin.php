<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PersistentLogin
{
    public const COOKIE = 'installment_malas';

    public static function lifetimeMinutes(): int
    {
        $minutes = (int) config('session.lifetime', 5256000);

        return $minutes > 0 ? $minutes : 5256000;
    }

    public static function restoreFromRequest(Request $request): ?CyberKey
    {
        try {
            $user = Auth::user();
            if (!$user instanceof CyberKey) {
                $user = self::userFromRequest($request);
            }

            if ($user instanceof CyberKey) {
                self::bind($user);
                self::queue($user);

                return $user;
            }
        } catch (Throwable $e) {
            //
        }

        return Auth::user() instanceof CyberKey ? Auth::user() : null;
    }

    public static function unauthenticatedResponse(Request $request)
    {
        $isAjax = $request->expectsJson()
            || $request->ajax()
            || $request->wantsJson()
            || $request->header('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->header('Accept'), 'application/json');

        if ($isAjax) {
            $csrf = null;
            try {
                $csrf = csrf_token();
            } catch (Throwable $e) {
                //
            }

            return response()->json([
                'ok' => true,
                'retry' => true,
                'token' => $csrf,
                'csrf_token' => $csrf,
            ], 401);
        }

        $hasStayCookie = $request->cookies->has(self::COOKIE)
            || $request->cookies->has((string) config('session.cookie'))
            || isset($_COOKIE[self::COOKIE])
            || isset($_COOKIE[(string) config('session.cookie')]);

        // Ada jejak login → jangan lempar ke login; retry URL yang sama.
        if ($hasStayCookie && !$request->boolean('_retry')) {
            return redirect()->to($request->fullUrlWithQuery(['_retry' => 1]));
        }

        if ($hasStayCookie) {
            $fallback = $request->isMethod('GET')
                ? $request->url()
                : (url()->previous() ?: url('/admin'));

            return redirect()->to($fallback);
        }

        return redirect()->route('login');
    }

    public static function bind(?CyberKey $user): void
    {
        if (!$user) {
            return;
        }

        $guard = Auth::guard();
        if (method_exists($guard, 'getName') && session()->isStarted()) {
            session()->put($guard->getName(), $user->getAuthIdentifier());
        }

        $guard->setUser($user);
    }

    public static function queue(?CyberKey $user): void
    {
        if (!$user) {
            return;
        }

        Cookie::queue(cookie(
            self::COOKIE,
            self::encodePayload($user),
            self::lifetimeMinutes(),
            config('session.path', '/'),
            config('session.domain'),
            self::secure(),
            true,
            false,
            config('session.same_site', 'lax')
        ));
    }

    public static function forget(): void
    {
        Cookie::queue(cookie(
            self::COOKIE,
            '',
            -2628000,
            config('session.path', '/'),
            config('session.domain'),
            self::secure(),
            true,
            false,
            config('session.same_site', 'lax')
        ));
    }

    public static function userFromRequest(Request $request): ?CyberKey
    {
        $raw = self::rawCookieValue($request);
        if ($raw === null) {
            return null;
        }

        try {
            $data = self::decodePayload($raw);
            if (!is_array($data)) {
                return null;
            }

            $id = (int) ($data['id'] ?? $data['urut'] ?? 0);
            if ($id <= 0) {
                return null;
            }

            if (isset($data['exp']) && (int) $data['exp'] > 0 && (int) $data['exp'] < time()) {
                return null;
            }

            $user = CyberKey::query()->where('urut', $id)->first();
            if (!$user) {
                return null;
            }

            $cookieUser = trim((string) ($data['u'] ?? ''));
            if ($cookieUser !== '' && strcasecmp($cookieUser, (string) $user->users) !== 0) {
                return null;
            }

            return $user;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function rawCookieValue(Request $request): ?string
    {
        foreach ([
            $request->cookie(self::COOKIE),
            $request->cookies->get(self::COOKIE),
            $_COOKIE[self::COOKIE] ?? null,
        ] as $raw) {
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }

        return null;
    }

    private static function encodePayload(CyberKey $user): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode([
            'id' => $user->getAuthIdentifier(),
            'u' => (string) $user->users,
            'exp' => now()->addYears(5)->timestamp,
        ], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        return $encoded . '.' . self::signature($encoded);
    }

    private static function decodePayload(string $raw): ?array
    {
        $candidates = [$raw, urldecode($raw)];

        foreach (self::decryptLegacy($raw) as $legacy) {
            $candidates[] = $legacy;
        }

        foreach (array_unique(array_filter($candidates, 'is_string')) as $candidate) {
            $parsed = self::parseCandidate($candidate);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private static function parseCandidate(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '.')) {
            [$encoded, $signature] = explode('.', $raw, 2);
            if ($encoded !== '' && $signature !== '' && hash_equals(self::signature($encoded), $signature)) {
                $json = base64_decode(strtr($encoded, '-_', '+/'), true);
                $data = is_string($json) ? json_decode($json, true) : null;

                return is_array($data) ? $data : null;
            }
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private static function decryptLegacy(string $raw): array
    {
        $found = [];

        try {
            $found[] = Crypt::decryptString($raw);
        } catch (Throwable $e) {
            //
        }

        try {
            $decrypted = Crypt::decrypt($raw, false);
            if (is_string($decrypted)) {
                $found[] = $decrypted;
            }
        } catch (Throwable $e) {
            //
        }

        return $found;
    }

    private static function signature(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private static function secure(): bool
    {
        $configured = config('session.secure');
        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        // Local HTTP: jangan set Secure supaya cookie tetap terkirim.
        return (bool) request()?->isSecure();
    }
}
