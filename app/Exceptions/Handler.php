<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $e, $request) {
            if (!$this->isTransientError($e)) {
                return null;
            }

            $csrf = $this->safeCsrfToken();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Silakan coba lagi.',
                    'csrf_token' => $csrf,
                ], $e instanceof TokenMismatchException ? 419 : 503);
            }

            if ($request->isMethod('GET') && !$request->boolean('_retry')) {
                return redirect()->to($request->fullUrlWithQuery(['_retry' => 1]));
            }

            $fallback = url()->previous() ?: url('/admin');

            return redirect()
                ->to($fallback)
                ->withInput($request->except('_token', 'password', 'password_confirmation'));
        });
    }

    private function isTransientError(Throwable $e): bool
    {
        if ($e instanceof TokenMismatchException) {
            return true;
        }

        $message = strtolower($e->getMessage() . ' ' . $e->getPrevious()?->getMessage());

        return str_contains($message, 'session store not set')
            || str_contains($message, 'failed to retrieve the session')
            || str_contains($message, 'unable to create lockable file')
            || str_contains($message, 'resource temporarily unavailable')
            || str_contains($message, 'session file')
            || str_contains($message, 'has gone away')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'too many connections')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'try restarting transaction')
            || (str_contains($message, 'failed to open stream') && str_contains($message, 'session'));
    }

    private function safeCsrfToken(): ?string
    {
        try {
            return csrf_token();
        } catch (Throwable $e) {
            return null;
        }
    }
}
