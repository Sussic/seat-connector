<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Seat\Services\Settings\Profile;

return new class extends Migration
{
    private const SETTING = 'seat-connector.discord_strict_exempt_characters';

    public function up(): void
    {
        // Kept separate from schema creation: MariaDB DDL implicitly commits.
        DB::transaction(function (): void {
            $seen_users = [];
            DB::table('user_settings')->where('name', self::SETTING)->orderBy('id')
                ->chunkById(200, function ($settings) use (&$seen_users): void {
                    foreach ($settings as $setting) {
                        $user_id = (int) $setting->user_id;
                        if (isset($seen_users[$user_id])) {
                            continue;
                        }
                        $seen_users[$user_id] = true;

                        // Legacy settings can have duplicate rows. Use the first
                        // effective value rather than unioning stale selections.
                        $ids = json_decode($setting->value);
                        if (! is_array($ids) || ! DB::table('users')->where('id', $user_id)->exists()) {
                            continue;
                        }
                        foreach ($ids as $id) {
                            if (! is_int($id) || $id < 1) {
                                continue 2;
                            }
                        }

                        $owned_ids = DB::table('refresh_tokens')->where('user_id', $user_id)
                            ->whereIn('character_id', array_values(array_unique($ids)))
                            ->pluck('character_id');

                        foreach ($owned_ids as $character_id) {
                            DB::table('seat_connector_strict_exemptions')->insertOrIgnore([
                                'user_id' => $user_id,
                                'character_id' => (int) $character_id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        // Export current selections, including empty lists, before the earlier
        // schema migration removes the new table. This prevents stale legacy
        // selections from reappearing after a rollback.
        $user_ids = DB::table('user_settings')->where('name', self::SETTING)->pluck('user_id')
            ->merge(DB::table('seat_connector_strict_exemptions')->pluck('user_id'))
            ->map(fn($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($user_ids): void {
            foreach ($user_ids as $user_id) {
                $ids = DB::table('seat_connector_strict_exemptions as exemptions')
                    ->join('refresh_tokens as tokens', 'tokens.character_id', '=', 'exemptions.character_id')
                    ->where('exemptions.user_id', $user_id)->where('tokens.user_id', $user_id)
                    ->orderBy('exemptions.character_id')->pluck('exemptions.character_id')
                    ->map(fn($id): int => (int) $id)->all();

                DB::table('user_settings')->where('user_id', $user_id)->where('name', self::SETTING)->delete();

                if (DB::table('users')->where('id', $user_id)->exists()) {
                    DB::table('user_settings')->insert([
                        'user_id' => $user_id, 'name' => self::SETTING,
                        'value' => json_encode($ids), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        foreach ($user_ids as $user_id) {
            Cache::forget(Profile::get_key_prefix(self::SETTING, $user_id));
        }
    }
};
