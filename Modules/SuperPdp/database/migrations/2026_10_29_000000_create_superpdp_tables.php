<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Invoice Ninja invoice/credit handed to SUPER PDP.
        Schema::create('superpdp_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('entity_type', 16);
            $table->unsignedInteger('entity_id');
            $table->string('number')->nullable();
            $table->string('processing_rule', 16)->nullable();
            $table->unsignedBigInteger('superpdp_id')->nullable()->index();
            // queued, sent, delivered, rejected, failed, skipped
            $table->string('status', 16)->default('queued');
            $table->string('last_event_code', 64)->nullable();
            $table->string('last_event_text')->nullable();
            $table->text('error')->nullable();
            // VAT groups of the uploaded invoice, used to split payments.
            $table->json('vat_breakdown')->nullable();
            $table->decimal('total_with_vat', 20, 2)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
        });

        // fr:212 "Encaissée" statuses sent for a payment applied to an invoice.
        Schema::create('superpdp_payment_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('payment_id');
            $table->unsignedInteger('invoice_id');
            $table->unsignedBigInteger('superpdp_invoice_id');
            $table->decimal('amount', 20, 2);
            $table->date('paid_on');
            $table->unsignedBigInteger('superpdp_event_id')->nullable();
            // sent, failed
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
        });

        // Invoices received on SUPER PDP and imported as expenses.
        Schema::create('superpdp_incoming', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedBigInteger('superpdp_id')->unique();
            $table->string('number')->nullable();
            $table->string('seller_name')->nullable();
            $table->unsignedInteger('expense_id')->nullable();
            // imported, failed
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Sync cursors and the install date.
        Schema::create('superpdp_state', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });

        DB::table('superpdp_state')->insert([
            'key' => 'installed_on',
            'value' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('superpdp_state');
        Schema::dropIfExists('superpdp_incoming');
        Schema::dropIfExists('superpdp_payment_reports');
        Schema::dropIfExists('superpdp_documents');
    }
};
