<x-mail::message>
Bonjour{{ $cas->client_nom ? ' '.$cas->client_nom : '' }},

Votre demande de prise en charge (dossier **{{ $cas->reference }}**) a été transmise au
support Lift Foils, à Porto Rico.

Votre dossier est désormais en cours de traitement de leur côté. Nous revenons vers vous
dès que nous avons leur réponse — inutile de nous relancer entre-temps.

@if ($cas->ticket_lift)
Référence du ticket ouvert chez Lift : **#{{ $cas->ticket_lift }}**.
@endif

<x-mail::panel>
Si vous avez de nouveaux éléments (photos, vidéos, précisions), répondez simplement à ce
message : ils seront ajoutés à votre dossier et transmis à Lift.
</x-mail::panel>

Bien cordialement,

L'équipe SAV Lift Foils France
</x-mail::message>
