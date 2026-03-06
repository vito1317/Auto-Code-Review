<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReviewTaskResource\Pages;
use App\Models\ReviewTask;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReviewTaskResource extends Resource
{
    protected static ?string $model = ReviewTask::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Review Tasks';
    protected static ?string $navigationGroup = 'Reviews';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('PR Information')
                ->schema([
                    Forms\Components\TextInput::make('pr_title')->disabled(),
                    Forms\Components\TextInput::make('pr_url')->disabled()->url(),
                    Forms\Components\TextInput::make('pr_author')->disabled(),
                    Forms\Components\TextInput::make('pr_number')->disabled(),
                ]),
            Forms\Components\Section::make('Review Status')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'reviewing' => 'Reviewing',
                            'commented' => 'Commented',
                            'fixing' => 'Fixing (Jules)',
                            'fixed' => 'Fixed',
                            'approved' => 'Approved',
                            'failed' => 'Failed',
                        ]),
                    Forms\Components\TextInput::make('jules_session_id')->disabled(),
                    Forms\Components\TextInput::make('jules_fix_pr_url')->disabled()->url(),
                    Forms\Components\TextInput::make('iteration')->disabled(),
                    Forms\Components\Textarea::make('review_summary')->disabled()->rows(3),
                    Forms\Components\Textarea::make('error_message')->disabled()->rows(2),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Pull Request')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('pr_title')
                        ->label('Title')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('pr_url')
                        ->label('URL')
                        ->url(fn($record) => $record->pr_url)
                        ->openUrlInNewTab()
                        ->badge()
                        ->color('info'),
                    Infolists\Components\TextEntry::make('pr_author')
                        ->label('Author')
                        ->badge(),
                    Infolists\Components\TextEntry::make('pr_number')
                        ->label('PR #'),
                    Infolists\Components\TextEntry::make('repository.full_name')
                        ->label('Repository')
                        ->badge()
                        ->color('gray'),
                ]),

            Infolists\Components\Section::make('Review Status')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('status')
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
                    Infolists\Components\TextEntry::make('iteration')
                        ->label('Iteration #'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Started')
                        ->dateTime(),
                ]),

            Infolists\Components\Section::make('Review Summary')
                ->schema([
                    Infolists\Components\TextEntry::make('review_summary')
                        ->label('')
                        ->markdown()
                        ->columnSpanFull(),
                ])
                ->visible(fn($record) => $record->review_summary),

            Infolists\Components\Section::make('Jules Auto-Fix')
                ->schema([
                    Infolists\Components\TextEntry::make('jules_session_id')
                        ->label('Session ID'),
                    Infolists\Components\TextEntry::make('jules_fix_pr_url')
                        ->label('Fix PR')
                        ->url(fn($record) => $record->jules_fix_pr_url)
                        ->openUrlInNewTab()
                        ->badge()
                        ->color('success')
                        ->visible(fn($record) => $record->jules_fix_pr_url),
                ])
                ->visible(fn($record) => $record->jules_session_id),

            Infolists\Components\Section::make('Error')
                ->schema([
                    Infolists\Components\TextEntry::make('error_message')
                        ->label('')
                        ->color('danger'),
                ])
                ->visible(fn($record) => $record->error_message),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('repository.name')
                    ->label('Repo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pr_number')
                    ->label('PR #')
                    ->formatStateUsing(fn($state, $record) => "#{$state}")
                    ->url(fn($record) => $record->pr_url)
                    ->openUrlInNewTab()
                    ->color('info'),

                Tables\Columns\TextColumn::make('pr_title')
                    ->label('Title')
                    ->limit(50)
                    ->searchable(),

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

                Tables\Columns\TextColumn::make('iteration')
                    ->label('Iter.')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('comments_count')
                    ->label('Issues')
                    ->counts('comments')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'reviewing' => 'Reviewing',
                        'commented' => 'Commented',
                        'fixing' => 'Fixing',
                        'fixed' => 'Fixed',
                        'approved' => 'Approved',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('repository')
                    ->relationship('repository', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->label('Retry Review')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn(ReviewTask $record) => in_array($record->status, ['failed', 'commented']))
                    ->action(function (ReviewTask $record) {
                        $record->update([
                            'status' => ReviewTask::STATUS_PENDING,
                            'error_message' => null,
                            'iteration' => $record->iteration + 1,
                        ]);
                        \App\Jobs\ReviewPrJob::dispatch($record);
                    }),
                Tables\Actions\Action::make('view_github')
                    ->label('View PR')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn(ReviewTask $record) => $record->pr_url)
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviewTasks::route('/'),
            'view' => Pages\ViewReviewTask::route('/{record}'),
        ];
    }
}
