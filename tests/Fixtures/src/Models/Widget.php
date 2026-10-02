<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Like Gadget, but no provider touches it: it boots at its first use in a request
class Widget extends Model
{
    protected $table   = 'gadgets';
    public $timestamps = false;
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Widget $widget) {
            $widget->listener_calls = ($widget->listener_calls ?? 0) + 1;
        });
    }
}
