<?php

namespace Swerve\Laravel;

/**
 * State of each pooled application, swapped in for the request the application serves.
 *
 * AppState::$state is the running request's state: the array of the application serving it,
 * bound by reference whenever a coroutine of that request starts or resumes (by the request's
 * Scope, from a phasync::onScopeChange() hook). A key added to AppState::$defaults is a slot every
 * application's array has, with that start value; existing arrays take it when their application
 * next serves a request. A scope no application serves (outside a request, Handler::run()) has a
 * state of its own, made from the defaults when it is first entered.
 *
 * When classes have added slots by the time the first application booted, the handler leaves
 * Laravel's pointers to the application (app(), the facades, ...) to them instead of proxying them.
 */
final class AppState
{
    /** @var array<string, mixed> the running request's state, by reference */
    public static array $state = [];

    /** @var array<string, mixed> the slots of every state, with their start values */
    public static array $defaults = [];

    /** @var \WeakMap<\Scope, self>|null the state of each scope */
    private static ?\WeakMap $scopes = null;

    /** @var array<string, mixed> */
    private array $values;

    /** How many slots of AppState::$defaults this state has had a chance to take */
    private int $seen;

    public function __construct()
    {
        $this->values = self::$defaults;
        $this->seen   = \count($this->values);
    }

    /** Bind AppState::$state to the state of $scope: the onScopeChange() hook. */
    public static function enter(\Scope $scope): void
    {
        self::$scopes ??= new \WeakMap();
        $state       = self::$scopes[$scope] ??= new self();
        self::$state = &$state->values;
    }

    /** Make this the state of the current scope, and AppState::$state now, with the slots added since it last was. */
    public function adopt(): void
    {
        if (\count(self::$defaults) !== $this->seen) {
            $this->values += self::$defaults;
            $this->seen   = \count(self::$defaults);
        }
        self::$scopes ??= new \WeakMap();
        self::$scopes[\Scope::getCurrent()] = $this;
        self::$state                        = &$this->values;
    }

    /**
     * The current scope goes on with a copy of this state, as another request may take the
     * application now: a WebSocket callback keeps what its request left.
     */
    public function leave(): void
    {
        $copy         = new self();
        $copy->values = $this->values;
        $copy->seen   = $this->seen;
        $copy->adopt();
    }
}
