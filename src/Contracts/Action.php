<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Contracts;

use Thuraaung\Rulecraft\Actions\ActionResult;

interface Action
{
    /**
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $context
     */
    public function handle(array $parameters, array $context): ActionResult;
}
