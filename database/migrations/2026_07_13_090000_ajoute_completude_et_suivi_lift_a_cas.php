<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloc 4 — règle de complétude « pièces obligatoires » + suivi du dossier chez Lift.
 *
 * Additive, comme les précédentes : tout est nullable, les dossiers existants
 * restent valides (leur `complet` sera recalculé au prochain enregistrement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            // Indice de garantie, extrait verbatim (« juillet 2024 », « 12/03/2025 ») :
            // une date libre, jamais interprétée — c'est l'humain qui tranche.
            $table->string('date_achat')->nullable()->after('sales_order');

            /*
             * Les trois pièces que seul un œil humain peut vraiment valider.
             * Trois états :
             *   null  → on s'en remet à la présomption tirée des pièces jointes
             *           (voir App\Models\Cas::aPhotoEtiquette & co.) ;
             *   true  → un humain a vu la pièce, elle est bonne ;
             *   false → un humain a vu qu'elle manque, ou qu'elle est illisible.
             *
             * Sans ça, « photo LISIBLE de l'étiquette » serait indécidable : aucun
             * code ne sait ce que montre un JPEG.
             */
            $table->boolean('photo_etiquette')->nullable()->after('date_achat');
            $table->boolean('preuve_achat')->nullable()->after('photo_etiquette');
            $table->boolean('photos_defaut')->nullable()->after('preuve_achat');

            // Dernier mail « il nous manque ceci » envoyé au client.
            $table->timestamp('relance_client_le')->nullable()->after('extraction_erreur');

            // Quand le dossier est parti chez Lift, quand Lift a répondu, et quand
            // le client a été prévenu de la transmission. Ces trois dates font
            // avancer l'écran toutes seules (bandeau « Prochaine action »).
            $table->timestamp('envoye_lift_le')->nullable()->after('statut_lift');
            $table->timestamp('reponse_lift_le')->nullable()->after('envoye_lift_le');
            $table->timestamp('client_avise_lift_le')->nullable()->after('reponse_lift_le');
        });
    }

    public function down(): void
    {
        Schema::table('cas', function (Blueprint $table) {
            $table->dropColumn([
                'date_achat',
                'photo_etiquette',
                'preuve_achat',
                'photos_defaut',
                'relance_client_le',
                'envoye_lift_le',
                'reponse_lift_le',
                'client_avise_lift_le',
            ]);
        });
    }
};
