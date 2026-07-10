<?php

namespace App\Filament\Resources\Cas\Schemas;

use App\Enums\StatutCas;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CasForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dossier')
                    ->columns(3)
                    ->schema([
                        TextInput::make('reference')
                            ->label('Référence')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('statut')
                            ->label('Statut')
                            ->options(StatutCas::class)
                            ->default(StatutCas::Nouveau->value)
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('source')
                            ->label('Source')
                            ->default('email')
                            ->required()
                            ->maxLength(255),
                    ]),

                Section::make('Client')
                    ->columns(3)
                    ->schema([
                        TextInput::make('client_nom')
                            ->label('Nom')
                            ->maxLength(255),
                        TextInput::make('client_email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('client_telephone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(255),
                    ]),

                Section::make('Matériel')
                    ->columns(2)
                    ->schema([
                        TextInput::make('produit')
                            ->label('Produit')
                            ->helperText('Catégorie : batterie, télécommande, eBox/ESC, moteur, mât, chargeur, planche, foil…')
                            ->maxLength(255),
                        TextInput::make('modele')
                            ->label('Modèle')
                            ->maxLength(255),
                        TextInput::make('numero_serie')
                            ->label('Numéro de série (MHS)')
                            ->maxLength(255),
                        TextInput::make('sales_order')
                            ->label('Sales Order')
                            ->maxLength(255),
                    ]),

                Section::make('Demande')
                    ->schema([
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make('Suivi Lift')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ticket_lift')
                            ->label('Ticket Lift')
                            ->maxLength(255),
                        TextInput::make('tracking')
                            ->label('Numéro de suivi')
                            ->maxLength(255),
                    ]),
            ]);
    }
}
