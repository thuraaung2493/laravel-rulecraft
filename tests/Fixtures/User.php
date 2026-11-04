<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<int, string> */
    protected $guard_name = ['web', 'api'];

    /** @var bool */
    public $timestamps = false;
}
