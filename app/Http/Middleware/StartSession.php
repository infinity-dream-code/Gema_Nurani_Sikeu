<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Session\Middleware\StartSession as Middleware;
use Illuminate\Support\Str;
use Throwable;

class StartSession extends Middleware
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (Throwable $e) {
            if (!$this->isRecoverableSessionError($e)) {
                throw $e;
            }

            $this->resetSessionIdentity($request);

            try {
                $session = $this->manager->driver();
                $session->setId(Str::random(40));

                return $this->handleStatefulRequest($request, $session, function ($request) use ($next) {
                    PersistentLogin::restoreFromRequest($request);

                    return $next($request);
                });
            } catch (Throwable $retry) {
                PersistentLogin::restoreFromRequest($request);

                if ($request->isMethod('GET') && !$request->boolean('_retry')) {
                    return redirect()->to($request->fullUrlWithQuery(['_retry' => 1]));
                }

                return $next($request);
            }
        }
    }

    protected function startSession($request, $session)
    {
        try {
            return parent::startSession($request, $session);
        } catch (Throwable $e) {
            $this->resetSessionIdentity($request);
            $session->setId(Str::random(40));
            $session->setRequestOnHandler($request);
            $session->start();

            return $session;
        }
    }

    protected function saveSession($request)
    {
        try {
            parent::saveSession($request);
        } catch (Throwable $e) {
            // Jangan gagalkan response hanya karena file session tidak tersimpan.
        }
    }

    private function resetSessionIdentity($request): void
    {
        $name = (string) config('session.cookie');
        $request->cookies->remove($name);
        unset($_COOKIE[$name]);
    }

    private function isRecoverableSessionError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage() . ' ' . $e->getPrevious()?->getMessage());

        return str_contains($message, 'session')
            || str_contains($message, 'unable to obtain lock')
            || str_contains($message, 'unable to create lockable file')
            || str_contains($message, 'resource temporarily unavailable')
            || str_contains($message, 'failed to open stream')
            || str_contains($message, 'serialization')
            || str_contains($message, 'unserialize');
    }
}
