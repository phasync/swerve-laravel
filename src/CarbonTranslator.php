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
    /** @var \WeakMap<object, string>|null the locale of each request's context */
    private static ?\WeakMap $locales = null;

    public function getLocale(): string
    {
        $context = Current::context();

        return (null === $context ? null : self::$locales[$context] ?? null) ?? parent::getLocale();
    }

    public function setLocale($locale): void
    {
        parent::setLocale($locale);
        if ($context = Current::context()) {
            self::$locales ??= new \WeakMap();
            self::$locales[$context] = parent::getLocale();
        }
    }
}
