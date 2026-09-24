<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Support\Facades\Cookie;

class PersistentLogin
{
    public const COOKIE = 'installment_malas';

    public static function queue(CyberKey $user): void
    {
        $payload = json_encode([
            'urut' => (int) $user->urut,
            'exp' => now()->addYears(5)->timestamp,
        ]);

        Cookie::queue(cookie(
            self::COOKIE,
            $payload,
            5256000,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax'
        ));
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    public static function userFromCookie(?string $raw): ?CyberKey
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $urut = (int) ($data['urut'] ?? 0);
        $exp = (int) ($data['exp'] ?? 0);

        if ($urut <= 0 || $exp < time()) {
            return null;
        }

        return CyberKey::query()->where('urut', $urut)->first();
    }
}
