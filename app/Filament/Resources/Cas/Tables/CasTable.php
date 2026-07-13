<?php

namespace App\Filament\Resources\Cas\Tables;

use App\Enums\StatutCas;
use App\Enums\VueDossier;
use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use App\Services\Dossier\RegleCompletude;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\RedacteurLift;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class CasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // « Ce qui manque » interroge les pièces jointes de chaque dossier :
            // sans ce with(), la liste ferait une requête par ligne.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('pieceJointes'))
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Cas $record): ?string => $record->urgent ? 'URGENT' : null)
                    ->placeholder('—'),
                TextColumn::make('client_nom')
                    ->label('Client')
                    ->description(fn (Cas $record): ?string => $record->client_email)
                    ->searchable(['client_nom', 'client_email'])
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('produit')
                    ->label('Produit')
                    ->description(fn (Cas $record): ?string => $record->modele)
                    ->searchable(['produit', 'modele'])
                    ->placeholder('—'),

                // Le cœur de l'écran : pas un badge « incomplet », mais la liste
                // de ce qu'il faut aller chercher. Nico sait quoi faire sans ouvrir.
                TextColumn::make('manque')
                    ->label('Ce qui manque')
                    ->state(fn (Cas $record): array => RegleCompletude::libellesBloquants($record))
                    ->badge()
                    ->color('danger')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('Rien — dossier complet'),

                TextColumn::make('ticket_lift')
                    ->label('Ticket Lift')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : "#{$state}")
                    ->url(fn (Cas $record): ?string => $record->lienPortailZendesk())
                    ->openUrlInNewTab()
                    ->color(fn (Cas $record): string => $record->ticket_lift ? 'primary' : 'gray')
                    ->searchable()
                    ->placeholder('—'),

                // La date du dernier email du fil, et non celle de la dernière
                // édition du dossier : c'est le client qui rythme le SAV. Le
                // nom de la colonne est celui que `withMax` donne à l'attribut.
                TextColumn::make('messages_max_received_at')
                    ->label('Dernière activité')
                    ->max('messages', 'received_at')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('—'),

                TextColumn::make('statut')
                    ->label('Statut interne')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('urgent')
                    ->label('Urgent')
                    ->boolean()
                    ->trueIcon('heroicon-s-exclamation-triangle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('numero_serie')
                    ->label('Numéro de série (MHS)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking')
                    ->label('Numéro de suivi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('urgent')
                    ->label('Urgents seulement')
                    ->query(fn (Builder $query): Builder => $query->where('urgent', true))
                    ->toggle(),
                SelectFilter::make('statut')
                    ->label('Statut interne')
                    ->options(StatutCas::class),
            ])
            ->recordActions([
                static::preparerEnvoiLift(),
                EditAction::make()->label('Ouvrir'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun dossier dans cette vue')
            ->emptyStateDescription('Les dossiers arrivent par mail sur '.config('sav.mailbox').'.');
    }

    /**
     * L'action attendue, posée sur la ligne : quand un dossier est complet, il ne
     * reste qu'un geste à faire, et il est visible sans ouvrir la fiche.
     *
     * Elle prépare, elle n'envoie pas : le brouillon est généré s'il manque, puis
     * la fiche s'ouvre pour qu'un humain le relise. L'envoi est un second clic,
     * sur la fiche (voir EditCas) — un mail vers Lift ne part jamais d'un clic
     * unique depuis une liste.
     */
    private static function preparerEnvoiLift(): Action
    {
        return Action::make('preparer_envoi_lift')
            ->label('Valider & préparer l\'envoi Lift')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->button()
            ->visible(fn (Cas $record): bool => VueDossier::de($record) === VueDossier::AValider)
            ->action(function (Cas $record, Component $livewire): void {
                if (blank($record->brouillon_lift) && app(RedacteurLift::class)->estConfigure()) {
                    try {
                        $record->forceFill([
                            'brouillon_lift' => app(RedacteurLift::class)->rediger($record),
                            'brouillon_lift_le' => now(),
                        ])->save();
                    } catch (ExtractionException $e) {
                        Notification::make()
                            ->title('Brouillon non généré')
                            ->status('warning')
                            ->body($e->getMessage().' Le dossier s\'ouvre quand même : rédigez-le à la main.')
                            ->send();
                    }
                }

                $livewire->redirect(CasResource::getUrl('edit', ['record' => $record]));
            });
    }
}
