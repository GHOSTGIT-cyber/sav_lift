<?php

namespace Tests\Feature;

use App\Mail\AccuseReceptionMail;
use App\Models\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * On vérifie ici le mail réellement produit — pas le Mailable, mais le MIME
 * qui sort du transport. Les en-têtes de threading sont posés par Laravel à
 * partir de `headers()` : c'est la seule façon de s'assurer que l'accusé
 * atterrit bien dans le fil du client, et non dans un message isolé.
 */
class AccuseReceptionMailTest extends TestCase
{
    use RefreshDatabase;

    private function envoyer(?string $enReponseA = 'demande-1@example.test'): Email
    {
        config()->set('mail.from.address', 'sav@liftfoils.fr');
        config()->set('mail.from.name', 'SAV Lift Foils France');
        config()->set('sav.mailbox', 'sav@liftfoils.fr');

        $cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
        ]);

        Mail::to($cas->client_email)->send(
            new AccuseReceptionMail($cas, 'sav-abcdef@liftfoils.fr', $enReponseA),
        );

        return Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    }

    public function test_le_mail_porte_notre_message_id(): void
    {
        $entetes = $this->envoyer()->getHeaders();

        // Généré en amont et enregistré en base : c'est lui que portera le
        // In-Reply-To de la réponse du client.
        $this->assertSame('sav-abcdef@liftfoils.fr', $entetes->get('Message-Id')->getId());
    }

    public function test_le_mail_se_greffe_sur_le_fil_du_client(): void
    {
        $entetes = $this->envoyer()->getHeaders();

        $this->assertSame('<demande-1@example.test>', $entetes->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('<demande-1@example.test>', $entetes->get('References')->getBodyAsString());
    }

    public function test_un_mail_sans_parent_ne_porte_pas_d_entetes_de_fil(): void
    {
        $entetes = $this->envoyer(enReponseA: null)->getHeaders();

        $this->assertFalse($entetes->has('In-Reply-To'));
        $this->assertFalse($entetes->has('References'));
    }

    public function test_l_expediteur_et_le_sujet_viennent_de_la_config(): void
    {
        $mail = $this->envoyer();

        $this->assertSame('Réception de votre demande SAV Lift Foils', $mail->getSubject());
        $this->assertSame('sav@liftfoils.fr', $mail->getFrom()[0]->getAddress());
        $this->assertSame('SAV Lift Foils France', $mail->getFrom()[0]->getName());
        $this->assertSame('camille@example.test', $mail->getTo()[0]->getAddress());
    }

    public function test_le_corps_reprend_la_reference_et_reclame_les_pieces_manquantes(): void
    {
        $corps = $this->envoyer()->getTextBody();

        $this->assertStringContainsString('Camille Dupont', $corps);
        $this->assertStringContainsString('SAV-2026-0001', $corps);

        // Le dossier de ce test est vide : tout est à réclamer, sauf les
        // coordonnées (nom + e-mail), qu'il porte déjà. C'est tout le principe.
        foreach ([
            'modèle exact concerné',
            'numéro MHS',
            'photo lisible de l\'étiquette',
            'facture d\'achat',
            'Sales Order',
            'description courte et précise',
            'photos et/ou vidéos',
            'contexte d\'apparition',
            'sav@liftfoils.fr',
        ] as $element) {
            $this->assertStringContainsString($element, $corps, "L'accusé n'évoque pas « {$element} ».");
        }
    }

    /**
     * Le cœur du correctif « flux Nico » : le mail ne réclame QUE ce qui manque.
     * Le client a donné son nom et son e-mail — on ne les redemande pas.
     */
    public function test_l_accuse_ne_reclame_pas_ce_que_le_client_a_deja_fourni(): void
    {
        $corps = $this->envoyer()->getTextBody();

        $this->assertStringNotContainsString('vos coordonnées', $corps);
    }

    /** Dossier complet : le même mail, sans aucune puce, et il le dit. */
    public function test_un_dossier_complet_ne_reclame_rien(): void
    {
        Storage::fake('local');

        config()->set('mail.from.address', 'sav@liftfoils.fr');
        config()->set('sav.mailbox', 'sav@liftfoils.fr');

        $cas = Cas::create([
            'reference' => 'SAV-2026-0002',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
            'client_telephone' => '0612345678',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-123456',
            'sales_order' => 'SO-99',
            'date_achat' => 'juillet 2024',
            'description' => 'Ne charge plus.',
            'contexte' => 'après un choc',
            'photo_etiquette' => true,
            'photos_defaut' => true,
        ]);

        Mail::to($cas->client_email)->send(new AccuseReceptionMail($cas, 'sav-1@liftfoils.fr'));

        $corps = Mail::mailer()->getSymfonyTransport()->messages()->first()
            ->getOriginalMessage()->getTextBody();

        $this->assertStringContainsString('Votre dossier est complet', $corps);
        $this->assertStringNotContainsString('merci de nous transmettre les éléments suivants', $corps);
    }
}
