<?php

namespace App\Filament\Resources\Cas\Pages;

use App\Enums\VueDossier;
use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCas extends ListRecords
{
    protected static string $resource = CasResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouveau dossier'),
        ];
    }

    /**
     * Les cinq vues, et rien d'autre.
     *
     * Chaque onglet est une file d'attente : son nom dit ce qu'on y fait, son
     * compteur dit combien il en reste. Nico ouvre l'outil, lit « À valider (3) »,
     * et sait ce qui l'attend sans cliquer une seule fois.
     *
     * Le découpage lui-même vit dans VueDossier — ici, on ne fait que l'afficher.
     * Il est exhaustif : aucun dossier ne peut tomber hors des cinq onglets.
     */
    public function getTabs(): array
    {
        $onglets = [];

        foreach (VueDossier::cases() as $vue) {
            $nombre = $vue->filtrer(Cas::query())->count();

            $onglets[$vue->value] = Tab::make($vue->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $vue->filtrer($query))
                // `?: null` : un onglet vide n'affiche pas « 0 », il n'affiche rien.
                ->badge($nombre ?: null)
                ->badgeColor($vue->getColor());
        }

        return $onglets;
    }
}
