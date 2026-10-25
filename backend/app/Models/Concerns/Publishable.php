<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Items with `published_at`: null = draft, future = scheduled. Students only see
 * published items; lecturers of the course see everything.
 */
trait Publishable
{
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('published_at'))->where($this->qualifyColumn('published_at'), '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lessThanOrEqualTo(now());
    }
}
