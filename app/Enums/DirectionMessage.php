<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Sens d'un email attaché à un dossier, du point de vue du SAV.
 */
enum DirectionMessage: string implements HasColor, HasLabel
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inbound => 'Entrant',
            self::Outbound => 'Sortant',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inbound => 'info',
            self::Outbound => 'gray',
        };
    }
}
