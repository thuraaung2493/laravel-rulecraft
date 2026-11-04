<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;

/**
 * @property int $id
 * @property string $role_type
 * @property string $role_id
 * @property string $permission_type
 * @property string $permission_id
 * @property int $rule_group_id
 * @property-read RuleGroup|null $group
 * @property-read Model|null $role
 * @property-read Model|null $permission
 */
class RolePermissionRule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'role_type', 'role_id', 'permission_type', 'permission_id', 'rule_group_id',
    ];

    public function getTable(): string
    {
        return config('rulecraft.tables.role_permission_rules');
    }

    /** @return BelongsTo<RuleGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RuleGroup::class, 'rule_group_id');
    }

    /** @return MorphTo<Model, $this> */
    public function role(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function permission(): MorphTo
    {
        return $this->morphTo();
    }

    public static function assign(Model $role, Model $permission, RuleGroup $group): self
    {
        foreach ([$role, $permission, $group] as $model) {
            if (! $model->exists || $model->getKey() === null) {
                throw new InvalidArgumentException(
                    'Save the role, permission and group before assigning conditions.'
                );
            }
        }

        if ($group->parent_id !== null) {
            throw new InvalidGroupHierarchyException(
                'Conditional permissions must reference a root group.'
            );
        }

        return static::query()->updateOrCreate([
            'role_type' => $role->getMorphClass(),
            'role_id' => (string) $role->getKey(),
            'permission_type' => $permission->getMorphClass(),
            'permission_id' => (string) $permission->getKey(),
        ], ['rule_group_id' => $group->getKey()]);
    }
}
