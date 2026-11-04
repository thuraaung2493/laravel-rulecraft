<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

class CustomerFunctions implements ExpressionFunctionProviderInterface
{
    /** @var int */
    public static $calls = 0;

    /** @return list<ExpressionFunction> */
    public function getFunctions(): array
    {
        return [new ExpressionFunction('eligible', function ($value) {
            return '('.$value.' >= 650)';
        }, function (array $variables, $value) {
            self::$calls++;

            return $value >= 650;
        })];
    }
}
