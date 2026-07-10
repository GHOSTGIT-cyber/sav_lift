<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piece_jointes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cas_id')->constrained('cas')->cascadeOnDelete();

            // Attention à l'homonymie : ici `message_id` est la clé étrangère
            // vers messages.id (un entier), pas le Message-ID RFC de l'email
            // (qui vit dans messages.message_id).
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            // Chemin relatif sur le disque `local` (privé), sous sav/{cas_id}/.
            $table->string('path');

            // Nom d'origine tel qu'annoncé par l'email — jamais utilisé comme
            // chemin sur le disque (voir App\Support\NomFichier).
            $table->string('filename');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('taille')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_jointes');
    }
};
