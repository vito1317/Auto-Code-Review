<?php

namespace App\Filament\Resources\RepositoryResource\Pages;

use App\Filament\Resources\RepositoryResource;
use App\Models\Repository;
use App\Services\JulesApiService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListRepositories extends ListRecords
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_from_jules')
                ->label('Sync from Jules')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Repositories from Jules')
                ->modalDescription('This will fetch all repositories connected to your Jules account and import any that are not yet in the system.')
                ->modalSubmitActionLabel('Sync Now')
                ->action(function () {
                    try {
                        $jules = app(JulesApiService::class);
                        $imported = 0;
                        $skipped = 0;
                        $pageToken = null;

                        do {
                            $result = $jules->listSources($pageToken);
                            $sources = $result['sources'] ?? [];
                            $pageToken = $result['nextPageToken'] ?? null;

                            foreach ($sources as $source) {
                                $ghRepo = $source['githubRepo'] ?? null;
                                if (!$ghRepo)
                                    continue;

                                $owner = $ghRepo['owner'] ?? '';
                                $repo = $ghRepo['repo'] ?? '';

                                if (empty($owner) || empty($repo))
                                    continue;

                                // Skip if already exists
                                if (Repository::where('owner', $owner)->where('repo', $repo)->exists()) {
                                    $skipped++;
                                    continue;
                                }

                                Repository::create([
                                    'name' => "{$owner}/{$repo}",
                                    'owner' => $owner,
                                    'repo' => $repo,
                                    'jules_source' => $source['name'] ?? "sources/github/{$owner}/{$repo}",
                                    'default_branch' => 'main',
                                    'webhook_secret' => Str::random(40),
                                    'is_active' => true,
                                ]);

                                $imported++;
                            }
                        } while ($pageToken);

                        Notification::make()
                            ->title("Sync Complete")
                            ->body("Imported: {$imported}, Skipped (already exists): {$skipped}")
                            ->success()
                            ->send();

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Sync Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
