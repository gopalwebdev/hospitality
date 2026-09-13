<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * `restaurant.manage` becomes `tenant.manage`.
     *
     * A permission's name is what `can()` checks, so App\Enums\Permission
     * renaming its case is only half the change: the row has to follow, or the
     * product team's panel would refuse everyone but a super admin. Renaming the
     * row in place keeps its id, and with it every role that already holds it.
     */
    public function up(): void
    {
        $this->rename('restaurant.manage', 'tenant.manage');
    }

    public function down(): void
    {
        $this->rename('tenant.manage', 'restaurant.manage');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('permissions')->where('name', $from)->update([
            'name' => $to,
            'updated_at' => now(),
        ]);

        // The registrar caches permissions by name, and this row is in it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
