<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloc 3 — brouillon Lift + suivi.
 *
 * ticket_lift / tracking / sales_order existent déjà. On ajoute le brouillon
 * d'e-mail vers Lift (généré par l'IA, jamais envoyé auto) et un statut Lift
 * saisi à la main (le repli du Bloc 3-D : la sync auto Zendesk est fermée côté
 * Lift, cf. CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            // Brouillon d'e-mail EN vers help@liftfoils.com, stocké, jamais envoyé
            // automatiquement (validation humaine + garde-fou SAV_ENVOI_ACTIF).
            $table->longText('brouillon_lift')->nullable()->after('tracking');
            $table->timestamp('brouillon_lift_le')->nullable()->after('brouillon_lift');

            // Statut du ticket côté Lift (open/solved/…), saisi à la main dans le
            // repli. Prêt pour une future sync si Lift ouvre un accès API.
            $table->string('statut_lift')->nullable()->after('brouillon_lift_le');
        });
    }

    public function down(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            $table->dropColumn(['brouillon_lift', 'brouillon_lift_le', 'statut_lift']);
        });
    }
};
