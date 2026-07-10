<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'un dossier SAV.
 *
 * Les libellés et les couleurs sont consommés directement par Filament
 * (badges de la table, Select du formulaire, filtre par statut).
 *
 * Les couleurs autres que celles fournies par Filament (info, success, gray…)
 * sont enregistrées dans App\Providers\Filament\AdminPanelProvider.
 */
enum StatutCas: string implements HasColor, HasLabel
{
    case Nouveau = 'nouveau';
    case AttenteClient = 'attente_client';
    case EnvoyeLift = 'envoye_lift';
    case AttenteLift = 'attente_lift';
    case Atelier = 'atelier';
    case Pret = 'pret';
    case Clos = 'clos';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::AttenteClient => 'Attente client',
            self::EnvoyeLift => 'Envoyé à Lift',
            self::AttenteLift => 'Attente Lift',
            self::Atelier => 'Atelier',
            self::Pret => 'Prêt',
            self::Clos => 'Clos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nouveau => 'info',
            self::AttenteClient => 'orange',
            self::EnvoyeLift => 'indigo',
            self::AttenteLift => 'violet',
            self::Atelier => 'cyan',
            self::Pret => 'success',
            self::Clos => 'gray',
        };
    }
}
