<?php

namespace App\Filament\Resources\Cas\Pages;

use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\RedacteurLift;
use App\Services\Ia\ServiceExtraction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCas extends EditRecord
{
    protected static string $resource = CasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Relance l'extraction IA sur le dossier (produit, MHS, contexte…).
            Action::make('extraire')
                ->label('Ré-extraire (IA)')
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => app(ServiceExtraction::class)->estConfiguree())
                ->action(function (Cas $record): void {
                    app(ServiceExtraction::class)->pourCas($record);
                    $this->fillForm();

                    Notification::make()
                        ->title($record->extraction_erreur ? 'Extraction en échec' : 'Dossier ré-extrait')
                        ->status($record->extraction_erreur ? 'danger' : 'success')
                        ->body($record->extraction_erreur ?: 'Champs mis à jour.')
                        ->send();
                }),

            // Génère le brouillon d'e-mail Lift. Ne l'envoie jamais.
            Action::make('brouillon_lift')
                ->label('Générer le brouillon Lift')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->visible(fn (): bool => app(RedacteurLift::class)->estConfigure())
                ->action(function (Cas $record): void {
                    try {
                        $brouillon = app(RedacteurLift::class)->rediger($record);
                    } catch (ExtractionException $e) {
                        Notification::make()
                            ->title('Génération impossible')
                            ->status('danger')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    $record->forceFill([
                        'brouillon_lift' => $brouillon,
                        'brouillon_lift_le' => now(),
                    ])->save();

                    $this->fillForm();

                    Notification::make()
                        ->title('Brouillon Lift généré')
                        ->status('success')
                        ->body('À relire, puis à copier vers Lift. Rien n\'a été envoyé.')
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
