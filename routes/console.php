<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Relève de la boîte sav@. Tourne dans le conteneur « planificateur » de
 * Coolify (`php artisan schedule:work`), pas dans le conteneur web.
 *
 * withoutOverlapping : une relève lente — une grosse pièce jointe, un SMTP qui
 * traîne — ne doit pas se faire doubler par la suivante, sous peine de voir
 * deux processus ouvrir le même dossier avant que le premier n'ait écrit son
 * Message-ID en base.
 */
Schedule::command('sav:fetch-mail')
    ->everyTwoMinutes()
    ->withoutOverlapping();
