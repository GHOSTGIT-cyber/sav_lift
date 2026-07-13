<?php

namespace Database\Seeders;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Models\Cas;
use App\Models\PieceJointe;
use App\Models\User;
use App\Support\ModeDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Peuple l'instance de démonstration : un dossier dans chacune des cinq vues,
 * avec de vraies pièces jointes fictives (l'étiquette MHS, la facture, les
 * photos du défaut). Sans elles, on ne pourrait pas montrer le mécanisme le plus
 * dur à expliquer à l'oral : un dossier qui bascule tout seul en « À valider »
 * parce que le client a enfin envoyé la photo.
 *
 * **Idempotent** : le conteneur rejoue `migrate --seed` à chaque déploiement.
 * On repart donc de la référence du dossier, jamais d'un `create()` sec.
 *
 * Refuse de tourner hors mode démo (voir ModeDemo) : ce seeder ne doit jamais
 * pouvoir écrire un faux client dans la base de production.
 */
class DemoSeeder extends Seeder
{
    private const FIXTURES = __DIR__.'/demo';

    public function run(): void
    {
        if (! ModeDemo::actif()) {
            $this->command?->warn('Mode démo inactif : DemoSeeder ignoré.');

            return;
        }

        $this->visiteur();

        $this->aCompleterToutManque();
        $this->aCompleterRelanceEnvoyee();
        $this->aValiderPiecesRecues();
        $this->aValiderBrouillonPret();
        $this->chezLiftAvecReponse();
        $this->atelier();
        $this->clos();

        $this->command?->info('Démo : '.Cas::count().' dossiers fictifs.');
    }

    /**
     * Le compte sous lequel tout visiteur est connecté d'office (ConnexionDemo).
     *
     * Il porte quand même un mot de passe — aléatoire, jamais affiché, jamais
     * réutilisable : un compte sans hash serait un compte dont on pourrait
     * deviner le mot de passe le jour où quelqu'un rebrancherait l'écran de
     * connexion.
     */
    private function visiteur(): void
    {
        User::updateOrCreate(
            ['email' => ModeDemo::email()],
            [
                'name' => 'Visiteur (démo)',
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ],
        );
    }

    // -------------------------------------------------------------- À compléter

    /** Le dossier brut : un mail, rien d'autre. C'est ce que Nico reçoit vraiment. */
    private function aCompleterToutManque(): void
    {
        $cas = $this->dossier('SAV-2026-0101', [
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille.dupont@example.test',
            'produit' => 'batterie',
            'description' => "Bonjour,\n\nMa batterie ne charge plus depuis la sortie de samedi. Elle chauffe au branchement et le voyant reste rouge.\n\nMerci d'avance,\nCamille",
            'contexte' => 'Ne charge plus, chauffe au branchement, depuis une sortie en mer.',
            'urgent' => true,
        ]);

        $this->mailEntrant($cas, 'Batterie qui ne charge plus', 'Ma batterie ne charge plus depuis samedi. Elle chauffe au branchement.', 2);
    }

    /** Le client a été relancé, on attend ses pièces. La fiche le dit, et le date. */
    private function aCompleterRelanceEnvoyee(): void
    {
        $cas = $this->dossier('SAV-2026-0102', [
            'client_nom' => 'Marc Leroy',
            'client_email' => 'marc.leroy@example.test',
            'client_telephone' => '06 12 34 56 78',
            'produit' => 'telecommande',
            'modele' => 'MY H3',
            'description' => "La télécommande ne s'appaire plus depuis la mise à jour du firmware.",
            'contexte' => 'Après une mise à jour du firmware.',
            'relance_client_le' => now()->subDays(3),
        ]);

        $this->mailEntrant($cas, 'Télécommande HS', "Ne s'appaire plus depuis la MAJ.", 5);
        $this->mailSortant($cas, 'Réception de votre demande SAV Lift Foils', 'Accusé de réception + demande des pièces manquantes.', 5);
    }

    // ----------------------------------------------------------------- À valider

    /**
     * Le dossier qui montre la présomption à l'œuvre : le client a envoyé
     * l'étiquette et la vidéo, donc il a basculé tout seul en « À valider ».
     */
    private function aValiderPiecesRecues(): void
    {
        $cas = $this->dossier('SAV-2026-0103', [
            'client_nom' => 'Sophie Martin',
            'client_email' => 'sophie.martin@example.test',
            'client_telephone' => '06 98 76 54 32',
            'produit' => 'moteur',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-240118-0042',
            'sales_order' => 'SO-98765',
            'date_achat' => '12/03/2024',
            'description' => 'Le moteur fait un bruit de roulement et perd de la puissance au bout de dix minutes.',
            'contexte' => 'Après un transport en van, bruit de roulement.',
            'relance_client_le' => now()->subDays(6),
        ]);

        $this->mailEntrant($cas, 'Moteur bruyant', 'Bruit de roulement. MHS-240118-0042, commande SO-98765, acheté le 12/03/2024.', 8);
        $this->mailSortant($cas, 'Réception de votre demande SAV Lift Foils', 'Accusé + demande des pièces manquantes.', 8);
        $this->mailEntrant($cas, 'Re: Moteur bruyant', 'Voici la photo de l\'étiquette, la facture et une photo du moteur.', 4);

        $this->piece($cas, 'etiquette-mhs.jpg', 'image/jpeg');
        $this->piece($cas, 'defaut-moteur.jpg', 'image/jpeg');
        $this->piece($cas, 'facture.pdf', 'application/pdf');
    }

    /** Le dossier prêt à partir : brouillon anglais rédigé, il n'attend qu'un clic. */
    private function aValiderBrouillonPret(): void
    {
        $cas = $this->dossier('SAV-2026-0104', [
            'client_nom' => 'Julien Bernard',
            'client_email' => 'julien.bernard@example.test',
            'produit' => 'ebox_esc',
            'modele' => 'Lift5',
            'numero_serie' => 'MHS-250302-0117',
            'sales_order' => 'SO-44120',
            'date_achat' => 'juillet 2025',
            'description' => 'L\'eBox coupe en pleine navigation, au bout d\'une dizaine de minutes. Aucun choc, aucune immersion prolongée.',
            'contexte' => 'Coupure en navigation, pas de choc ni d\'immersion signalés.',
            'photo_etiquette' => true,
            'preuve_achat' => true,
            'photos_defaut' => true,
            'brouillon_lift' => <<<'TXT'
                Subject: [SAV-2026-0104] eBox cutting out mid-session — MHS-250302-0117

                Hello Lift team,

                One of our customers reports that his eBox (Lift5, MHS-250302-0117, Sales
                Order SO-44120, purchased in July 2025) cuts out mid-session, after roughly
                ten minutes of riding. He reports no impact and no prolonged immersion.

                Could you advise on how to proceed — diagnostics, RMA, or replacement part?

                Best regards,
                SAV Lift Foils France
                TXT,
            'brouillon_lift_le' => now()->subDay(),
        ]);

        $this->mailEntrant($cas, 'eBox qui coupe', 'Coupe au bout de 10 minutes. MHS-250302-0117.', 6);
        $this->piece($cas, 'etiquette-mhs.jpg', 'image/jpeg');
    }

    // ------------------------------------------------------------------ Chez Lift

    /** Lift a répondu : le dossier a bougé tout seul, sans qu'on interroge personne. */
    private function chezLiftAvecReponse(): void
    {
        $cas = $this->dossier('SAV-2026-0105', [
            'client_nom' => 'Léa Petit',
            'client_email' => 'lea.petit@example.test',
            'produit' => 'planche',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-231120-0009',
            'sales_order' => 'SO-31007',
            'date_achat' => 'mai 2024',
            'description' => 'Délaminage sur le flanc arrière droit de la planche.',
            'contexte' => 'Apparu après un transport sur galerie de toit.',
            'statut' => StatutCas::AttenteLift,
            'photo_etiquette' => true,
            'photos_defaut' => true,
            'ticket_lift' => '90907',
            'statut_lift' => 'open',
            'envoye_lift_le' => now()->subDays(9),
            'client_avise_lift_le' => now()->subDays(9),
            'reponse_lift_le' => now()->subDay(),
        ]);

        $this->mailEntrant($cas, 'Délaminage planche', 'Le flanc arrière droit se décolle.', 12);
        $this->mailSortant($cas, '[SAV-2026-0105] Board delamination — MHS-231120-0009', 'Hello Lift team, ...', 9);
        $this->mailEntrant($cas, 'Re: [SAV-2026-0105] Board delamination (#90907)', 'Your request has been received and assigned Ticket #90907.', 9, 'support@liftsupport.zendesk.com');
        $this->mailEntrant($cas, 'Re: [SAV-2026-0105] Board delamination (#90907)', 'Please ship the board back to our RMA center. Shipping label attached.', 1, 'support@liftsupport.zendesk.com');

        $this->piece($cas, 'delaminage-planche.jpg', 'image/jpeg');
    }

    // -------------------------------------------------------------- Atelier / Clos

    private function atelier(): void
    {
        $cas = $this->dossier('SAV-2026-0106', [
            'client_nom' => 'Thomas Roux',
            'client_email' => 'thomas.roux@example.test',
            'produit' => 'mat',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-220810-0055',
            'description' => 'Jeu dans le mât, à diagnostiquer à l\'atelier.',
            'statut' => StatutCas::Atelier,
            'tracking' => 'CB1234567890FR',
        ]);

        $this->mailEntrant($cas, 'Jeu dans le mât', 'Il y a du jeu à l\'emplanture.', 15);
    }

    private function clos(): void
    {
        $cas = $this->dossier('SAV-2026-0107', [
            'client_nom' => 'Inès Faure',
            'client_email' => 'ines.faure@example.test',
            'produit' => 'chargeur',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-240705-0301',
            'sales_order' => 'SO-77213',
            'description' => 'Chargeur remplacé sous garantie. Dossier soldé.',
            'statut' => StatutCas::Clos,
            'ticket_lift' => '88412',
            'statut_lift' => 'solved',
        ]);

        $this->mailEntrant($cas, 'Chargeur mort', 'Plus aucune LED au branchement.', 40);
    }

    // ------------------------------------------------------------------ Fabrique

    /**
     * `forceFill` et non `create` : on écrit aussi des champs hors `$fillable`
     * (les dates de suivi, le ticket). Et sur un dossier qui existe déjà, l'objet
     * est mis à jour au lieu d'être dupliqué — le seeder rejoue sans dommage.
     *
     * `client_avise_lift_le` est posé DANS la même écriture que le statut : sans
     * ça, CasObserver croirait le dossier fraîchement arrivé chez Lift et
     * tenterait de prévenir un client qui n'existe pas.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function dossier(string $reference, array $attributs): Cas
    {
        $cas = Cas::firstOrNew(['reference' => $reference]);

        $cas->forceFill([
            'statut' => StatutCas::Nouveau,
            'source' => 'email',
            ...$attributs,
        ])->save();

        return $cas;
    }

    private function mailEntrant(Cas $cas, string $sujet, string $corps, int $ilYaJours, ?string $de = null): void
    {
        $this->message($cas, $sujet, $corps, $ilYaJours, DirectionMessage::Inbound, $de ?? (string) $cas->client_email);
    }

    private function mailSortant(Cas $cas, string $sujet, string $corps, int $ilYaJours): void
    {
        $this->message($cas, $sujet, $corps, $ilYaJours, DirectionMessage::Outbound, 'sav@liftfoils.fr');
    }

    private function message(Cas $cas, string $sujet, string $corps, int $ilYaJours, DirectionMessage $sens, string $adresse): void
    {
        $entrant = $sens === DirectionMessage::Inbound;

        // Un Message-ID déterministe : c'est ce qui rend le seeder rejouable
        // (la relève déduplique sur ce champ, et nous aussi).
        $messageId = 'demo-'.substr(sha1($cas->reference.$sujet.$ilYaJours.$sens->value), 0, 16).'@demo.liftfoils.fr';

        $cas->messages()->updateOrCreate(['message_id' => $messageId], [
            'direction' => $sens,
            'from_email' => $entrant ? $adresse : 'sav@liftfoils.fr',
            'from_name' => $entrant ? $cas->client_nom : 'SAV Lift Foils France',
            'to_email' => $entrant ? 'sav@liftfoils.fr' : $adresse,
            'subject' => $sujet,
            'body_text' => $corps,
            'received_at' => now()->subDays($ilYaJours),
        ]);
    }

    /** Copie une pièce jointe fictive sur le disque privé et la rattache au dossier. */
    private function piece(Cas $cas, string $fichier, string $mime): void
    {
        $chemin = "sav/{$cas->id}/{$fichier}";
        $disque = Storage::disk('local');

        if (! $disque->exists($chemin)) {
            $disque->put($chemin, (string) file_get_contents(self::FIXTURES."/{$fichier}"));
        }

        PieceJointe::updateOrCreate(
            ['cas_id' => $cas->id, 'path' => $chemin],
            [
                'filename' => $fichier,
                'mime' => $mime,
                'taille' => (int) $disque->size($chemin),
            ],
        );
    }
}
