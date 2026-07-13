{{--
    Accusé de réception + demande des pièces manquantes, en un seul mail.

    Le texte de base est celui validé par le client ; la liste à puces, elle, est
    GÉNÉRÉE (voir App\Services\Dossier\RegleCompletude) : on ne réclame que ce qui
    manque vraiment. Le texte vit ici, et pas dans le code, pour être retouché sans
    déploiement de logique.
--}}
<x-mail::message>
Bonjour{{ $cas->client_nom ? ' '.$cas->client_nom : '' }},

Merci de nous avoir contactés concernant votre demande SAV Lift Foils.

Votre demande a bien été reçue par notre équipe et porte la référence **{{ $cas->reference }}**.
Merci de la rappeler dans vos prochains échanges.

@if ($demandes === [])
Votre dossier est complet : nous le transmettons au support Lift Foils. Nous revenons
vers vous dès leur réponse.
@else
Afin de pouvoir traiter votre dossier rapidement et, si nécessaire, ouvrir un ticket
auprès du support Lift Foils, merci de nous transmettre les éléments suivants :

{{-- rtrim : la dernière puce (« …, après contact avec l'eau, etc. ») porte déjà
     son point ; sans ça, on écrirait « etc.. ». --}}
@foreach ($demandes as $demande)
- {{ rtrim($demande, '.') }}{{ $loop->last ? '.' : ' ;' }}
@endforeach

Dès réception de ces éléments, nous pourrons :

1. analyser votre demande ;
2. déterminer s'il s'agit d'un cas de garantie, d'un diagnostic atelier ou d'une intervention hors garantie ;
3. ouvrir si nécessaire un ticket auprès du support Lift Foils ;
4. vous tenir informé de la suite du traitement.
@endif

<x-mail::panel>
**Important** — pour un traitement efficace, merci de centraliser les échanges liés à ce
dossier par email à **{{ config('sav.mailbox') }}**, en répondant directement à ce message,
et d'éviter les doublons par SMS, WhatsApp ou appels directs, sauf urgence.
</x-mail::panel>

Nous revenons vers vous dès que possible.

Bien cordialement,

L'équipe SAV Lift Foils France
</x-mail::message>
