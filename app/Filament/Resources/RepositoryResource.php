<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryResource\Pages;
use App\Models\Repository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Repository Info')
                ->description('GitHub repository details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Display Name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('My Project'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('owner')
                            ->label('GitHub Owner')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('username or org'),

                        Forms\Components\TextInput::make('repo')
                            ->label('Repository Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('my-repo'),
                    ]),

                    Forms\Components\TextInput::make('default_branch')
                        ->label('Default Branch')
                        ->default('main')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('jules_source')
                        ->label('Jules Source (auto-generated if blank)')
                        ->placeholder('sources/github/owner/repo')
                        ->helperText('Leave blank to auto-generate from owner/repo')
                        ->maxLength(500),
                ]),

            Forms\Components\Section::make('Settings')
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Enable/disable automatic PR reviews for this repo'),

                    Forms\Components\Toggle::make('auto_merge')
                        ->label('Auto-Merge')
                        ->default(false)
                        ->helperText('When enabled, PRs that pass AI review (approved) will be automatically merged'),

                    Forms\Components\Toggle::make('auto_ai_merge')
                        ->label('AI Merge on Conflict')
                        ->default(false)
                        ->helperText('When auto-merge fails due to conflicts, automatically use AI to resolve and retry'),

                    Forms\Components\KeyValue::make('review_config')
                        ->label('Review Configuration')
                        ->helperText('Custom key-value config passed to the AI reviewer')
                        ->addActionLabel('Add Config'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                if (! auth()->user()->isAdmin()) {
                    $query->where('user_id', auth()->id());
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Repository')
                    ->badge()
                    ->color('info')
                    ->searchable(['owner', 'repo']),

                Tables\Columns\TextColumn::make('default_branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\IconColumn::make('auto_merge')
                    ->label('Auto-Merge')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path-rounded-square')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('auto_ai_merge')
                    ->label('AI Merge')
                    ->boolean()
                    ->trueIcon('heroicon-o-sparkles')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('info')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('review_tasks_count')
                    ->label('Reviews')
                    ->counts('reviewTasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fixed_tasks_count')
                    ->label('Auto PRs')
                    ->counts('fixedTasks')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (Repository $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (Repository $record) => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (Repository $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (Repository $record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRepositories::route('/'),
            'create' => Pages\CreateRepository::route('/create'),
            'edit' => Pages\EditRepository::route('/{record}/edit'),
        ];
    }
}
