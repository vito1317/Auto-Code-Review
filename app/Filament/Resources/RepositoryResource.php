<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryResource\Pages;
use App\Models\Repository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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

            Forms\Components\Section::make('Webhook')
                ->description('Configure GitHub webhook integration')
                ->schema([
                    Forms\Components\TextInput::make('webhook_secret')
                        ->label('Webhook Secret')
                        ->password()
                        ->revealable()
                        ->default(fn() => \Illuminate\Support\Str::random(40))
                        ->helperText('Auto-generated. Copy this value to your GitHub webhook settings.')
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('regenerate')
                                ->icon('heroicon-o-arrow-path')
                                ->action(function (Forms\Set $set) {
                                    $set('webhook_secret', \Illuminate\Support\Str::random(40));
                                })
                        ),

                    Forms\Components\Placeholder::make('webhook_url')
                        ->label('Webhook URL')
                        ->content(fn() => url('/api/webhooks/github'))
                        ->helperText('Use this URL in your GitHub repository webhook settings'),
                ]),

            Forms\Components\Section::make('Settings')
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Enable/disable automatic PR reviews for this repo'),

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

                Tables\Columns\TextColumn::make('review_tasks_count')
                    ->label('Reviews')
                    ->counts('reviewTasks')
                    ->sortable(),

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
                    ->label(fn(Repository $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn(Repository $record) => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn(Repository $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn(Repository $record) => $record->update(['is_active' => !$record->is_active])),
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
