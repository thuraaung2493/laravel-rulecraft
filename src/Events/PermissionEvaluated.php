<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Events;

use Thuraaung\Rulecraft\Results\PermissionResult;

class PermissionEvaluated
{
    /** @var mixed */
    public $user;

    /** @var string */
    public $permission;

    /** @var string|null */
    public $guard;

    /** @var PermissionResult */
    public $result;

    /** @var array<array-key, mixed> */
    public $context;

    /**
     * @param  mixed  $user
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        $user,
        string $permission,
        ?string $guard,
        PermissionResult $result,
        array $context
    ) {
        $this->user = $user;
        $this->permission = $permission;
        $this->guard = $guard;
        $this->result = $result;
        $this->context = $context;
    }
}
