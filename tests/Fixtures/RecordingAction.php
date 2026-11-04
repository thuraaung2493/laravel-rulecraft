<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use RuntimeException;
use Thuraaung\Rulecraft\Actions\ActionResult;
use Thuraaung\Rulecraft\Contracts\Action;

class RecordingAction implements Action
{
    /**
     * @var array<int, array{parameters: array<array-key, mixed>, context: array<array-key, mixed>}>
     */
    public $calls = [];

    /**
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $context
     */
    public function handle(array $parameters, array $context): ActionResult
    {
        $this->calls[] = ['parameters' => $parameters, 'context' => $context];

        if (isset($parameters['throw'])) {
            throw new RuntimeException($parameters['throw']);
        }

        if (isset($parameters['failure'])) {
            return ActionResult::failure($parameters['failure']);
        }

        return ActionResult::success($parameters['output'] ?? []);
    }
}
