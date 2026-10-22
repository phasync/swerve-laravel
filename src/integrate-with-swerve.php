<?php

// Composer's autoload "files" entry: registers LaravelAdapter with swerve, so that it is there
// before `swerve.php` runs. LaravelAdapter::register() guards against running more than once
// (a direct require in a test, or a second autoloader that isn't Composer's own files-once
// bookkeeping) registering the adapter twice, which would make two adapters claim the same
// handler.
Swerve\Laravel\LaravelAdapter::register();
