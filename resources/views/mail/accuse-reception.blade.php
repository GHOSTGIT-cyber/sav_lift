{{--
    Texte de l'accusé de réception automatique (cahier des charges).
    Il vit ici, et pas dans le code, pour être relu et retouché sans déploiement
    de logique. Toute modification est visible immédiatement par le client.
--}}
<x-mail::message>
# Bonjour{{ $cas->client_nom ? ' '.$cas->client_nom : '' }},

Nous avons bien reçu votre demande de service après-vente et nous vous en remercions.

Votre dossier porte la référence **{{ $cas->reference }}**. Merci de la rappeler dans vos prochains échanges.

Afin que nous puissions traiter votre demande au plus vite, merci de nous transmettre
les éléments suivants s'ils ne figuraient pas déjà dans votre message :

- **Vos coordonnées complètes** : nom, adresse postale et numéro de téléphone.
- **Le modèle exact** du produit concerné (planche, batterie, télécommande, eBox/ESC, moteur, mât, chargeur, foil…).
- **Le numéro de série (MHS)** de l'élément concerné.
- **Une photo nette de l'étiquette** portant ce numéro de série.
- **La facture d'achat** ou, à défaut, le numéro de commande (Sales Order).
- **Une description précise du problème** rencontré.
- **Des photos et/ou une vidéo** montrant le défaut.
- **Le contexte d'apparition** du problème : depuis quand, dans quelles conditions d'utilisation,
  après un choc, une immersion prolongée ou un transport ?

Ces informations nous sont indispensables pour ouvrir le dossier auprès de Lift Foils
et déterminer la prise en charge applicable.

<x-mail::panel>
Merci de **centraliser tous vos échanges sur l'adresse {{ config('sav.mailbox') }}**,
en répondant directement à ce message. Les demandes envoyées à une autre adresse
ou par un autre canal risquent de ne pas être rattachées à votre dossier.
</x-mail::panel>

Nous revenons vers vous dès que votre dossier a été étudié.

Cordialement,

L'équipe SAV — {{ config('mail.from.name') }}
</x-mail::message>
