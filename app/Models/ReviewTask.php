<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewTask extends Model
{
    protected $fillable = [
        'repository_id',
        'pr_number',
        'pr_title',
        'pr_url',
        'pr_author',
        'status',
        'jules_session_id',
        'jules_fix_pr_url',
        'review_summary',
        'diff_content',
        'iteration',
        'error_message',
    ];

    protected $casts = [
        'pr_number' => 'integer',
        'iteration' => 'integer',
    ];

    /**
     * Possible statuses for a review task.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_REVIEWING = 'reviewing';
    const STATUS_COMMENTED = 'commented';
    const STATUS_FIXING = 'fixing';
    const STATUS_FIXED = 'fixed';
    const STATUS_APPROVED = 'approved';
    const STATUS_FAILED = 'failed';

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }

    /**
     * Check if this task has critical issues requiring a fix.
     */
    public function hasCriticalIssues(): bool
    {
        return $this->comments()
            ->where('severity', 'critical')
            ->exists();
    }

    /**
     * Get counts by severity.
     */
    public function getSeverityCounts(): array
    {
        return $this->comments()
            ->selectRaw('severity, count(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();
    }

    /**
     * Scope for active (non-terminal) tasks.
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Scope for tasks waiting on Jules.
     */
    public function scopeWaitingOnJules($query)
    {
        return $query->where('status', self::STATUS_FIXING)
            ->whereNotNull('jules_session_id');
    }
}
