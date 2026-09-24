<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Jangan hard-redirect ke login dari sini.
     * Recovery ditangani Handler + PersistentLogin.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
