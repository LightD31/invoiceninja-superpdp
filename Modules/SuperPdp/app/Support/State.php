<?php

namespace Modules\SuperPdp\Support;

use Illuminate\Support\Facades\DB;

/** Key/value rows of superpdp_state (sync cursors, install date). */
class State
{
    public static function get(string $key, ?string $default = null): ?string
    {
        return DB::table('superpdp_state')->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, string|int $value): void
    {
        DB::table('superpdp_state')->updateOrInsert(
            ['key' => $key],
            ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** First day covered by automatic sending and importing (Y-m-d). */
    public static function startDate(): string
    {
        return config('superpdp.start_date') ?: self::get('installed_on', now()->toDateString());
    }
}
