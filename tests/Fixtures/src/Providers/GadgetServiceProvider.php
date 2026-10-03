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
        // An application with context-local state (APP_CONTEXT_STATE): a class registers its slot when it is declared
        \getenv('APP_CONTEXT_STATE') && \phasync::$contextStateDefaults['registered'] ??= true;
    }

    public function boot(): void
    {
        ++self::$boots;
        Gadget::observe(GadgetObserver::class);
    }
}
