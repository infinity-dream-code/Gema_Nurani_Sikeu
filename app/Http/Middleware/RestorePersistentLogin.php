<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RestorePersistentLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $current = Auth::user();
            if ($current instanceof \App\Models\CyberKey && !$request->cookie(PersistentLogin::COOKIE)) {
                PersistentLogin::queue($current);
            }

            return $next($request);
        }

        $user = PersistentLogin::userFromCookie($request->cookie(PersistentLogin::COOKIE));
        if ($user) {
            Auth::login($user);
            PersistentLogin::queue($user);

            return $next($request);
        }

        if ($request->cookie(PersistentLogin::COOKIE)) {
            PersistentLogin::forget();
        }

        return $next($request);
    }
}
