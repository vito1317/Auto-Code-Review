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
use Illuminate\Database\Eloquent\Builder;

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
                        ->url(fn ($record) => $record->pr_url)
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
                        ->color(fn (string $state) => match ($state) {
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
                ->visible(fn ($record) => $record->review_summary),

            Infolists\Components\Section::make('Jules Auto-Fix')
                ->schema([
                    Infolists\Components\TextEntry::make('jules_session_id')
                        ->label('Session ID'),
                    Infolists\Components\TextEntry::make('jules_fix_pr_url')
                        ->label('Fix PR')
                        ->url(fn ($record) => $record->jules_fix_pr_url)
                        ->openUrlInNewTab()
                        ->badge()
                        ->color('success')
                        ->visible(fn ($record) => $record->jules_fix_pr_url),
                ])
                ->visible(fn ($record) => $record->jules_session_id),

            Infolists\Components\Section::make('AI Merge')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('ai_merge_status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (?string $state) => match ($state) {
                            'pending' => 'warning',
                            'processing' => 'info',
                            'resolved' => 'success',
                            'failed' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (?string $state) => match ($state) {
                            'pending' => '⏳ Pending',
                            'processing' => '🔄 Processing',
                            'resolved' => '✅ Resolved',
                            'failed' => '❌ Failed',
                            default => '—',
                        }),
                    Infolists\Components\TextEntry::make('ai_merge_message')
                        ->label('Message')
                        ->placeholder('—'),
                ])
                ->visible(fn ($record) => $record->ai_merge_status),

            Infolists\Components\Section::make('Merge')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('merge_status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (?string $state) => match ($state) {
                            'queued' => 'warning',
                            'merging' => 'info',
                            'merged' => 'success',
                            'failed' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (?string $state) => match ($state) {
                            'queued' => '⏳ Queued',
                            'merging' => '🔄 Merging',
                            'merged' => '✅ Merged',
                            'failed' => '❌ Failed',
                            default => '—',
                        }),
                    Infolists\Components\TextEntry::make('merge_message')
                        ->label('Message')
                        ->placeholder('—'),
                ])
                ->visible(fn ($record) => $record->merge_status),

            Infolists\Components\Section::make('Error')
                ->schema([
                    Infolists\Components\TextEntry::make('error_message')
                        ->label('')
                        ->color('danger'),
                ])
                ->visible(fn ($record) => $record->error_message),

            Infolists\Components\Section::make('Review Findings')
                ->description(fn ($record) => $record->comments->count().' issue(s) found')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('comments')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('severity')
                                ->badge()
                                ->color(fn (string $state) => match ($state) {
                                    'critical' => 'danger',
                                    'warning' => 'warning',
                                    'suggestion' => 'info',
                                    default => 'gray',
                                }),
                            Infolists\Components\TextEntry::make('file_path')
                                ->label('File')
                                ->icon('heroicon-o-document-text')
                                ->formatStateUsing(fn (string $state) => basename($state))
                                ->tooltip(fn (string $state) => $state)
                                ->columnSpan(2),
                            Infolists\Components\TextEntry::make('line_number')
                                ->label('Line')
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('category')
                                ->badge()
                                ->color('gray'),
                            Infolists\Components\TextEntry::make('body')
                                ->label('Details')
                                ->markdown()
                                ->columnSpanFull(),
                        ])
                        ->columns(5),
                ])
                ->visible(fn ($record) => $record->comments->count() > 0)
                ->collapsible(),

            Infolists\Components\Section::make('Diff Content')
                ->schema([
                    Infolists\Components\TextEntry::make('diff_content')
                        ->label('')
                        ->formatStateUsing(fn (?string $state) => $state ? '```diff'."\n".$state."\n".'```' : 'No diff available')
                        ->markdown()
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->diff_content)
                ->collapsible()
                ->collapsed(),

            Infolists\Components\Section::make('AI Raw Output')
                ->schema([
                    Infolists\Components\TextEntry::make('ai_raw_output')
                        ->label('')
                        ->markdown()
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->ai_raw_output)
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                // Only show the latest iteration of each PR
                $query->latestIteration();

                if (! auth()->user()->isAdmin()) {
                    $query->whereHas('repository', fn (Builder $q) => $q->where('user_id', auth()->id()));
                }
            })
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('repository.name')
                    ->label('Repo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pr_number')
                    ->label('PR #')
                    ->formatStateUsing(fn ($state, $record) => "#{$state}")
                    ->url(fn ($record) => $record->pr_url)
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
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'reviewing' => 'info',
                        'commented' => 'warning',
                        'fixing' => 'info',
                        'fixed' => 'success',
                        'approved' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('pr_status')
                    ->label('PR')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => '🟢 Open',
                        'closed' => '🔴 Closed',
                        'merged' => '🟣 Merged',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'success',
                        'closed' => 'danger',
                        'merged' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('ai_merge_status')
                    ->label('AI Merge')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'pending' => '⏳ Pending',
                        'processing' => '🔄 Processing',
                        'resolved' => '✅ Resolved',
                        'failed' => '❌ Failed',
                        default => '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'resolved' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (ReviewTask $record) => $record->ai_merge_message),

                Tables\Columns\TextColumn::make('merge_status')
                    ->label('Merge')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'queued' => '⏳ Queued',
                        'merging' => '🔄 Merging',
                        'merged' => '✅ Merged',
                        'failed' => '❌ Failed',
                        default => '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'queued' => 'warning',
                        'merging' => 'info',
                        'merged' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (ReviewTask $record) => $record->merge_message),

                Tables\Columns\TextColumn::make('iteration')
                    ->label('Iter.')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('comments_count')
                    ->label('Issues')
                    ->counts('comments')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('jules_fix_pr_url')
                    ->label('Fix PR')
                    ->formatStateUsing(fn (?string $state) => $state ? '🔧 View PR' : '—')
                    ->url(fn ($record) => $record->jules_fix_pr_url)
                    ->openUrlInNewTab()
                    ->color(fn (?string $state) => $state ? 'success' : 'gray'),

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
                Tables\Filters\SelectFilter::make('pr_status')
                    ->label('PR Status')
                    ->options([
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'merged' => 'Merged',
                    ]),
                Tables\Filters\SelectFilter::make('repository')
                    ->relationship('repository', 'name'),
                Tables\Filters\SelectFilter::make('ai_merge_status')
                    ->label('AI Merge')
                    ->options([
                        'pending' => '⏳ Pending',
                        'processing' => '🔄 Processing',
                        'resolved' => '✅ Resolved',
                        'failed' => '❌ Failed',
                    ]),
                Tables\Filters\SelectFilter::make('merge_status')
                    ->label('Merge')
                    ->options([
                        'queued' => '⏳ Queued',
                        'merging' => '🔄 Merging',
                        'merged' => '✅ Merged',
                        'failed' => '❌ Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->label('Retry Review')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ReviewTask $record) => in_array($record->status, ['failed', 'commented']))
                    ->action(function (ReviewTask $record) {
                        $record->update([
                            'status' => ReviewTask::STATUS_PENDING,
                            'error_message' => null,
                            'iteration' => $record->iteration + 1,
                        ]);
                        \App\Jobs\ReviewPrJob::dispatch($record);
                    }),
                Tables\Actions\Action::make('merge')
                    ->label('Merge PR')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Merge Pull Request')
                    ->modalDescription(fn (ReviewTask $record) => "Merge PR #{$record->pr_number}: {$record->pr_title}?")
                    ->visible(fn (ReviewTask $record) => $record->pr_status === 'open' && in_array($record->status, ['approved', 'fixed', 'commented']))
                    ->action(function (ReviewTask $record) {
                        $github = app(\App\Services\GitHubApiService::class)->forUser(auth()->id());
                        $repo = $record->repository;
                        try {
                            $github->mergePullRequest(
                                $repo->owner,
                                $repo->repo,
                                $record->pr_number,
                                "Merge PR #{$record->pr_number}: {$record->pr_title}",
                            );
                            $record->update(['pr_status' => ReviewTask::PR_STATUS_MERGED]);
                            \Filament\Notifications\Notification::make()
                                ->title("PR #{$record->pr_number} merged successfully")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $msg = $e->getMessage();
                            \Filament\Notifications\Notification::make()
                                ->title('Merge failed')
                                ->body(str_contains($msg, 'not mergeable') ? 'PR has merge conflicts' : \Illuminate\Support\Str::limit($msg, 100))
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('ai_merge')
                    ->label('AI Merge')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('AI Merge Pull Request')
                    ->modalDescription(fn (ReviewTask $record) => "Use AI to resolve merge conflicts and merge PR #{$record->pr_number}. This runs in the background.")
                    ->visible(fn (ReviewTask $record) => $record->pr_status === 'open' && in_array($record->status, ['approved', 'fixed', 'commented']))
                    ->action(function (ReviewTask $record) {
                        $record->update(['ai_merge_status' => ReviewTask::AI_MERGE_PENDING, 'ai_merge_message' => 'Queued for AI merge']);
                        \App\Jobs\AiMergeJob::dispatch($record, auth()->id());
                        \Filament\Notifications\Notification::make()
                            ->title("AI Merge started for PR #{$record->pr_number}")
                            ->body('Running in background. Refresh to see results.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('view_github')
                    ->label('View PR')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ReviewTask $record) => $record->pr_url)
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retry_selected')
                    ->label('Retry Selected')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $count = 0;
                        foreach ($records as $record) {
                            if (! in_array($record->status, ['failed', 'commented'])) {
                                continue;
                            }
                            $record->update([
                                'status' => ReviewTask::STATUS_PENDING,
                                'error_message' => null,
                                'iteration' => $record->iteration + 1,
                            ]);
                            \App\Jobs\ReviewPrJob::dispatch($record);
                            $count++;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title("Retrying {$count} tasks")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\BulkAction::make('merge_selected')
                    ->label('Merge Selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $github = app(\App\Services\GitHubApiService::class)->forUser(auth()->id());
                        $merged = 0;
                        $failed = 0;
                        foreach ($records as $record) {
                            $repo = $record->repository;
                            try {
                                $github->mergePullRequest(
                                    $repo->owner,
                                    $repo->repo,
                                    $record->pr_number,
                                    "Merge PR #{$record->pr_number}: {$record->pr_title}",
                                );
                                $record->update(['pr_status' => ReviewTask::PR_STATUS_MERGED]);
                                $merged++;
                            } catch (\Throwable) {
                                $failed++;
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title("Merged {$merged} PRs".($failed ? ", {$failed} failed" : ''))
                            ->color($failed ? 'warning' : 'success')
                            ->send();
                    }),
                Tables\Actions\BulkAction::make('ai_merge_selected')
                    ->label('AI Merge Selected')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $userId = auth()->id();
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record->pr_status === 'open') {
                                $record->update(['ai_merge_status' => ReviewTask::AI_MERGE_PENDING, 'ai_merge_message' => 'Queued for AI merge']);
                                \App\Jobs\AiMergeJob::dispatch($record, $userId);
                                $count++;
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title("AI Merge dispatched for {$count} PRs")
                            ->body('Processing in background. Refresh to see results.')
                            ->success()
                            ->send();
                    }),
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
