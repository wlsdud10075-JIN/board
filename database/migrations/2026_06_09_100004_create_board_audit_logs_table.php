<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // append-only — 누가·언제·무엇을(이전값→새값) 변경했는지 기록 (관리자 override 포함)
        Schema::create('board_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('purchase_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');                 // status_change / field_edit / deadline_override
            $table->string('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['purchase_listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_audit_logs');
    }
};
