<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// What Eloquent registers once per model class, from the static boot(): a creating listener and a
// global scope. GadgetServiceProvider adds an observer on top.
class Gadget extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();
        // Returns nothing: a value would halt the event before the observer
        static::creating(function (Gadget $gadget) {
            $gadget->listener_calls = ($gadget->listener_calls ?? 0) + 1;
        });
        static::addGlobalScope('visible', fn (Builder $query) => $query->where('hidden', false));
    }
}
