<?php

namespace App\Filament\Resources\ReviewTaskResource\Pages;

use App\Filament\Resources\ReviewTaskResource;
use App\Models\ReviewComment;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;

class ViewReviewTask extends ViewRecord
{
    protected static string $resource = ReviewTaskResource::class;

    public function getRelationManagers(): array
    {
        return [
            ReviewTaskResource\RelationManagers\CommentsRelationManager::class,
        ];
    }
}
