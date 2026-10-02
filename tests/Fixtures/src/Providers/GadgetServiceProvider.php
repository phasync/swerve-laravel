<?php

namespace App\Providers;

use App\Models\Gadget;
use App\Observers\GadgetObserver;
use Illuminate\Support\ServiceProvider;

class GadgetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gadget::observe(GadgetObserver::class);
    }
}
