<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            if (!Schema::hasColumn('agendas', 'forma_pagamento')) {
                $table->string('forma_pagamento')->nullable()->after('status');
            }
            if (!Schema::hasColumn('agendas', 'observacao_final')) {
                $table->text('observacao_final')->nullable()->after('forma_pagamento');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            if (Schema::hasColumn('agendas', 'observacao_final')) {
                $table->dropColumn('observacao_final');
            }
            if (Schema::hasColumn('agendas', 'forma_pagamento')) {
                $table->dropColumn('forma_pagamento');
            }
        });
    }
};
