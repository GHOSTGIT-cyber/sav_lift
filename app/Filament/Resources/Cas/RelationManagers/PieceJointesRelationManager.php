<?php

namespace App\Filament\Resources\Cas\RelationManagers;

use App\Models\PieceJointe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Photos d'étiquette MHS, factures, vidéos du défaut.
 *
 * Le disque est privé : ni aperçu ni téléchargement ne passent par une URL
 * publique. Les deux pointent vers PieceJointeController, derrière
 * l'authentification du panneau.
 */
class PieceJointesRelationManager extends RelationManager
{
    protected static string $relationship = 'pieceJointes';

    protected static ?string $title = 'Pièces jointes';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPaperClip;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                // ImageColumn rend tel quel un état qui est déjà une URL : il
                // n'interroge donc jamais le disque, qui n'a pas d'URL publique.
                ImageColumn::make('apercu')
                    ->label('Aperçu')
                    ->state(fn (PieceJointe $record): ?string => $record->estAffichable() && $record->existeSurLeDisque()
                        ? route('filament.admin.pieces-jointes.apercu', $record)
                        : null)
                    ->height(48)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('filename')
                    ->label('Nom')
                    ->searchable()
                    ->wrap()
                    ->description(fn (PieceJointe $record): ?string => $record->existeSurLeDisque()
                        ? null
                        : 'Fichier absent du disque'),
                TextColumn::make('mime')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('taille')
                    ->label('Taille')
                    ->state(fn (PieceJointe $record): ?string => $record->tailleLisible())
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Ajoutée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Un lien direct, pas une action Livewire : Livewire encode le
                // fichier en base64 dans sa réponse, ce qu'une vidéo de 40 Mo
                // ne pardonne pas. Ici le fichier est servi en flux.
                Action::make('telecharger')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (PieceJointe $record): string => route('filament.admin.pieces-jointes.telecharger', $record))
                    ->visible(fn (PieceJointe $record): bool => $record->existeSurLeDisque()),
            ])
            ->emptyStateHeading('Aucune pièce jointe')
            ->emptyStateDescription('Les fichiers envoyés par le client apparaîtront ici.');
    }
}
