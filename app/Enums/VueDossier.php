<?php

namespace App\Enums;

use App\Models\Cas;
use App\Services\Dossier\RegleCompletude;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les cinq — et seules — vues exposées à l'utilisateur.
 *
 * Une vue = une action attendue d'un humain, et le nom de la vue EST
 * l'instruction. Les statuts internes (StatutCas) restent plus fins ; ils se
 * projettent ici. Cette projection est exhaustive : chaque statut tombe dans
 * exactement une vue, aucun dossier ne peut se cacher.
 */
enum VueDossier: string implements HasColor, HasLabel
{
    /** Il manque des pièces bloquantes : la balle est dans le camp du client. */
    case AComplete = 'a_completer';

    /** Complet : plus rien ne manque, ça attend un clic de Nico. */
    case AValider = 'a_valider';

    /** Parti chez Lift, on attend leur réponse. */
    case ChezLift = 'chez_lift';

    /** Diagnostic ou réparation en cours à l'atelier. */
    case Atelier = 'atelier';

    case Clos = 'clos';

    public function getLabel(): string
    {
        return match ($this) {
            self::AComplete => 'À compléter',
            self::AValider => 'À valider',
            self::ChezLift => 'Chez Lift',
            self::Atelier => 'Atelier',
            self::Clos => 'Clos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AComplete => 'orange',
            self::AValider => 'success',
            self::ChezLift => 'indigo',
            self::Atelier => 'cyan',
            self::Clos => 'gray',
        };
    }

    /** Le geste attendu dans cette file, en trois mots (widget d'accueil). */
    public function resume(): string
    {
        return match ($this) {
            self::AComplete => 'Réclamer les pièces manquantes',
            self::AValider => 'Relire et envoyer à Lift',
            self::ChezLift => 'Attendre leur réponse',
            self::Atelier => 'Diagnostiquer ou réparer',
            self::Clos => 'Terminés',
        };
    }

    /**
     * La vue d'un dossier. Se lit de bas en haut : d'abord où en est le dossier
     * dans son cycle de vie, et seulement pour ceux qui n'ont pas encore quitté
     * la maison, s'il est complet ou non.
     */
    public static function de(Cas $cas): self
    {
        return match (true) {
            $cas->statut === StatutCas::Clos => self::Clos,
            in_array($cas->statut, [StatutCas::Atelier, StatutCas::Pret], true) => self::Atelier,
            in_array($cas->statut, [StatutCas::EnvoyeLift, StatutCas::AttenteLift], true) => self::ChezLift,
            (bool) $cas->complet => self::AValider,
            default => self::AComplete,
        };
    }

    /**
     * Le même découpage, mais en SQL — pour les onglets et leurs compteurs.
     *
     * S'appuie sur la colonne `complet`, tenue à jour par Cas (voir
     * Cas::rafraichirCompletude) : c'est ce qui permet de compter les dossiers
     * sans charger leurs pièces jointes.
     *
     * @param  Builder<Cas>  $query
     * @return Builder<Cas>
     */
    public function filtrer(Builder $query): Builder
    {
        $enCours = [StatutCas::Nouveau, StatutCas::AttenteClient];

        return match ($this) {
            self::AComplete => $query->whereIn('statut', $enCours)->where('complet', false),
            self::AValider => $query->whereIn('statut', $enCours)->where('complet', true),
            self::ChezLift => $query->whereIn('statut', [StatutCas::EnvoyeLift, StatutCas::AttenteLift]),
            self::Atelier => $query->whereIn('statut', [StatutCas::Atelier, StatutCas::Pret]),
            self::Clos => $query->where('statut', StatutCas::Clos),
        };
    }

    /**
     * L'instruction affichée en tête de la fiche. Une phrase, à l'impératif :
     * si un écran a besoin d'être expliqué, c'est qu'il est mal nommé.
     */
    public function prochaineAction(Cas $cas): string
    {
        return match ($this) {
            self::AComplete => $this->prochaineActionAComplete($cas),
            self::AValider => 'Vérifier les pièces, relire le brouillon, puis l\'envoyer à Lift.',
            self::ChezLift => $this->prochaineActionChezLift($cas),
            self::Atelier => $cas->statut === StatutCas::Pret
                ? 'Prévenir le client : son matériel est prêt.'
                : 'Diagnostic / réparation en cours à l\'atelier.',
            self::Clos => 'Dossier clos. Rien à faire.',
        };
    }

    private function prochaineActionAComplete(Cas $cas): string
    {
        $manque = implode(', ', RegleCompletude::libellesBloquants($cas));

        if ($cas->relance_client_le === null) {
            return "Relancer le client. Il manque : {$manque}.";
        }

        return sprintf(
            'Relance envoyée le %s. En attente du client : %s.',
            $cas->relance_client_le->format('d/m/Y'),
            $manque,
        );
    }

    private function prochaineActionChezLift(Cas $cas): string
    {
        if ($cas->reponse_lift_le !== null) {
            return "Lift a répondu le {$cas->reponse_lift_le->format('d/m/Y')}. Traiter leur réponse.";
        }

        return $cas->ticket_lift === null
            ? 'Envoyé à Lift. En attente de leur accusé et du n° de ticket.'
            : "Ticket Lift #{$cas->ticket_lift} ouvert. En attente de leur réponse.";
    }
}
