<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cas_id')->constrained('cas')->cascadeOnDelete();

            // Le Message-ID RFC 5322, sans ses chevrons. Unique : c'est la clé
            // de déduplication de la relève IMAP, qui peut repasser plusieurs
            // fois sur le même mail (fenêtre glissante, relance après crash).
            $table->string('message_id')->unique();

            $table->string('in_reply_to')->nullable()->index();
            // `references` est un mot réservé SQL : on préfixe.
            $table->text('email_references')->nullable();

            $table->string('direction');

            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('to_email')->nullable();

            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            $table->timestamp('received_at');

            $table->timestamps();

            $table->index(['cas_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
