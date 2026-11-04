<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityCategory extends Model
{
    use Auditable;
    use IsLookup;

    protected $fillable = ['name', 'requires_detail', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_detail' => 'boolean'];
    }

    /** The name as offered in a list: "Other (specify)" for the category that asks for words. */
    public function optionLabel(): string
    {
        return $this->requires_detail ? $this->name.' (specify)' : $this->name;
    }

    /** Alphabetical, with the category that asks for words ("Other (specify)") last. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('requires_detail')->orderBy('name');
    }

    /** @return HasMany<ActivityType, $this> */
    public function types(): HasMany
    {
        return $this->hasMany(ActivityType::class)->orderBy('name');
    }
}
