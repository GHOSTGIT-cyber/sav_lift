<?php

namespace App\Filament\Resources\Cas;

use App\Filament\Resources\Cas\Pages\CreateCas;
use App\Filament\Resources\Cas\Pages\EditCas;
use App\Filament\Resources\Cas\Pages\ListCas;
use App\Filament\Resources\Cas\Schemas\CasForm;
use App\Filament\Resources\Cas\Tables\CasTable;
use App\Models\Cas;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CasResource extends Resource
{
    protected static ?string $model = Cas::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'dossier';

    protected static ?string $pluralModelLabel = 'dossiers SAV';

    protected static ?string $navigationLabel = 'Dossiers SAV';

    public static function form(Schema $schema): Schema
    {
        return CasForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCas::route('/'),
            'create' => CreateCas::route('/create'),
            'edit' => EditCas::route('/{record}/edit'),
        ];
    }
}
