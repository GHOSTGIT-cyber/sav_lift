<?php

namespace Tests\Feature;

use App\Mail\AccuseReceptionMail;
use App\Models\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_le_corps_reprend_la_reference_et_les_pieces_a_fournir(): void
    {
        $corps = $this->envoyer()->getTextBody();

        $this->assertStringContainsString('Camille Dupont', $corps);
        $this->assertStringContainsString('SAV-2026-0001', $corps);

        foreach ([
            'coordonnées complètes',
            'modèle exact',
            'numéro de série (MHS)',
            'photo nette de l\'étiquette',
            'facture d\'achat',
            'Sales Order',
            'description précise',
            'photos et/ou une vidéo',
            'contexte d\'apparition',
            'sav@liftfoils.fr',
        ] as $element) {
            $this->assertStringContainsString($element, $corps, "L'accusé n'évoque pas « {$element} ».");
        }
    }
}
