<?php

namespace App\Filament\Resources\Cas\Pages;

use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\RedacteurLift;
use App\Services\Ia\ServiceExtraction;
use App\Services\Mail\EnvoiLift;
use App\Services\Mail\NotificateurClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class EditCas extends EditRecord
{
    protected static string $resource = CasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->envoyerALift(),
            $this->relancerClient(),
            $this->genererBrouillon(),
            $this->reextraire(),
            DeleteAction::make(),
        ];
    }

    /**
     * Le geste qui envoie le dossier chez Lift — et le seul.
     *
     * Trois verrous en amont : la règle de complétude (le bouton est désactivé et
     * dit pourquoi), la confirmation explicite, et SAV_ENVOI_ACTIF. Si le
     * garde-fou est fermé, on le dit franchement : rien n'est parti, le dossier
     * n'a pas bougé.
     */
    private function envoyerALift(): Action
    {
        $envoi = app(EnvoiLift::class);

        return Action::make('envoyer_lift')
            ->label('Envoyer à Lift')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Envoyer ce dossier à Lift ?')
            ->modalDescription(fn (Cas $record): string => sprintf(
                'Le brouillon anglais part à %s, et le client est prévenu que son dossier a été transmis. Objet : « %s ».',
                config('sav.lift.email'),
                $record->sujetBrouillonLift(),
            ))
            ->modalSubmitActionLabel('Envoyer')
            ->visible(fn (Cas $record): bool => $record->envoye_lift_le === null)
            ->disabled(fn (Cas $record): bool => $envoi->empechement($record) !== null)
            ->tooltip(fn (Cas $record): ?string => $envoi->empechement($record))
            ->action(function (Cas $record) use ($envoi): void {
                try {
                    $parti = $envoi->envoyer($record);
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title('Envoi refusé')
                        ->status('danger')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $this->fillForm();

                $parti
                    ? Notification::make()
                        ->title('Dossier envoyé à Lift')
                        ->status('success')
                        ->body('Le client a été prévenu. La réponse de Lift se rattachera toute seule au dossier.')
                        ->send()
                    : Notification::make()
                        ->title('Envoi simulé — rien n\'est parti')
                        ->status('warning')
                        ->body('SAV_ENVOI_ACTIF est à false : le mail a été journalisé, pas envoyé. Le dossier n\'a pas bougé.')
                        ->send();
            });
    }

    /** Renvoie au client l'accusé — donc la liste, à jour, de ce qui manque encore. */
    private function relancerClient(): Action
    {
        return Action::make('relancer_client')
            ->label('Relancer le client')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Relancer le client ?')
            ->modalDescription(fn (Cas $record): string => 'Un mail lui redemandera exactement ce qui manque : '
                .implode(', ', array_map(fn ($e) => $e->libelle, $record->manquantsBloquants())).'.')
            ->visible(fn (Cas $record): bool => filled($record->client_email) && ! $record->complet)
            ->action(function (Cas $record): void {
                $parti = app(NotificateurClient::class)->accuserReception($record);
                $this->fillForm();

                $parti
                    ? Notification::make()
                        ->title('Client relancé')
                        ->status('success')
                        ->send()
                    : Notification::make()
                        ->title('Relance non envoyée')
                        ->status('warning')
                        ->body('SAV_ENVOI_ACTIF est à false, ou le SMTP a refusé. Voir les journaux.')
                        ->send();
            });
    }

    /** Génère le brouillon d'e-mail Lift. Ne l'envoie pas. */
    private function genererBrouillon(): Action
    {
        return Action::make('brouillon_lift')
            ->label('Générer le brouillon Lift')
            ->icon(Heroicon::OutlinedPencilSquare)
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
                    ->body('À relire. Rien n\'a été envoyé.')
                    ->send();
            });
    }

    /** Relance l'extraction IA sur le dossier (produit, MHS, contexte…). */
    private function reextraire(): Action
    {
        return Action::make('extraire')
            ->label('Ré-extraire (IA)')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (): bool => app(ServiceExtraction::class)->estConfiguree())
            ->action(function (Cas $record): void {
                app(ServiceExtraction::class)->pourCas($record);
                $this->fillForm();

                Notification::make()
                    ->title($record->extraction_erreur ? 'Extraction en échec' : 'Dossier ré-extrait')
                    ->status($record->extraction_erreur ? 'danger' : 'success')
                    ->body($record->extraction_erreur ?: 'Champs mis à jour.')
                    ->send();
            });
    }
}
