<?php

namespace Swerve\Laravel;

use Carbon\Translator;

/**
 * Carbon's translator, whose locale is the current request's: Laravel sets Carbon's locale, which
 * is process-wide, at each request and on App::setLocale().
 *
 * @internal
 */
final class CarbonTranslator extends Translator
{
    /** @var \WeakMap<\Scope, string>|null the locale of each request's scope */
    private static ?\WeakMap $locales = null;

    public function getLocale(): string
    {
        $scope = Current::scope();

        return (null === $scope ? null : self::$locales[$scope] ?? null) ?? parent::getLocale();
    }

    public function setLocale($locale): void
    {
        parent::setLocale($locale);
        if ($scope = Current::scope()) {
            self::$locales ??= new \WeakMap();
            self::$locales[$scope] = parent::getLocale();
        }
    }
}
