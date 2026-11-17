<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename Expedia → Ticketoc and VRBO → Agoda portal integrations.
     */
    public function up(): void
    {
        $this->renamePortalKey('expedia', 'ticketoc');
        $this->renamePortalKey('vrbo', 'agoda');

        if (Schema::hasTable('bookings')) {
            DB::table('bookings')->where('source', 'expedia')->update(['source' => 'ticketoc']);
            DB::table('bookings')->where('source', 'vrbo')->update(['source' => 'agoda']);
        }
    }

    public function down(): void
    {
        $this->renamePortalKey('ticketoc', 'expedia');
        $this->renamePortalKey('agoda', 'vrbo');

        if (Schema::hasTable('bookings')) {
            DB::table('bookings')->where('source', 'ticketoc')->update(['source' => 'expedia']);
            DB::table('bookings')->where('source', 'agoda')->update(['source' => 'vrbo']);
        }
    }

    private function renamePortalKey(string $from, string $to): void
    {
        if (! Schema::hasTable('booking_portal_connections')) {
            return;
        }

        $rows = DB::table('booking_portal_connections')
            ->where('portal_key', $from)
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('booking_portal_connections')
                ->where('user_uuid', $row->user_uuid)
                ->where('portal_key', $to)
                ->exists();

            if ($exists) {
                DB::table('booking_portal_connections')->where('uuid', $row->uuid)->delete();
                continue;
            }

            DB::table('booking_portal_connections')
                ->where('uuid', $row->uuid)
                ->update(['portal_key' => $to]);
        }
    }
};
