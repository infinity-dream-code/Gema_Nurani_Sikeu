<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckSession
{
    public function handle($request, Closure $next)
    {
        PersistentLogin::restoreFromRequest($request);

        if (!Auth::check() && !session()->has('user')) {
            PersistentLogin::recreateSession($request);
            PersistentLogin::restoreFromRequest($request);
        }

        if (!Auth::check() && !session()->has('user')) {
            return PersistentLogin::unauthenticatedResponse($request);
        }

        return $next($request);
    }
}
