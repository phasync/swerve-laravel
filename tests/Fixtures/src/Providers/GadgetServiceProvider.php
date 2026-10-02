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
        // A provider that needs the request while booting, as the HTTP kernel allows
        \Illuminate\Support\Facades\URL::forceRootUrl(\request()->root());
    }
}
