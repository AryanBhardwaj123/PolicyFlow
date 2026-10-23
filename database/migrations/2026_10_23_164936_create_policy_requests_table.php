
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->enum('destination', ['DOMESTIC', 'INTERNATIONAL']);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->unsignedInteger('sum_insured');

            $table->decimal('premium', 12, 2);
            $table->string('status', 30)->default('RECEIVED');

            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable()->unique();
            $table->json('last_provider_response')->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['agent_id', 'client_request_id'],
                'policy_requests_agent_client_unique'
            );

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
