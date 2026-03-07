<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewComment extends Model
{
    protected $fillable = [
        'review_task_id',
        'file_path',
        'line_number',
        'severity',
        'category',
        'body',
        'github_comment_id',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'github_comment_id' => 'integer',
    ];

    const SEVERITY_CRITICAL = 'critical';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_SUGGESTION = 'suggestion';
    const SEVERITY_INFO = 'info';

    public function reviewTask(): BelongsTo
    {
        return $this->belongsTo(ReviewTask::class);
    }

    /**
     * Get severity badge color for Filament.
     */
    public function getSeverityColor(): string
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 'danger',
            self::SEVERITY_WARNING => 'warning',
            self::SEVERITY_SUGGESTION => 'info',
            default => 'gray',
        };
    }
}
