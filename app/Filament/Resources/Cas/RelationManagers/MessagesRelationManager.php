<?php

namespace App\Filament\Resources\Cas\RelationManagers;

use App\Models\Message;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * La timeline du dossier : tout ce qui s'est dit, dans l'ordre.
 *
 * En lecture seule — ces lignes sont le reflet d'emails réellement échangés.
 * Les modifier depuis le panneau n'aurait aucun effet sur le monde extérieur,
 * et casserait la déduplication (le Message-ID est la clé de la relève IMAP).
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedEnvelope;

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Le contenu de la modale « Voir » : ViewAction remplit ce schéma puis le
     * désactive en bloc. C'est le seul usage de ce formulaire — isReadOnly()
     * interdit création et édition.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('subject')->label('Sujet')->columnSpanFull(),
                        TextInput::make('from_email')->label('De'),
                        TextInput::make('to_email')->label('À'),
                        TextInput::make('received_at')->label('Reçu le'),
                        TextInput::make('message_id')->label('Message-ID'),
                    ]),

                Section::make('Corps du message')
                    ->schema([
                        // Volontairement le texte brut : afficher le HTML d'un
                        // mail entrant reviendrait à exécuter le balisage d'un
                        // inconnu dans la session du technicien.
                        Textarea::make('body_text')
                            ->hiddenLabel()
                            ->rows(18)
                            ->columnSpanFull()
                            ->placeholder('(message sans corps texte)'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'asc')
            ->columns([
                TextColumn::make('received_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Sens')
                    ->badge(),
                TextColumn::make('from_email')
                    ->label('Expéditeur')
                    ->description(fn (Message $record): ?string => $record->from_name)
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('Sujet')
                    ->searchable()
                    ->wrap()
                    ->placeholder('(sans sujet)'),
                TextColumn::make('extrait')
                    ->label('Extrait')
                    ->state(fn (Message $record): string => $record->extrait())
                    ->wrap()
                    ->color('gray'),
                // withCount('pieceJointes') expose l'attribut sous sa forme
                // snake_case : c'est ce nom-là que la colonne doit porter.
                TextColumn::make('piece_jointes_count')
                    ->label('PJ')
                    ->counts('pieceJointes')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Aucun message')
            ->emptyStateDescription('Les emails du dossier apparaîtront ici après la relève.');
    }
}
