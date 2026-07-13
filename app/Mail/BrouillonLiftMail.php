<?php

namespace App\Mail;

use App\Models\Cas;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Le mail (anglais) qui ouvre le dossier chez Lift, à help@liftfoils.com.
 *
 * Ce n'est plus un brouillon à recopier : c'est le brouillon relu par un humain
 * puis expédié d'un clic (App\Services\Mail\EnvoiLift). Deux garde-fous restent
 * au-dessus : la validation humaine explicite (bouton + confirmation) et
 * SAV_ENVOI_ACTIF.
 *
 * Partir de l'outil plutôt que de la boîte de Nico n'est pas cosmétique : le mail
 * porte alors NOTRE Message-ID, donc la réponse de Lift — et l'accusé de leur
 * Zendesk, qui porte le n° de ticket — se rattache au dossier par threading, sans
 * qu'on ait à deviner quoi que ce soit.
 *
 * Texte brut, jamais de HTML : c'est un ticket de support, pas une newsletter.
 */
class BrouillonLiftMail extends Mailable
{
    public function __construct(
        public readonly Cas $cas,
        protected readonly string $messageId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->cas->sujetBrouillonLift(),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.brouillon-lift',
            with: ['corps' => $this->cas->corpsBrouillonLift()],
        );
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId);
    }
}
