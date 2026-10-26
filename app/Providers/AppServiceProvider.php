<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            try {
                $request = request();
                if ($request && $request->getHost()) {
                    URL::forceRootUrl($request->getSchemeAndHttpHost());
                    if ($request->isSecure()) {
                        URL::forceScheme('https');
                    }
                }
            } catch (\Throwable $e) {
                //
            }
        }

        Blade::directive('rupiah', function ($expression) {
            return "<?php echo 'Rp. ' . number_format($expression,0,',','.'); ?>";
        });
    }
}
