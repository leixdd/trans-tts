<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AnonymousVisitor
{
    public const COOKIE_NAME = 'tts_visitor';

    public function idFrom(Request $request): string
    {
        $existing = $request->cookie(self::COOKIE_NAME);
        $visitorId = $this->resolveVisitorId($existing);

        if ($visitorId !== null) {
            return $visitorId;
        }

        $visitorId = (string) Str::uuid();
        $this->queueCookie($visitorId);

        return $visitorId;
    }

    public function ensureCookie(Request $request): string
    {
        return $this->idFrom($request);
    }

    public function queueCookie(string $visitorId): void
    {
        Cookie::queue($this->makeCookie($visitorId));
    }

    public function makeCookie(string $visitorId): SymfonyCookie
    {
        $minutes = max(1, (int) config('services.novita.retention_days', 30) * 24 * 60);

        return cookie(
            self::COOKIE_NAME,
            Crypt::encryptString($visitorId),
            $minutes,
            '/',
            null,
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }

    public function isValidVisitorId(string $visitorId): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $visitorId,
        );
    }

    private function resolveVisitorId(mixed $existing): ?string
    {
        if (! is_string($existing) || $existing === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($existing);
            if ($this->isValidVisitorId($decrypted)) {
                return $decrypted;
            }
        } catch (DecryptException) {
            // Allow plain UUIDs in tests using withUnencryptedCookie.
        }

        if ($this->isValidVisitorId($existing)) {
            return $existing;
        }

        return null;
    }
}
