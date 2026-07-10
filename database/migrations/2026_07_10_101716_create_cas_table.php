<?php

use App\Enums\StatutCas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cas', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->nullable()->unique();

            $table->string('client_nom')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_telephone')->nullable();

            // Catégorie produit (batterie, télécommande, eBox/ESC, moteur, mât…).
            $table->string('produit')->nullable();
            $table->string('modele')->nullable();
            // Numéro de série Lift, dit « MHS ».
            $table->string('numero_serie')->nullable();
            $table->string('sales_order')->nullable();

            $table->text('description')->nullable();

            $table->string('statut')->default(StatutCas::Nouveau->value);

            $table->string('ticket_lift')->nullable();
            $table->string('tracking')->nullable();

            // Origine du dossier : 'email' (relève IMAP, Bloc 1) ou 'manuel'.
            $table->string('source')->default('email');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cas');
    }
};
