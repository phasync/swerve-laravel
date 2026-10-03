<?php

// Routes for tests/AuditResourcesTest.php: resources and lifetime shared between requests by accident

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

// MySQL (a MariaDB test container) for queries that really wait; SQLite otherwise
\config(['database.connections.advmysql' => [
    'driver' => 'mysql', 'host' => \getenv('ADV_MYSQL_HOST') ?: '127.0.0.1', 'port' => \getenv('ADV_MYSQL_PORT') ?: 13306,
    'database' => 'adv_resources', 'username' => 'root', 'password' => 'test', 'charset' => 'utf8mb4',
]]);

if (!\class_exists('AdvLog')) {
    /** Process-wide on purpose: what the routes saw, read back by /adv/log */
    final class AdvLog
    {
        public static array $lines = [];
        public static array $weak = [];
    }
}

if (!\function_exists('audx_wait')) {
    function audx_wait(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : phasync::sleep($seconds);
    }

    /** Stack depth of a PHP handler: pops them all, then pushes them back */
    function audx_depth(string $kind): int
    {
        $set = "set_{$kind}_handler";
        $pop = "restore_{$kind}_handler";
        $stack = [];
        while (null !== ($h = $set(null))) {
            $stack[] = $h;
            $pop();
            $pop();
        }
        $pop();
        foreach (\array_reverse($stack) as $h) {
            $set($h);
        }

        return \count($stack);
    }
}

Route::prefix('audx')->withoutMiddleware('web')->group(function () {
    Route::get('log', function () {
        $lines = AdvLog::$lines;
        AdvLog::$lines = [];

        return $lines;
    });

    // --- database: which connection, transaction, session and query log a request sees
    Route::get('db/{tag}', function (Request $r, string $tag) {
        $db = DB::connection($r->query('c', 'advmysql'));
        $db->statement('set @adv = ?', [$tag]);
        $db->statement('create temporary table if not exists audx_tmp (v varchar(20))');
        $db->table('audx_tmp')->insert(['v' => $tag]);
        $db->enableQueryLog();
        $db->beginTransaction();
        $start = \microtime(true);
        $db->select('select sleep(0.3)');
        $out = [
            'start' => $start, 'end' => \microtime(true),
            'level' => $db->transactionLevel(),
            'var' => $db->selectOne('select @adv as v')->v,
            'tmp' => $db->table('audx_tmp')->pluck('v')->all(),
            'log' => \count($db->getQueryLog()),
            'conn' => $db->selectOne('select connection_id() as id')->id,
        ];
        $db->rollBack();

        return $out;
    });
    // What a connection kept from earlier requests
    Route::get('db-state', function (Request $r) {
        $db = DB::connection($r->query('c', 'advmysql'));

        return [
            'level' => $db->transactionLevel(),
            'var' => $db->selectOne('select @adv as v')->v,
            'tmp' => $db->selectOne('select count(*) as n from information_schema.tables where table_name = ? and table_schema = ?', ['audx_tmp', 'adv_resources'])->n,
            'logging' => $db->logging(),
            'log' => \count($db->getQueryLog()),
        ];
    });
    Route::get('db-leave-open/{how}', function (string $how) {
        DB::beginTransaction();
        DB::table('cache')->insert(['key' => "adv-open-$how", 'value' => 'x', 'expiration' => \time() + 60]);
        if ('throw' === $how) {
            throw new RuntimeException('adv');
        }

        return 'ok';
    });
    Route::get('db-open-exists/{how}', fn (string $how) => [
        'level' => DB::transactionLevel(), 'exists' => DB::table('cache')->where('key', "adv-open-$how")->exists(),
    ]);
    Route::get('db-listen/{tag}', function (Request $r, string $tag) {
        DB::listen(fn () => AdvLog::$lines[] = "listen-$tag");
        audx_wait((float) $r->query('wait', 0));

        return 'ok';
    });
    Route::get('db-query', fn () => DB::select('select 1') ? 'ok' : '');
    Route::get('db-querylog/on', function (Request $r) {
        DB::enableQueryLog();
        audx_wait((float) $r->query('wait', 0));

        return 'on';
    });
    Route::get('db-querylog/state', function () {
        DB::select('select 1');

        return ['logging' => DB::logging(), 'log' => \count(DB::getQueryLog())];
    });

    // --- Eloquent's process-wide switches
    Route::get('model/unguard', fn () => \Illuminate\Database\Eloquent\Model::unguard() ?: 'ok');
    Route::get('model/strict', function () {
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes();

        return 'ok';
    });
    Route::get('model/state', fn () => [
        'unguarded' => \Illuminate\Database\Eloquent\Model::isUnguarded(),
        'lazy' => \Illuminate\Database\Eloquent\Model::preventsLazyLoading(),
        'discard' => \Illuminate\Database\Eloquent\Model::preventsSilentlyDiscardingAttributes(),
    ]);
    Route::get('model/listen/{tag}', function (string $tag) {
        User::creating(fn () => AdvLog::$lines[] = "creating-$tag");

        return 'ok';
    });
    Route::get('model/create/{tag}', function (string $tag) {
        DB::beginTransaction();
        User::create(['name' => $tag, 'email' => "$tag-" . \bin2hex(\random_bytes(4)) . '@example.com', 'password' => 'x']);
        DB::rollBack();

        return 'ok';
    });
    // User::creating() from the framework's events, as after Event::fake()
    Route::get('model/observe-events', function () {
        Event::listen('eloquent.created: ' . User::class, fn () => AdvLog::$lines[] = 'created-heard');

        return 'ok';
    });

    // --- events, fakes
    Route::get('event/listen/{tag}', function (Request $r, string $tag) {
        Event::listen('adv.event', fn () => AdvLog::$lines[] = "event-$tag");
        audx_wait((float) $r->query('wait', 0));

        return 'ok';
    });
    Route::get('event/dispatch', fn () => Event::dispatch('adv.event') ?: 'ok');
    // Fake {what}, wait, and say what is faked; or dispatch really while another request fakes
    Route::get('fake/{what}', function (Request $r, string $what) {
        $start = \microtime(true);
        ['Event' => Event::class, 'Queue' => Queue::class, 'Mail' => Mail::class, 'Bus' => Bus::class][$what]::fake();
        audx_wait(0.3);

        return ['start' => $start, 'end' => \microtime(true)];
    });
    Route::get('real/{what}', function (string $what) {
        $start = \microtime(true);
        audx_wait(0.1);
        $out = match ($what) {
            'Event' => (function () {
                Event::listen('adv.real', fn () => AdvLog::$lines[] = 'real-heard');
                Event::dispatch('adv.real');

                return \count(AdvLog::$lines);
            })(),
            'Queue' => (function () {
                Queue::connection('sync')->push(function () {
                    AdvLog::$lines[] = 'job-ran';
                });

                return \in_array('job-ran', AdvLog::$lines, true);
            })(),
            'Mail' => (function () {
                Mail::mailer('array')->raw('x', fn ($m) => $m->to('a@b.c')->subject('s'));

                return \count(Mail::mailer('array')->getSymfonyTransport()->messages());
            })(),
            'Bus' => Bus::dispatchSync(new class {
                public function handle(): int
                {
                    return \count(AdvLog::$lines) + 1;
                }
            }),
        };

        return ['start' => $start, 'end' => \microtime(true), 'out' => $out, 'faked' => ['Event' => Event::class, 'Queue' => Queue::class, 'Mail' => Mail::class, 'Bus' => Bus::class][$what]::getFacadeRoot() instanceof \Illuminate\Support\Testing\Fakes\Fake];
    });
    Route::get('mail/count', function () {
        return \count(Mail::mailer('array')->getSymfonyTransport()->messages());
    });
    Route::get('mail/send', function () {
        Mail::mailer('array')->raw('x', fn ($m) => $m->to('a@b.c')->subject('s'));

        return 'ok';
    });
    Route::get('schedule/add', function () {
        app(Schedule::class)->call(fn () => null)->daily();

        return 'ok';
    });
    Route::get('schedule/count', fn () => \count(app(Schedule::class)->events()));
});

if (!\function_exists('audx_track')) {
    /** Remember weakly what belongs to this request, to see later whether it was freed */
    function audx_track(string $tag): void
    {
        $r = request();
        AdvLog::$weak[] = [
            'app' => WeakReference::create(app()),
            'request' => WeakReference::create($r),
            'scoped' => WeakReference::create(app('test.scoped')),
            'session' => $r->hasSession() ? WeakReference::create($r->session()) : null,
            'tag' => $tag,
        ];
    }
}

Route::prefix('audx')->withoutMiddleware('web')->group(function () {
    // --- terminating, deferred, booted and shutdown callbacks, per request
    Route::get('term/{tag}', function (Request $r, string $tag) {
        app()->instance('adv.tag', $tag);
        app()->terminating(fn () => AdvLog::$lines[] = "term-$tag:" . app('adv.tag'));
        audx_wait((float) $r->query('wait', 0.2));

        return $tag;
    });
    Route::get('defer/{tag}', function (Request $r, string $tag) {
        app()->instance('adv.tag', $tag);
        defer(function () use ($tag) {
            audx_wait(0.1);
            AdvLog::$lines[] = "defer-$tag:" . app('adv.tag');
        });
        audx_wait((float) $r->query('wait', 0.2));

        return $tag;
    });
    Route::get('after/{tag}', function (Request $r, string $tag) {
        app()->instance('adv.tag', $tag);
        dispatch(function () use ($tag) {
            AdvLog::$lines[] = "after-$tag:" . app('adv.tag');
        })->afterResponse();
        audx_wait((float) $r->query('wait', 0.2));

        return $tag;
    });
    Route::get('booted/{tag}', function (string $tag) {
        app()->booted(fn () => AdvLog::$lines[] = "booted-$tag");

        return $tag;
    });
    Route::get('shutdown/{tag}', function (string $tag) {
        @\unlink(storage_path('adv-shutdown'));
        \register_shutdown_function(fn () => \file_put_contents(storage_path('adv-shutdown'), "$tag\n", \FILE_APPEND));

        return $tag;
    });
    Route::get('shutdown-seen', fn () => \is_file(storage_path('adv-shutdown')) ? \trim(\file_get_contents(storage_path('adv-shutdown'))) : '');

    // --- lifetime: what a worker holds after requests
    Route::get('vitals', function (Request $r) {
        $r->boolean('gc') && \gc_collect_cycles();
        $alive = ['app' => [], 'request' => [], 'scoped' => [], 'session' => []];
        foreach (AdvLog::$weak as $entry) {
            foreach (['app', 'request', 'scoped', 'session'] as $k) {
                $o = $entry[$k]?->get();
                $o && $alive[$k][\spl_object_id($o)] = 1;
            }
        }

        return [
            'memory' => \memory_get_usage(), 'fds' => \count(\scandir('/proc/self/fd')), 'ob' => \ob_get_level(),
            'error_handlers' => audx_depth('error'), 'exception_handlers' => audx_depth('exception'),
            'autoloaders' => \count(\spl_autoload_functions()),
            'tracked' => \count(AdvLog::$weak), 'alive' => \array_map('count', $alive),
        ];
    });
    Route::get('ping/{tag}', fn (string $tag) => ['tag' => $tag, 'at' => \microtime(true)]);
    Route::get('slow/{tag}', function (Request $r, string $tag) {
        $start = \microtime(true);
        audx_wait((float) $r->query('wait', 0.4));

        return ['tag' => $tag, 'start' => $start, 'end' => \microtime(true)];
    });

    // --- a request that fails, or ends the process, while another is served
    Route::get('boom/{kind}', function (Request $r, string $kind) {
        config(['app.tag' => 'boom']);
        app()->instance('adv.tag', 'boom');
        \ob_start();
        echo 'half a page';
        audx_wait(0.1);
        match ($kind) {
            'throw' => throw new RuntimeException('adv boom'),
            'abort' => abort(418),
            'error' => \audx_undefined_function(),
            'warning' => \trigger_error('adv warning', \E_USER_WARNING),
            'exit' => exit(0),
            'fatal' => \str_repeat('x', \PHP_INT_MAX),
        };
    });
    Route::get('tagstate', fn () => ['config' => config('app.tag'), 'bound' => app()->bound('adv.tag'), 'ob' => \ob_get_level()]);
    // error_get_last() is process-wide: a suppressed warning here, read in another request
    Route::get('suppressed', function () {
        audx_wait(0.1);
        @\file_get_contents('/nonexistent/adv');
        audx_wait(0.3);

        return 'ok';
    });
    Route::get('last-error', function () {
        \error_clear_last();
        audx_wait(0.2);

        return ['last' => \error_get_last()['message'] ?? null];
    });

    // --- a request the client leaves: a transaction, a lock, a stream
    Route::get('hold', function () {
        audx_track('hold');
        DB::beginTransaction();
        DB::table('cache')->insert(['key' => 'adv-open-hold', 'value' => 'x', 'expiration' => \time() + 60]);
        audx_wait(1);
        DB::rollBack();

        return 'held';
    });
    Route::get('lock', fn () => Cache::lock('adv-lock', 30)->get(function () {
        audx_wait(1);

        return 'locked';
    }));
    Route::get('lock-free', function () {
        $lock = Cache::lock('adv-lock', 5);
        $got = $lock->get();
        $got && $lock->release();

        return ['free' => (bool) $got];
    });
    Route::get('stream/{tag}', function (Request $r, string $tag) {
        config(['app.tag' => $tag]);
        app()->instance('adv.tag', $tag);
        audx_track("stream-$tag");

        return response()->stream(function () use ($tag) {
            for ($i = 0; $i < 3; ++$i) {
                audx_wait(0.15);
                echo \json_encode([$tag, config('app.tag'), app('adv.tag'), request('q'), \microtime(true)]) . "\n";
                \flush();
            }
        });
    });
    Route::get('forever', function () {
        @\unlink(storage_path('adv-forever-ended'));
        audx_track('forever');

        return response()->stream(function () {
            try {
                while (true) {
                    echo "tick\n";
                    \flush();
                    audx_wait(0.05);
                }
            } finally {
                \file_put_contents(storage_path('adv-forever-ended'), \microtime(true));
            }
        });
    });
    Route::get('forever-ended', fn () => \is_file(storage_path('adv-forever-ended')) ? 'yes' : 'no');
});

// With the web group: session store and cookies
Route::prefix('audx-web')->group(function () {
    Route::get('track/{tag}', function (string $tag) {
        session(['tag' => $tag]);
        audx_track($tag);

        return $tag;
    });
    // A typical request: session, a query, a view, an event, the cache
    Route::get('work', function () {
        session(['n' => (session('n') ?? 0) + 1]);
        Event::dispatch('adv.work');
        Cache::put('adv-work', \str_repeat('x', 100), 60);

        return Blade::render('{{ $n }}', ['n' => DB::table('cache')->count()]);
    });
});
