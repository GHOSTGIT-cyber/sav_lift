<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloc 2 — champs alimentés par l'extraction IA.
 *
 * produit / modele / numero_serie / sales_order existent déjà (Bloc 0) : on
 * ajoute seulement ce qui manque. Tout est nullable / à défaut faux :
 * rétro-compatible, les dossiers existants restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            // Contexte d'apparition résumé par l'IA (choc, eau, transport, depuis quand).
            $table->text('contexte')->nullable()->after('description');

            // Indice d'urgence détecté (sécurité, batterie qui gonfle, départ imminent).
            // L'humain tranche ; ce n'est qu'un signal.
            $table->boolean('urgent')->default(false)->after('contexte');

            // Dossier « actionnable » : produit + MHS présents (règle V1). Dérivé de
            // l'extraction, stocké pour filtrer/trier la liste sans recalcul.
            $table->boolean('complet')->default(false)->after('urgent');

            // Dernière extraction réussie, et dernière erreur d'extraction s'il y en a
            // une — pour que l'humain voie qu'un dossier n'a pas pu être enrichi.
            $table->timestamp('extrait_le')->nullable()->after('complet');
            $table->text('extraction_erreur')->nullable()->after('extrait_le');
        });
    }

    public function down(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            $table->dropColumn(['contexte', 'urgent', 'complet', 'extrait_le', 'extraction_erreur']);
        });
    }
};
