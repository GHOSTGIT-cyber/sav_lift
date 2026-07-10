<?php

namespace App\Filament\Resources\Cas\Pages;

use App\Filament\Resources\Cas\CasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCas extends ListRecords
{
    protected static string $resource = CasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
