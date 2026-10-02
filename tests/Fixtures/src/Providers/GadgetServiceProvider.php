<?php

namespace App\Providers;

use App\Models\Gadget;
use App\Observers\GadgetObserver;
use Illuminate\Support\ServiceProvider;

class GadgetServiceProvider extends ServiceProvider
{
    public static int $boots = 0;

    public function boot(): void
    {
        ++self::$boots;
        Gadget::observe(GadgetObserver::class);
    }
}
