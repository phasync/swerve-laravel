<?php

// Routes that print outside their response (tests/StrayOutputTest.php), added to routes/web.php by
// tests/create-app.sh after swerve-tests.php. Without phasync-ext a request for one ends the worker.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/echo', function () {
    echo 'echoed ';

    return 'body';
});
// Output a route echoes, and then fails
Route::get('/echo-throw', function () {
    echo 'echoed before failing';

    throw new RuntimeException('failed after echo');
});
// Output echoed around a wait, before the response exists
Route::get('/echo-wait/{tag}', function (string $tag) {
    echo "$tag-";
    swerve_test_wait(0.1);
    echo $tag;

    return response()->json(['tag' => $tag]);
});
// What a request echoes in two parts around a wait, tests/ConcurrencyTest.php; the response is the tag
Route::get('/probe-echo/{tag}', function (Request $request, string $tag) {
    echo "$tag-";
    swerve_test_wait((float) $request->query('wait', 0.1));
    echo $tag;

    return $tag;
});
