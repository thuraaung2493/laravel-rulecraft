<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Contracts;

use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

interface BuilderExpressionProvider extends ExpressionFunctionProviderInterface
{
    /** @return array<array-key, mixed> */
    public function getBuilderFunctions(): array;
}
