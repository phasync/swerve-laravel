<?php

namespace Swerve\Laravel;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Pagination\PaginationState;
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
    public const KEY = 'swerve.laravel.app';

    /** For code outside any request: the worker's first application. */
    public static Application $fallback;

    private static ?Application $proxy = null;

    public static function app(): Application
    {
        return self::context()[self::KEY] ?? self::$fallback;
    }

    /** The current request's coroutine context; null outside coroutines. */
    public static function context(): ?\ArrayAccess
    {
        try {
            return null === \Fiber::getCurrent() ? null : \phasync::getContext();
        } catch (\LogicException) {
            return null;
        }
    }

    /** Point Laravel's process-wide pointers at the proxies; again after each application boots. */
    public static function install(): Application
    {
        $proxy = self::$proxy ??= self::makeProxy();
        Container::setInstance($proxy);
        \Closure::bind(static function () use ($proxy) {
            Facade::$app              = $proxy;
            Facade::$cached           = false; // a facade's instance is the current application's
            Facade::$resolvedInstance = [];
        }, null, Facade::class)();
        \Closure::bind(static fn () => HandleExceptions::$app = $proxy, null, HandleExceptions::class)();
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
        // Blade components keep the view factory they first met
        \Closure::bind(static fn () => Component::$factory = new class {
            public function __call($method, $args)
            {
                return Current::app()['view']->$method(...$args);
            }
        }, null, Component::class)();

        return $proxy;
    }

    /** An Application whose every public method runs on Current::app(). */
    private static function makeProxy(): Application
    {
        $methods = '';
        foreach ((new \ReflectionClass(Application::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
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

        return eval("return new class() extends \\Illuminate\\Foundation\\Application {\npublic function __construct() {}\n$methods};");
    }
}
