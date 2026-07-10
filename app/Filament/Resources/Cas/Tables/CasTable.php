<?php

namespace App\Filament\Resources\Cas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable(),
                TextColumn::make('client_nom')
                    ->searchable(),
                TextColumn::make('client_email')
                    ->searchable(),
                TextColumn::make('client_telephone')
                    ->searchable(),
                TextColumn::make('produit')
                    ->searchable(),
                TextColumn::make('modele')
                    ->searchable(),
                TextColumn::make('numero_serie')
                    ->searchable(),
                TextColumn::make('sales_order')
                    ->searchable(),
                TextColumn::make('statut')
                    ->badge()
                    ->searchable(),
                TextColumn::make('ticket_lift')
                    ->searchable(),
                TextColumn::make('tracking')
                    ->searchable(),
                TextColumn::make('source')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
