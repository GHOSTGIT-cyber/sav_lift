<?php

namespace App\Filament\Resources\Cas\Tables;

use App\Enums\StatutCas;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('client_nom')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('produit')
                    ->label('Produit')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                // La date du dernier email du fil, et non celle de la dernière
                // édition du dossier : c'est le client qui rythme le SAV. Le
                // nom de la colonne est celui que `withMax` donne à l'attribut.
                TextColumn::make('messages_max_received_at')
                    ->label('Dernière activité')
                    ->max('messages', 'received_at')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('numero_serie')
                    ->label('Numéro de série (MHS)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ticket_lift')
                    ->label('Ticket Lift')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking')
                    ->label('Numéro de suivi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(StatutCas::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun dossier SAV')
            ->emptyStateDescription('Les dossiers arriveront par mail à partir du Bloc 1. En attendant, créez-en un à la main.');
    }
}
