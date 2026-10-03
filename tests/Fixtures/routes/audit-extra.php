<?php

// Flow probes of tests/AuditExtraTest.php, added by tests/create-app.sh: things a request does
// across several framework layers while another request is in flight (terminable middleware,
// route-model binding, validation, cookies, login).

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

if (!\class_exists('AudzTerm')) {
    /** Remembers the request in handle(); terminate() reports what it sees to storage/audz-term.log */
    class AudzTerm
    {
        public function handle(Request $request, Closure $next)
        {
            // terminate() gets a fresh middleware instance from the container (Laravel's design): remember on the request
            $request->attributes->set('audz.app', \spl_object_id(app()));
            $request->attributes->set('audz.self', \spl_object_id($request));

            return $next($request)->header('X-Audz', $request->route('tag'));
        }

        public function terminate(Request $request, $response): void
        {
            \file_put_contents(\storage_path('audz-term.log'), \json_encode([
                'url'      => $request->route('tag'),
                'header'   => $response->headers->get('X-Audz'),
                'same'     => $request->attributes->get('audz.self') === \spl_object_id($request),
                'sameApp'  => $request->attributes->get('audz.app') === \spl_object_id(app()),
                'current'  => Route::current()?->parameter('tag'),
                'user'     => Auth::user()?->name,
            ]) . "\n", \FILE_APPEND | \LOCK_EX);
        }
    }
}

Route::get('/audz-term/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    swerve_test_wait((float) $request->query('wait', 0.3));

    return ['tag' => $tag, 'start' => $start, 'end' => \microtime(true)];
})->middleware(AudzTerm::class);

// Explicit route-model binding that waits while resolving: the route's parameters are set, then another request runs
Route::bind('audzm', function (string $value) {
    swerve_test_wait('A' === $value ? 0.8 : 0.1);

    return (object) ['v' => $value];
});
Route::get('/audz-bind/{audzm}', function (Request $request, object $audzm) {
    $start = \microtime(true);
    swerve_test_wait('A' === $audzm->v ? 0.3 : 0.05);

    return ['start' => $start, 'param' => $audzm->v, 'route' => $request->route('audzm')->v, 'current' => Route::current()->parameter('audzm')->v, 'path' => $request->path(), 'end' => \microtime(true)];
});

// Validation that fails after a wait: each request gets its own message
Route::get('/audz-validate/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    $errors = validator(['x' => $tag], ['x' => [function ($attribute, $value, $fail) {
        swerve_test_wait('A' === $value ? 0.8 : 0.1);
        $fail("bad-$value");
    }]])->errors();

    return ['start' => $start, 'message' => $errors->first('x'), 'bag' => $errors->all(), 'end' => \microtime(true)];
});

// A cookie put on the response of one request, a wait meanwhile
Route::get('/audz-cookie/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    swerve_test_wait((float) $request->query('wait', 0.3));

    return response()->json(['start' => $start, 'end' => \microtime(true)])->cookie("audz_$tag", $tag)->header("X-Audz-$tag", $tag);
});

// Who the request is, before and after a wait
Route::get('/audz-who', function (Request $request) {
    $start  = \microtime(true);
    $before = [Auth::check(), Auth::user()?->name, $request->user()?->name];
    swerve_test_wait((float) $request->query('wait', 0.3));

    return ['start' => $start, 'before' => $before, 'after' => [Auth::check(), Auth::user()?->name, $request->user()?->name, \auth()->guard()->user()?->name], 'end' => \microtime(true)];
});
