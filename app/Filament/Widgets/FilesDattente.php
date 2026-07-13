<?php

namespace App\Filament\Widgets;

use App\Enums\VueDossier;
use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * L'accueil : les cinq files, leur compteur, et un clic pour y aller.
 *
 * Mêmes vues, même découpage, même code (VueDossier) que les onglets de la liste
 * — il ne peut donc pas y avoir deux vérités sur le nombre de dossiers qui
 * attendent.
 */
class FilesDattente extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected ?string $heading = 'Ce qui vous attend';

    protected function getStats(): array
    {
        return array_map(
            fn (VueDossier $vue): Stat => Stat::make(
                $vue->getLabel(),
                $vue->filtrer(Cas::query())->count(),
            )
                ->description($vue->resume())
                ->color($vue->getColor())
                ->url(CasResource::getUrl('index', ['activeTab' => $vue->value])),
            VueDossier::cases(),
        );
    }
}
