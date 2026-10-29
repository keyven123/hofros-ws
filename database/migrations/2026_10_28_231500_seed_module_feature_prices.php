<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_features')
            ->whereIn('key', ['sms_notifications', 'advanced_analytics'])
            ->update(['is_active' => false, 'updated_at' => now()]);

        $now = now();
        $modules = [
            ['dashboard', 'Home', 'The merchant home dashboard.', 0],
            ['calendar', 'Calendar', 'Availability calendar for rooms.', 99],
            ['bookings', 'Bookings', 'Reservation list and booking actions.', 149],
            ['discount', 'Discount', 'Discount codes for guests.', 99],
            ['messages', 'Messages', 'Guest messaging.', 99],
            ['customers', 'Customers', 'Guest profiles and stay history.', 149],
            ['analytics', 'Analytics', 'Occupancy and revenue reports.', 199],
            ['portals', 'Booking Portals', 'The public booking website.', 199],
            ['configuration', 'Configuration', 'Properties, rooms, and rates.', 99],
            ['advanced', 'Advanced', 'Profile, users, payments, and billing.', 249],
        ];

        foreach ($modules as $order => [$key, $name, $description, $price]) {
            $existing = DB::table('subscription_features')->where('key', $key)->first();
            if ($existing) {
                DB::table('subscription_features')->where('key', $key)->update([
                    'name' => $name,
                    'description' => $description,
                    'is_active' => true,
                    'sort_order' => $order + 1,
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('subscription_features')->insert([
                'uuid' => (string) Str::uuid(),
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'monthly_price' => $price,
                'currency' => 'PHP',
                'is_active' => true,
                'sort_order' => $order + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subscription_features')
            ->whereIn('key', [
                'dashboard', 'calendar', 'bookings', 'discount', 'messages',
                'customers', 'analytics', 'portals', 'configuration', 'advanced',
            ])
            ->delete();

        DB::table('subscription_features')
            ->whereIn('key', ['sms_notifications', 'advanced_analytics'])
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
