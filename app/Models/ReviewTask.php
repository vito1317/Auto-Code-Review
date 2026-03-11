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
        'pr_status',
        'ai_merge_status',
        'ai_merge_message',
        'status',
        'jules_session_id',
        'jules_fix_pr_url',
        'review_summary',
        'ai_raw_output',
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

    const PR_STATUS_OPEN = 'open';

    const PR_STATUS_CLOSED = 'closed';

    const PR_STATUS_MERGED = 'merged';

    const AI_MERGE_PENDING = 'pending';

    const AI_MERGE_PROCESSING = 'processing';

    const AI_MERGE_RESOLVED = 'resolved';

    const AI_MERGE_FAILED = 'failed';

    /**
     * Scope: only the latest iteration of each PR per repository.
     */
    public function scopeLatestIteration($query)
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')
                ->from('review_tasks')
                ->groupBy('repository_id', 'pr_number');
        });
    }

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
