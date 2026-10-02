<?php

namespace App;

use Illuminate\Foundation\Application;

// bootstrap/app.php returns this instead of Application when APP_CLASS is set (tests/create-app.sh)
class CustomApplication extends Application
{
    public function swerveTestMarker(): string
    {
        return static::class;
    }
}
