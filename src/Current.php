<?php

namespace Swerve\Laravel;

use Carbon\Carbon;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Pagination\PaginationState;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;

/**
 * The Laravel application of the request running in the current coroutine.
 *
 * Laravel reaches its application through process-wide pointers: Container::getInstance() (app(),
 * the helpers), Facade::$app (the facades), Model's connection resolver and event dispatcher, the
 * paginator's resolvers, and HandleExceptions. With several requests in one worker, each served by
 * an application of its own, these pointers are proxies that forward to the application stored in
 * the request's coroutine context (phasync::getContext()), inherited by coroutines it starts.
 *
 * @internal
 */
final class Current
{
    private static ?Application $proxy = null;

    /** @var \WeakMap<object, Application>|null the application of each request's context */
    private static ?\WeakMap $apps = null;

    /** @var array<class-string, array{string, \Closure}> static callback lists that providers append to, with the one callback each keeps */
    private static ?array $forwards = null;

    /** @throws \LogicException outside a request and Handler::run() */
    public static function app(): Application
    {
        $context = self::context();

        return (null === $context ? null : self::$apps[$context] ?? null) ?? throw new \LogicException('Laravel was used outside a request: wrap the code in Swerve\Laravel\Handler::run()');
    }

    /** Make $app the application of the current context, which is the request's own. */
    public static function set(Application $app): void
    {
        self::$apps ??= new \WeakMap();
        self::$apps[\phasync::getContext()] = $app;
    }

    public static function unset(): void
    {
        unset(self::$apps[\phasync::getContext()]);
    }

    /** The current coroutine's context, which a request and the coroutines it starts share; null outside coroutines. */
    public static function context(): ?object
    {
        try {
            return null === \Fiber::getCurrent() ? null : \phasync::getContext();
        } catch (\LogicException) {
            return null;
        }
    }

    /**
     * Point Laravel's process-wide pointers at the proxies; again after each application
     * bootstraps, as that points them at itself. The proxy extends the class of the first
     * $app, the application's own (Application, or a subclass of it).
     */
    public static function install(Application $app): Application
    {
        $proxy = self::$proxy ??= self::makeProxy($app::class);
        Container::setInstance($proxy);
        \Closure::bind(static function () use ($proxy) {
            Facade::$app              = $proxy;
            Facade::$cached           = false; // a facade's instance is the current application's
            Facade::$resolvedInstance = [];
        }, null, Facade::class)();
        \Closure::bind(static fn () => HandleExceptions::$app = $proxy, null, HandleExceptions::class)();
        // A model whose boot threw (Eloquent used outside a request) stays "being booted" for the
        // process, and every later request would fail on it (Laravel 13; 12 has no such list)
        \property_exists(Model::class, 'booting') && \Closure::bind(static fn () => Model::$booting = [], null, Model::class)();
        Model::setConnectionResolver(new class implements ConnectionResolverInterface {
            public function connection($name = null)
            {
                return Current::app()['db']->connection($name);
            }

            public function getDefaultConnection()
            {
                return Current::app()['db']->getDefaultConnection();
            }

            public function setDefaultConnection($name)
            {
                Current::app()['db']->setDefaultConnection($name);
            }
        });
        Model::setEventDispatcher(new class implements Dispatcher {
            public function __call($method, $args)
            {
                return Current::app()['events']->$method(...$args);
            }

            public function listen($events, $listener = null)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function hasListeners($eventName)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function subscribe($subscriber)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function until($event, $payload = [])
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function dispatch($event, $payload = [], $halt = false)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function push($event, $payload = [])
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function flush($event)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function forget($event)
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }

            public function forgetPushed()
            {
                return $this->__call(__FUNCTION__, \func_get_args());
            }
        });
        PaginationState::resolveUsing($proxy);
        // Carbon's locale is process-wide: each request's own, in its context
        if (!Carbon::getTranslator() instanceof CarbonTranslator) {
            Carbon::setTranslator(new CarbonTranslator(Carbon::getLocale()));
        }
        // Blade components keep the view factory they first met, and cache the names of the views
        // they compiled from strings: Laravel registers their namespace once per process, not per
        // application
        self::forwardCallbacks($app);
        $app['view']->addNamespace('__components', $app['config']->get('view.compiled'));
        \Closure::bind(static fn () => Component::$factory = new class {
            public function __call($method, $args)
            {
                return Current::app()['view']->$method(...$args);
            }
        }, null, Component::class)();

        return $proxy;
    }

    /**
     * Providers append callbacks bound to their application to static lists (job payload hooks,
     * Artisan's starting() callbacks), which would grow by an application per request and run
     * the callbacks of the others. Each application keeps its own; the lists hold one callback
     * that runs the current application's.
     */
    private static function forwardCallbacks(Application $app): void
    {
        self::$forwards ??= [
            Queue::class => ['createPayloadCallbacks', static function ($connection, $queue, $payload) {
                foreach (self::app()->make('swerve-laravel.callbacks')[Queue::class] as $callback) {
                    $payload = \array_merge($payload, $callback($connection, $queue, $payload));
                }

                return $payload;
            }],
            ConsoleApplication::class => ['bootstrappers', static function ($artisan) {
                foreach (self::app()->make('swerve-laravel.callbacks')[ConsoleApplication::class] as $callback) {
                    $callback($artisan);
                }
            }],
        ];
        $own = [];
        foreach (self::$forwards as $class => [$property, $forward]) {
            $own[$class] = \Closure::bind(static function () use ($property, $forward) {
                $own               = \array_values(\array_filter(static::$$property, static fn ($callback) => $callback !== $forward));
                static::$$property = [$forward];

                return $own;
            }, null, $class)();
        }
        $app->instance('swerve-laravel.callbacks', $own);
    }

    /** A subclass of $class whose every public method runs on Current::app(). */
    private static function makeProxy(string $class): Application
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->isFinal()) {
            throw new \LogicException("$class is final: swerve-laravel extends the application's class, so that app() is an instance of it");
        }
        $methods = '';
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isFinal()) {
                continue;
            }
            $name = $method->getName();
            $type = $method->getReturnType();
            $call = "\\Swerve\\Laravel\\Current::app()->$name(...\\func_get_args())";
            $body = match ((string) $type) {
                'void', 'never'  => "$call;",
                'static', 'self' => "\$r = $call; return \$r === \\Swerve\\Laravel\\Current::app() ? \$this : \$r;",
                default          => "return $call;",
            };
            // Magic methods take exactly their parameters; the others any
            $params  = \str_starts_with($name, '__') ? \implode(', ', \array_map(static fn ($p) => '$' . $p->getName(), $method->getParameters())) : '...$args';
            $methods .= "public function $name($params)" . (null === $type ? '' : ': ' . $type) . " { $body }\n";
        }

        return eval("return new class() extends \\$class {\npublic function __construct() {}\n$methods};");
    }
}
