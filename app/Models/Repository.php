<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    protected $fillable = [
        'name',
        'owner',
        'repo',
        'jules_source',
        'default_branch',
        'webhook_secret',
        'is_active',
        'review_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'review_config' => 'array',
    ];

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class);
    }

    /**
     * Get review tasks that resulted in auto-fix PRs.
     */
    public function fixedTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class)
            ->where('status', 'fixed')
            ->whereNotNull('jules_fix_pr_url');
    }

    /**
     * Get the Jules source identifier for this repository.
     */
    public function getJulesSourceIdentifier(): string
    {
        return $this->jules_source ?? "sources/github/{$this->owner}/{$this->repo}";
    }

    /**
     * Get the full GitHub repository name (owner/repo).
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->owner}/{$this->repo}";
    }
}
