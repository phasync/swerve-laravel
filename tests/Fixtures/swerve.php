<?php

// WorkerAutoloader (swerve itself is installed in this app's vendor/) loads only swerve's own
// dependency closure, never this application's: load the real autoloader first, so that
// Swerve\Laravel\serve() (and the application's own classes) exist.
require __DIR__ . '/vendor/autoload.php';

return Swerve\Laravel\serve(__DIR__, (string) \getenv('APP_BASE_PATH'), (float) (\getenv('APP_WINDOW') ?: 60), (int) (\getenv('APP_MAX_APPLICATIONS') ?: 128));
