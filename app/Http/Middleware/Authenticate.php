<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        PersistentLogin::restoreFromRequest($request);

        return parent::handle($request, $next, ...$guards);
    }

    /**
     * Recovery ditangani Handler + PersistentLogin.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
