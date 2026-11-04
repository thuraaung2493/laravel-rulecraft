<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Thuraaung\Rulecraft\Facades\Rulecraft;

class ActionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Rulecraft::registerAction('module_record', InjectedAction::class);
    }
}
