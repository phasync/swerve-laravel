<?php

namespace App\Observers;

use App\Models\Gadget;

class GadgetObserver
{
    public function creating(Gadget $gadget): void
    {
        $gadget->observer_calls = ($gadget->observer_calls ?? 0) + 1;
    }
}
