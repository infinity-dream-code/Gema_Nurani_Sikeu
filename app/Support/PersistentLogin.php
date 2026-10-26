<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class PersistentLogin
{
    public const COOKIE = 'gema_nurani';

    public const LEGACY_COOKIE = 'installment_malas';

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
        if (self::isAjax($request)) {
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

        $user = self::restoreFromRequest($request);
        if ($user) {
            return redirect()->to(self::stayUrl($request));
        }

        if (!$request->boolean('_retry') && self::hasStaySignal($request)) {
            self::recreateSession($request);
            self::restoreFromRequest($request);

            if (Auth::user() instanceof CyberKey) {
                return redirect()->to(self::stayUrl($request));
            }

            return redirect()->to(self::retryUri($request));
        }

        return redirect('/login');
    }

    public static function bindRequestUrl(?Request $request = null): void
    {
        $request = $request ?: request();
        if (!$request || !$request->getHost()) {
            return;
        }

        try {
            URL::forceRootUrl($request->getSchemeAndHttpHost());
            if ($request->isSecure()) {
                URL::forceScheme('https');
            }
        } catch (Throwable $e) {
            //
        }
    }

    public static function retryUri(Request $request): string
    {
        $query = $request->query();
        $query['_retry'] = 1;

        return self::relativeUri($request->getPathInfo(), $query);
    }

    public static function recreateSession(?Request $request = null): void
    {
        $request = $request ?: request();

        try {
            if ($request->hasSession()) {
                $session = $request->session();
                try {
                    $session->regenerate(true);
                } catch (Throwable $e) {
                    $session->setId(Str::random(40));
                    if (!$session->isStarted()) {
                        $session->start();
                    }
                }
                $session->regenerateToken();
            }
        } catch (Throwable $e) {
            //
        }
    }

    public static function stayUrl(Request $request): string
    {
        $candidates = [];

        if ($request->isMethod('GET')) {
            $candidates[] = self::relativeUri($request->getPathInfo(), $request->query());
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $refererHost = (string) parse_url($referer, PHP_URL_HOST);
            if ($refererHost !== '' && strcasecmp($refererHost, (string) $request->getHost()) === 0) {
                $path = (string) parse_url($referer, PHP_URL_PATH);
                $query = [];
                parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
                $candidates[] = self::relativeUri($path, $query);
            }
        }

        $candidates[] = '/admin';

        foreach ($candidates as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
            $normalized = rtrim($path, '/') ?: '/';
            if (in_array($normalized, ['/login', '/home', '/logout'], true)) {
                continue;
            }

            return self::urlWithoutRetry($url);
        }

        return '/admin';
    }

    private static function relativeUri(string $path, array $query = []): string
    {
        $path = $path !== '' ? $path : '/';

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    public static function cookieNames(): array
    {
        return array_values(array_unique([self::COOKIE, self::LEGACY_COOKIE]));
    }

    private static function hasStaySignal(Request $request): bool
    {
        foreach (self::cookieNames() as $name) {
            if ($request->cookies->has($name) || isset($_COOKIE[$name])) {
                return true;
            }
        }

        foreach (['gema_nurani_session', 'demo_installment_session', (string) config('session.cookie')] as $name) {
            if ($name !== '' && ($request->cookies->has($name) || isset($_COOKIE[$name]))) {
                return true;
            }
        }

        return false;
    }

    private static function isAjax(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->wantsJson()
            || $request->header('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->header('Accept'), 'application/json');
    }

    private static function urlWithoutRetry(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['_retry']);

        $rebuilt = ($parts['scheme'] ?? null)
            ? ($parts['scheme'] . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''))
            : '';
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt !== '' ? $rebuilt : $url;
    }

    public static function bind(?CyberKey $user): void
    {
        if (!$user) {
            return;
        }

        $guard = Auth::guard();
        try {
            $guard->login($user);
        } catch (Throwable $e) {
            if (method_exists($guard, 'getName') && session()->isStarted()) {
                session()->put($guard->getName(), $user->getAuthIdentifier());
            }
            $guard->setUser($user);
        }
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
        foreach (self::cookieNames() as $name) {
            Cookie::queue(cookie(
                $name,
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
        foreach (self::cookieNames() as $name) {
            foreach ([
                $request->cookie($name),
                $request->cookies->get($name),
                $_COOKIE[$name] ?? null,
            ] as $raw) {
                if (is_string($raw) && trim($raw) !== '') {
                    return trim($raw);
                }
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
