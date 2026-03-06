<?php

namespace App\Filament\Resources\ReviewTaskResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';
    protected static ?string $title = 'Review Findings';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        'suggestion' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('file_path')
                    ->label('File')
                    ->limit(40)
                    ->tooltip(fn($record) => $record->file_path),

                Tables\Columns\TextColumn::make('line_number')
                    ->label('Line')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Finding')
                    ->limit(80)
                    ->wrap()
                    ->markdown(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('severity')
                    ->options([
                        'critical' => 'Critical',
                        'warning' => 'Warning',
                        'suggestion' => 'Suggestion',
                        'info' => 'Info',
                    ]),
            ])
            ->defaultSort('severity', 'asc');
    }
}
