<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Thuraaung\Rulecraft\GroupEvaluation group(string $name)
 * @method static \Thuraaung\Rulecraft\Permissions\PermissionEvaluation for(mixed $user)
 * @method static string export(string $group)
 * @method static \Thuraaung\Rulecraft\Models\RuleGroup import(string $json)
 * @method static void registerAction(string $name, string $class)
 * @method static void validateExpression(string $expression, array<int, string> $variables = [])
 *
 * @see \Thuraaung\Rulecraft\Rulecraft
 */
class Rulecraft extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Thuraaung\Rulecraft\Rulecraft::class;
    }
}
