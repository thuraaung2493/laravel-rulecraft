<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Thuraaung\Rulecraft\GroupHierarchy;

/**
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property string $match
 * @property int|null $parent_id
 * @property-read Collection<int, Rule> $rules
 * @property-read Collection<int, RuleGroup> $groups
 * @property-read RuleGroup|null $parent
 */
class RuleGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'match', 'parent_id', 'enabled'];

    /** @var array<string, mixed> */
    protected $attributes = ['match' => 'all', 'enabled' => true];

    /** @var array<string, string> */
    protected $casts = ['parent_id' => 'integer', 'enabled' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (RuleGroup $group) {
            if (! $group->exists || $group->isDirty('parent_id')) {
                app(GroupHierarchy::class)->validate($group);
            }
        });
    }

    public function getTable(): string
    {
        return config('rulecraft.tables.rule_groups');
    }

    /** @return BelongsTo<RuleGroup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<RuleGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /** @return HasMany<Rule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class, 'rule_group_id')
            ->orderByDesc('priority')->orderBy('id');
    }

    /** @return HasMany<RolePermissionRule, $this> */
    public function permissionRules(): HasMany
    {
        return $this->hasMany(RolePermissionRule::class, 'rule_group_id');
    }

    /**
     * @param  mixed  $value
     */
    public function setMatchAttribute($value): void
    {
        if (! in_array($value, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Group match must be all or any.');
        }

        $this->attributes['match'] = $value;
    }
}
