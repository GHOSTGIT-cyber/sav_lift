<?php

namespace App\Filament\Resources\Cas\Schemas;

use App\Enums\StatutCas;
use App\Models\Cas;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                    ->description('Pré-rempli par l\'extraction IA (verbatim ou vide). À vérifier avant d\'ouvrir un dossier chez Lift.')
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
                        Toggle::make('urgent')
                            ->label('Urgent')
                            ->helperText('Signalé par l\'IA ou coché à la main. L\'humain tranche.'),
                        Toggle::make('complet')
                            ->label('Dossier complet (actionnable)')
                            ->helperText('Produit + numéro de série présents.')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Demande')
                    ->schema([
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('contexte')
                            ->label('Contexte (extrait par l\'IA)')
                            ->helperText('Choc, immersion, transport, depuis quand… Résumé automatique, modifiable.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Suivi Lift')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ticket_lift')
                            ->label('Ticket Lift')
                            ->helperText('N° du ticket Zendesk chez Lift, saisi à la main.')
                            ->maxLength(255),
                        TextInput::make('statut_lift')
                            ->label('Statut chez Lift')
                            ->placeholder('open / solved…')
                            ->maxLength(255),
                        TextInput::make('tracking')
                            ->label('Numéro de suivi')
                            ->maxLength(255),
                        Placeholder::make('portail_lift')
                            ->label('Portail Lift')
                            ->content(fn (?Cas $record): HtmlString => new HtmlString(
                                $record?->lienPortailZendesk()
                                    ? '<a href="'.e($record->lienPortailZendesk()).'" target="_blank" rel="noopener" class="text-primary-600 underline">Ouvrir le ticket ↗</a>'
                                    : '<span class="text-gray-400">— (renseigner le n° de ticket)</span>',
                            )),
                    ]),

                Section::make('Brouillon Lift (anglais)')
                    ->description('Généré par l\'IA à partir du dossier. JAMAIS envoyé automatiquement : à relire, puis copier vers '.config('sav.lift.email').'. Bouton « Générer le brouillon Lift » en haut de page.')
                    ->collapsible()
                    ->schema([
                        Textarea::make('brouillon_lift')
                            ->hiddenLabel()
                            ->rows(14)
                            ->columnSpanFull()
                            ->placeholder('Aucun brouillon. Utilisez « Générer le brouillon Lift ».'),
                    ]),
            ]);
    }
}
