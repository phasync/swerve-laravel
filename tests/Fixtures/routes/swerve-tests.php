<?php

// The test suite's routes, added to the Laravel skeleton's routes/web.php by tests/create-app.sh

use App\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Swerve\Laravel\Handler;
use Swerve\Swerve;

// Wait without blocking the worker: sleep() waits as a coroutine with phasync-ext
if (!\function_exists('swerve_test_wait')) {
    function swerve_test_wait(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : phasync::sleep($seconds);
    }
}

// Laravel 13 renamed the CSRF middleware
$withoutCsrf = \array_filter([ValidateCsrfToken::class, 'Illuminate\Foundation\Http\Middleware\PreventRequestForgery'], 'class_exists');

// A request-scoped service: a new instance for every request
app()->scoped('test.scoped', fn () => new stdClass());

Route::get('/json', fn () => ['hello' => 'world', 'laravel' => app()->version()]);
// Without the web group's cookies and session, as an API route
Route::get('/api/json', fn () => ['hello' => 'world'])->withoutMiddleware('web');
// A wait of ?ms= (default 10) in usleep(), as a database query waits: it blocks the worker
// without phasync-ext
Route::get('/api/usleep', function (Request $request) {
    \usleep(1000 * (int) $request->query('ms', 10));

    return ['waited' => (int) $request->query('ms', 10)];
})->withoutMiddleware('web');

Route::get('/form', fn () => Blade::render('<form method="post">@csrf<input name="name"></form>{{ $errors->first("name") }}'));
Route::post('/form', function (Request $request) {
    $request->validate(['name' => 'required']);

    return 'Hello ' . $request->input('name');
});

Route::post('/json-echo', fn (Request $request) => ['all' => $request->all(), 'isJson' => $request->isJson(), 'method' => $request->method()])
    ->withoutMiddleware($withoutCsrf);
Route::put('/json-echo', fn (Request $request) => ['all' => $request->all(), 'isJson' => $request->isJson(), 'method' => $request->method()])
    ->withoutMiddleware($withoutCsrf);

// A route may ask for swerve's PSR-7 request instead of Laravel's
Route::post('/psr-echo', fn (ServerRequestInterface $psr) => ['body' => (string) $psr->getBody(), 'parsed' => $psr->getParsedBody()])
    ->withoutMiddleware($withoutCsrf);

Route::post('/upload', function (Request $request) {
    $request->validate(['doc' => 'required|file', 'other' => 'required|file']);
    $doc = $request->file('doc');
    $tmp = $doc->getPathname();
    $out = [
        'name'   => $doc->getClientOriginalName(),
        'size'   => $doc->getSize(),
        'md5'    => \md5_file($tmp),
        'valid'  => $doc->isValid(),
        'stored' => $doc->store('uploads'),
        'tmp'    => [$tmp, $request->file('other')->getPathname()],
    ];
    $moved        = $request->file('other')->move(storage_path('app/moved'), 'other.txt');
    $out['moved'] = \file_get_contents($moved->getPathname());

    return $out;
})->withoutMiddleware($withoutCsrf);

Route::get('/isolation/{tag}', function (Request $request, string $tag) {
    $before = ['session' => session('tag'), 'user' => Auth::id(), 'scoped' => app('test.scoped')->tag ?? null, 'config' => config('app.tag')];
    $request->attributes->set('tag', $tag);
    session(['tag' => $tag]);
    Auth::setUser(new GenericUser(['id' => $tag]));
    app('test.scoped')->tag = $tag;
    config(['app.tag' => $tag]);
    swerve_test_wait((float) $request->query('wait', 0.2));

    return ['before' => $before, 'after' => [
        'query'   => request('q'),
        'route'   => request()->route('tag'),
        'attr'    => request()->attributes->get('tag'),
        'session' => session('tag'),
        'user'    => Auth::id(),
        'scoped'  => app('test.scoped')->tag,
        'config'  => config('app.tag'),
    ]];
});

Route::get('/counter', function () {
    session(['n' => $n = session('n', 0) + 1]);

    return ['n' => $n, 'pid' => \getmypid(), 'driver' => config('session.driver')];
});
Route::get('/flash/set', function () {
    session()->flash('message', 'saved');

    return 'ok';
});
Route::get('/flash/show', fn () => session('message', 'none'));

Route::get('/login', function () {
    User::firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada', 'password' => 'secret']);

    return ['ok' => Auth::attempt(['email' => 'ada@example.com', 'password' => 'secret']), 'id' => Auth::id()];
});
Route::get('/me', fn () => ['id' => Auth::id()]);
Route::get('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();

    return ['id' => Auth::id()];
});

Route::get('/stream', fn () => response()->stream(function () {
    echo 'first ' . \microtime(true) . "\n";
    \flush();
    swerve_test_wait(0.5);
    echo 'last ' . \microtime(true) . "\n";
}));
Route::get('/sse', fn () => response()->eventStream(function () {
    for ($i = 1; $i <= 3; ++$i) {
        yield "tick $i";
        swerve_test_wait(0.1);
    }
}));
Route::get('/forever', fn () => response()->stream(function () {
    try {
        while (true) {
            echo \str_repeat('x', 1000) . "\n";
            swerve_test_wait(0.05);
        }
    } finally {
        \file_put_contents(storage_path('forever-ended'), \getmypid());
    }
}));
Route::get('/download', fn () => response()->download(base_path('composer.json')));

Route::get('/echo', function () {
    echo 'echoed ';

    return 'body';
});
Route::get('/defer', function () {
    defer(function () {
        \usleep(700_000);
        \file_put_contents(storage_path('deferred'), \microtime(true));
    });

    return \microtime(true);
});
Route::get('/slow', function (Request $request) {
    swerve_test_wait((float) $request->query('s', 1));

    return 'slow done';
});
Route::get('/memory', fn () => \memory_get_usage());

// Every WebSocket callback that runs has a file in storage/ws-live: /ws/live counts them, in all workers
if (!\function_exists('swerve_test_live')) {
    function swerve_test_live(Closure $callback): Closure
    {
        return function (WebSocket $ws) use ($callback) {
            @\mkdir(storage_path('ws-live'));
            \touch($file = storage_path('ws-live/' . \getmypid() . '-' . \spl_object_id($ws)));
            try {
                $callback($ws);
            } finally {
                \unlink($file);
            }
        };
    }
}
Route::get('/ws/live', fn () => \count(\glob(storage_path('ws-live/*'))));

Route::get('/ws', fn (ServerRequestInterface $request) => WebSocket::from($request, swerve_test_live(function (WebSocket $ws) {
    foreach ($ws as $message) {
        $ws->isBinary() ? $ws->sendBinary(\strrev($message)) : $ws->send("echo: $message");
    }
})));

// Server push: forward a topic; the first message says the subscription is there, and where
Route::get('/ws/news', fn (ServerRequestInterface $request) => WebSocket::from($request, swerve_test_live(function (WebSocket $ws) {
    $news = Swerve::subscribe('news');
    $ws->send('subscribed ' . \getmypid());
    foreach ($news as $message) {
        $ws->send($message);
    }
})));
Route::get('/publish', function (Request $request) {
    if ($request->has('n')) {
        for ($i = 1; $i <= $request->integer('n'); ++$i) {
            Swerve::publish('news', $request->query('m') . " $i");
        }
    } else {
        Swerve::publish('news', $request->query('m'));
    }

    return 'published';
});

// Identity: the user taken from the request before WebSocket::from(), and what Auth says
// inside the callback, which is whatever request runs in the worker at that moment
Route::get('/login-as/{name}', function (string $name) {
    Auth::login(User::firstOrCreate(['email' => "$name@example.com"], ['name' => $name, 'password' => 'secret']));

    return Auth::user()->name;
});
Route::get('/ws/me', function (Request $request, ServerRequestInterface $psr) {
    $user = $request->user(); // taken while this request runs

    return WebSocket::from($psr, function (WebSocket $ws) use ($user) {
        $inside = function (Closure $read) {
            try {
                return $read();
            } catch (Throwable $e) {
                return $e::class;
            }
        };
        foreach ($ws as $message) {
            $ws->send(\json_encode([
                'user'    => $user?->name,
                'auth'    => $inside(fn () => Auth::user()?->name),     // wrong: whichever request runs now
                'db'      => $inside(fn () => User::find($user?->id)?->name), // wrong: outside a request
                'run'     => Handler::run(fn () => User::find($user?->id)?->name),
                'message' => $message,
            ]));
        }
    });
});
Route::get('/slow-me', function (Request $request) {
    swerve_test_wait((float) $request->query('s', 1));

    return Auth::user()?->name;
});

// Concurrency probes (tests/ConcurrencyTest.php): each sets one piece of request state to {tag},
// waits ?wait= seconds (another request may run meanwhile), and reads it back
if (!\function_exists('swerve_test_probes')) {
    function swerve_test_probes(): array
    {
        return [
            'request'   => [fn ($tag) => null, fn () => request('q')],
            'route'     => [fn ($tag) => null, fn () => Route::current()?->parameter('tag')],
            'container' => [fn ($tag) => app()->instance('swerve.tag', $tag), fn () => app()->bound('swerve.tag') ? app('swerve.tag') : null],
            'scoped'    => [fn ($tag) => app('test.scoped')->tag = $tag, fn () => app('test.scoped')->tag ?? null],
            'config'    => [fn ($tag) => config(['app.tag' => $tag]), fn () => config('app.tag')],
            'locale'    => [fn ($tag) => App::setLocale($tag), fn () => App::getLocale()],
            'session'   => [fn ($tag) => session(['tag' => $tag]), fn () => session('tag')],
            'auth'      => [fn ($tag) => Auth::user()?->name, fn () => Auth::user()?->name],
            'url'       => [fn ($tag) => null, fn () => \basename(url()->current())],
            'view'      => [fn ($tag) => View::share('tag', $tag), fn () => Blade::render('{{ $tag }}')],
            'stored'    => [fn ($tag) => null, fn () => session('tag')], // what the session kept
            // Through the request object the route was given, not the helpers
            'req-query'   => [fn ($tag) => null, fn ($request) => $request->query('q')],
            'req-route'   => [fn ($tag, $request) => null, fn ($request) => $request->route('tag')],
            'req-session' => [fn ($tag, $request) => $request->session()->put('tag', $tag), fn ($request) => $request->session()->get('tag')],
            'req-user'    => [fn ($tag, $request) => $request->user()?->name, fn ($request) => $request->user()?->name],
            'carbon'      => [fn ($tag) => App::setLocale($tag), fn () => Carbon\Carbon::create(2024, 1, 1)->translatedFormat('F')],
            // A view that waits while it renders: its output buffer is open meanwhile
            'blade'       => [fn ($tag) => null, fn ($request, $tag) => Blade::render('{{ $tag }}-@php(swerve_test_wait(0.1)){{ $tag }}', ['tag' => $tag])],
        ];
    }
}
Route::get('/probe/{item}/{tag}', function (Request $request, string $item, string $tag) {
    [$set, $read] = swerve_test_probes()[$item];
    $set($tag, $request);
    swerve_test_wait((float) $request->query('wait', 0.1));

    return ['read' => $read($request, $tag)];
});
// What a request echoes, in two parts around a wait
Route::get('/probe-echo/{tag}', function (Request $request, string $tag) {
    echo "$tag-";
    swerve_test_wait((float) $request->query('wait', 0.1));
    echo $tag;

    return '';
});
// A queued cookie, sent with this request's response
Route::get('/probe-cookie/{tag}', function (Request $request, string $tag) {
    Cookie::queue("probe_$tag", 'x');
    swerve_test_wait((float) $request->query('wait', 0.1));

    return $tag;
});
// A transaction rolled back after a wait, and a write made meanwhile outside any transaction
Route::get('/probe-db/rollback', function (Request $request) {
    DB::beginTransaction();
    swerve_test_wait((float) $request->query('wait', 0.2));
    $level = DB::transactionLevel();
    DB::rollBack();

    return ['level' => $level];
});
Route::get('/probe-db/write/{key}', function (string $key) {
    DB::table('cache')->insert(['key' => $key, 'value' => 'x', 'expiration' => \time() + 60]);

    return ['level' => DB::transactionLevel()];
});
Route::get('/probe-db/exists/{key}', fn (string $key) => ['exists' => DB::table('cache')->where('key', $key)->exists()]);

// The folder the application is served under (APP_BASE_PATH), as the request sees it
Route::get('/where', fn (Request $request) => ['path' => $request->path(), 'full' => $request->fullUrl(), 'base' => $request->getBaseUrl(), 'url' => url('/x'), 'route' => route('where')])->name('where');
Route::get('/go-back', fn () => back());
