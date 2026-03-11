<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'owner',
        'repo',
        'jules_source',
        'default_branch',
        'webhook_secret',
        'is_active',
        'auto_merge',
        'auto_ai_merge',
        'review_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_merge' => 'boolean',
        'auto_ai_merge' => 'boolean',
        'review_config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class);
    }

    /**
     * Get review tasks that triggered Jules auto-fix.
     */
    public function fixedTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class)
            ->whereNotNull('jules_session_id');
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
