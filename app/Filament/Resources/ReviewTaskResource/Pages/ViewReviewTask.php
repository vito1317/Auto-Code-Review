<?php

namespace App\Filament\Resources\ReviewTaskResource\Pages;

use App\Filament\Resources\ReviewTaskResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReviewTask extends ViewRecord
{
    protected static string $resource = ReviewTaskResource::class;

    // Auto-refresh every 10 seconds for live status updates
    protected ?string $polling = '10s';

    public function getRelationManagers(): array
    {
        return [
            ReviewTaskResource\RelationManagers\CommentsRelationManager::class,
        ];
    }

    // Livewire polling via wire:poll
    protected function getExtraAttributes(): array
    {
        return [
            'wire:poll.10s' => '',
        ];
    }
}
