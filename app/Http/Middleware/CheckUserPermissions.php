<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUserPermissions
{
    public function handle(Request $request, Closure $next, ...$params)
    {
        PersistentLogin::restoreFromRequest($request);

        if (!Auth::check()) {
            PersistentLogin::recreateSession($request);
            PersistentLogin::restoreFromRequest($request);
        }

        if (!Auth::check()) {
            return PersistentLogin::unauthenticatedResponse($request);
        }
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }
        if (!$user->hasAnyPermission($params)) {
            abort(404, 'Halaman Tidak Ditemukan!');
        }

        return $next($request);
    }
}
