<?php

namespace App\Filament\Resources\Cas\Schemas;

use App\Enums\StatutCas;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CasForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reference'),
                TextInput::make('client_nom'),
                TextInput::make('client_email')
                    ->email(),
                TextInput::make('client_telephone')
                    ->tel(),
                TextInput::make('produit'),
                TextInput::make('modele'),
                TextInput::make('numero_serie'),
                TextInput::make('sales_order'),
                Textarea::make('description')
                    ->columnSpanFull(),
                Select::make('statut')
                    ->options(StatutCas::class)
                    ->default('nouveau')
                    ->required(),
                TextInput::make('ticket_lift'),
                TextInput::make('tracking'),
                TextInput::make('source')
                    ->required()
                    ->default('email'),
            ]);
    }
}
