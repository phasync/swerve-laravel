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
    private const KEY = 'swerve.laravel.carbon-locale';

    public function getLocale(): string
    {
        return Current::context()[self::KEY] ?? parent::getLocale();
    }

    public function setLocale($locale): void
    {
        parent::setLocale($locale);
        if ($context = Current::context()) {
            $context[self::KEY] = parent::getLocale();
        }
    }
}
