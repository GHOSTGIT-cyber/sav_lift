<?php

namespace Tests\Feature;

use App\Enums\DirectionMessage;
use App\Filament\Resources\Cas\Pages\EditCas;
use App\Filament\Resources\Cas\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Cas\RelationManagers\PieceJointesRelationManager;
use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CasRelationManagersTest extends TestCase
{
    use RefreshDatabase;

    private Cas $cas;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());

        $this->cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
        ]);
    }

    private function message(DirectionMessage $direction = DirectionMessage::Inbound): Message
    {
        return $this->cas->messages()->create([
            'message_id' => 'msg-'.$direction->value.'@example.test',
            'direction' => $direction,
            'from_email' => 'camille@example.test',
            'from_name' => 'Camille Dupont',
            'subject' => 'Batterie qui ne charge plus',
            'body_text' => "Bonjour,\n\nMa batterie ne charge plus depuis hier.",
            'received_at' => now(),
        ]);
    }

    private function pieceJointe(): PieceJointe
    {
        $chemin = "sav/{$this->cas->id}/abcd1234-photo.jpg";
        Storage::disk('local')->put($chemin, 'contenu');

        return PieceJointe::create([
            'cas_id' => $this->cas->id,
            'message_id' => $this->message()->id,
            'path' => $chemin,
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'taille' => 2_048,
        ]);
    }

    public function test_la_timeline_liste_les_messages_du_dossier(): void
    {
        $entrant = $this->message();
        $sortant = $this->message(DirectionMessage::Outbound);

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $this->cas,
            'pageClass' => EditCas::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$entrant, $sortant])
            ->assertSee('Entrant')
            ->assertSee('Sortant')
            ->assertSee('Batterie qui ne charge plus');
    }

    public function test_l_extrait_du_corps_est_lisible_sur_une_seule_ligne(): void
    {
        $this->message();

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $this->cas,
            'pageClass' => EditCas::class,
        ])->assertSee('Bonjour, Ma batterie ne charge plus depuis hier.');
    }

    public function test_les_pieces_jointes_sont_listees_avec_leur_taille(): void
    {
        $piece = $this->pieceJointe();

        Livewire::test(PieceJointesRelationManager::class, [
            'ownerRecord' => $this->cas,
            'pageClass' => EditCas::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$piece])
            ->assertSee('photo.jpg')
            ->assertSee('2.0 KB');
    }

    /**
     * Le lien de téléchargement doit pointer la route protégée, jamais une URL
     * de disque : le disque `local` n'en expose aucune.
     */
    public function test_l_action_de_telechargement_pointe_la_route_protegee(): void
    {
        $piece = $this->pieceJointe();

        Livewire::test(PieceJointesRelationManager::class, [
            'ownerRecord' => $this->cas,
            'pageClass' => EditCas::class,
        ])->assertSee(route('filament.admin.pieces-jointes.telecharger', $piece), escape: false);
    }

    /**
     * ViewAction remplit le schéma `form()` du RelationManager puis le
     * désactive : sans ce schéma, la modale s'ouvrirait vide.
     */
    public function test_la_modale_d_un_message_affiche_le_corps_complet(): void
    {
        $message = $this->message();

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $this->cas,
            'pageClass' => EditCas::class,
        ])
            ->mountTableAction('view', $message)
            ->assertSchemaStateSet([
                'subject' => 'Batterie qui ne charge plus',
                'body_text' => "Bonjour,\n\nMa batterie ne charge plus depuis hier.",
            ]);
    }

    public function test_les_timelines_sont_en_lecture_seule(): void
    {
        $this->assertTrue(app(MessagesRelationManager::class)->isReadOnly());
        $this->assertTrue(app(PieceJointesRelationManager::class)->isReadOnly());
    }
}
