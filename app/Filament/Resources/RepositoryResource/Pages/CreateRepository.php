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

        return $data;
    }
}
