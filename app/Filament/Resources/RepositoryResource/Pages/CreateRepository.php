<?php

namespace App\Filament\Resources\RepositoryResource\Pages;

use App\Filament\Resources\RepositoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepository extends CreateRecord
{
    protected static string $resource = RepositoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-generate jules_source if not provided
        if (empty($data['jules_source'])) {
            $data['jules_source'] = "sources/github/{$data['owner']}/{$data['repo']}";
        }

        // Auto-generate webhook secret if not provided
        if (empty($data['webhook_secret'])) {
            $data['webhook_secret'] = \Illuminate\Support\Str::random(40);
        }

        return $data;
    }
}
