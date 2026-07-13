<?php

namespace App\Filament\Resources\Cas\Schemas;

use App\Enums\StatutCas;
use App\Models\Cas;
use App\Services\Dossier\Exigence;
use App\Services\Dossier\RegleCompletude;
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
    /**
     * Les trois états d'une pièce qu'un humain seul peut trancher. `null` (le
     * placeholder du Select) laisse la présomption faire son travail.
     */
    private const ETATS_PIECE = [
        1 => 'Fournie',
        0 => 'Absente ou illisible',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                static::bandeau(),

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
                        TextInput::make('date_achat')
                            ->label('Date d\'achat')
                            ->helperText('Telle qu\'annoncée par le client. Indice de garantie — l\'humain tranche.')
                            ->maxLength(255),
                        Toggle::make('urgent')
                            ->label('Urgent')
                            ->helperText('Signalé par l\'IA ou coché à la main.'),
                    ]),

                Section::make('Pièces du dossier')
                    ->description('Par défaut, l\'outil présume d\'après les pièces jointes. Vous seul pouvez dire si l\'étiquette est LISIBLE et si les photos montrent bien le défaut : tranchez ici, et le dossier change de vue en conséquence.')
                    ->columns(3)
                    ->schema([
                        Select::make('photo_etiquette')
                            ->label('Photo de l\'étiquette MHS')
                            ->options(self::ETATS_PIECE)
                            ->placeholder(fn (?Cas $record): string => static::presomption($record?->aPhotoEtiquette())),
                        Select::make('preuve_achat')
                            ->label('Facture ou Sales Order')
                            ->options(self::ETATS_PIECE)
                            ->placeholder(fn (?Cas $record): string => static::presomption($record?->aPreuveAchat())),
                        Select::make('photos_defaut')
                            ->label('Photos / vidéos du défaut')
                            ->options(self::ETATS_PIECE)
                            ->placeholder(fn (?Cas $record): string => static::presomption($record?->aPhotosDefaut())),
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
                            ->helperText('Capté automatiquement dans l\'accusé de Lift. Saisissable à la main s\'il manque.')
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
                                    : '<span class="text-gray-400">— (aucun n° de ticket)</span>',
                            )),
                    ]),

                Section::make('Brouillon Lift (anglais)')
                    ->description('Généré par l\'IA à partir du dossier. Il ne part que si vous cliquez « Envoyer à Lift », et seulement si le dossier est complet.')
                    ->collapsible()
                    ->schema([
                        Textarea::make('brouillon_lift')
                            ->hiddenLabel()
                            ->rows(14)
                            ->columnSpanFull()
                            ->placeholder('Aucun brouillon. Utilisez « Générer le brouillon Lift ».'),
                    ]),

                Section::make('Dossier')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('reference')
                            ->label('Référence')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('statut')
                            ->label('Statut interne')
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
            ]);
    }

    /**
     * Le bandeau qui ouvre la fiche : ce qu'il faut faire, et ce qui manque pour
     * le faire. Deux phrases, en haut, avant tout formulaire — plus besoin de
     * lire le dossier pour savoir quoi en faire.
     */
    private static function bandeau(): Section
    {
        return Section::make()
            ->hiddenOn('create')
            ->columns(2)
            ->schema([
                Placeholder::make('prochaine_action')
                    ->label('Prochaine action')
                    ->content(fn (?Cas $record): HtmlString => new HtmlString(
                        '<span class="text-base font-semibold">'.e($record?->prochaineAction() ?? '—').'</span>',
                    )),
                Placeholder::make('ce_qui_manque')
                    ->label('Ce qui manque')
                    ->content(fn (?Cas $record): HtmlString => static::listeManquants($record)),
            ]);
    }

    /**
     * Ce qui manque, champ par champ — et non un badge « incomplet » qui
     * n'apprend rien. Le bloquant en rouge, le souhaitable en gris : la
     * distinction est celle de RegleCompletude, pas une couleur choisie ici.
     */
    private static function listeManquants(?Cas $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $manquants = RegleCompletude::manquants($record);

        if ($manquants === []) {
            return new HtmlString('<span class="text-success-600 font-medium">Rien. Le dossier est complet.</span>');
        }

        $lignes = array_map(
            fn (Exigence $exigence): string => sprintf(
                '<li class="%s">%s %s</li>',
                $exigence->bloquante ? 'text-danger-600 font-medium' : 'text-gray-500',
                $exigence->bloquante ? '&#10007;' : '&#8226;',
                e($exigence->libelle).($exigence->bloquante ? '' : ' <span class="text-xs">(souhaitable)</span>'),
            ),
            $manquants,
        );

        return new HtmlString('<ul class="space-y-1">'.implode('', $lignes).'</ul>');
    }

    private static function presomption(?bool $presumee): string
    {
        return $presumee === true
            ? 'Automatique : présumée fournie'
            : 'Automatique : présumée absente';
    }
}
