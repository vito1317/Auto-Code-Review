<?php

namespace App\Filament\Widgets;

use App\Models\ReviewTask;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentReviewsWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ReviewTask::query()
                    ->with('repository')
                    ->latest()
            )
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('repository.name')
                    ->label('Repo'),

                Tables\Columns\TextColumn::make('pr_number')
                    ->label('PR')
                    ->formatStateUsing(fn($state) => "#{$state}")
                    ->url(fn($record) => $record->pr_url)
                    ->openUrlInNewTab()
                    ->color('info'),

                Tables\Columns\TextColumn::make('pr_title')
                    ->label('Title')
                    ->limit(40),

                Tables\Columns\TextColumn::make('pr_author')
                    ->label('Author')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'pending' => 'gray',
                        'reviewing' => 'info',
                        'commented' => 'warning',
                        'fixing' => 'info',
                        'fixed' => 'success',
                        'approved' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->since(),
            ])
            ->paginated(false);
    }
}
