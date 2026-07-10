<?php

namespace App\Filament\Resources\Cas\Pages;

use App\Filament\Resources\Cas\CasResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCas extends EditRecord
{
    protected static string $resource = CasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
