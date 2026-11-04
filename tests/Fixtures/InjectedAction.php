<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use Thuraaung\Rulecraft\Actions\ActionResult;
use Thuraaung\Rulecraft\Contracts\Action;

class InjectedAction implements Action
{
    /** @var RecordingAction */
    private $recorder;

    public function __construct(RecordingAction $recorder)
    {
        $this->recorder = $recorder;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $context
     */
    public function handle(array $parameters, array $context): ActionResult
    {
        return $this->recorder->handle($parameters, $context);
    }
}
