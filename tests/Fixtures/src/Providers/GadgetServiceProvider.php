<?php

namespace App\Providers;

use App\Models\Gadget;
use App\Observers\GadgetObserver;
use Illuminate\Support\ServiceProvider;

class GadgetServiceProvider extends ServiceProvider
{
    public static int $boots = 0;

    public function register(): void
    {
        // An application with per-application state (APP_STATE): a class adds its slot when it is declared
        \getenv('APP_STATE') && \Swerve\Laravel\AppState::$defaults['registered'] ??= true;
    }

    public function boot(): void
    {
        ++self::$boots;
        $this->app->instance('test.app-id', self::$boots); // which application serves a request: the number of its boot
        Gadget::observe(GadgetObserver::class);
    }
}
