<?php

namespace Modules\SuperPdp\Support;

use App\Models\Company;

/** The Invoice Ninja company the SUPER PDP account belongs to. */
class Companies
{
    public static function target(): ?Company
    {
        if ($key = config('superpdp.company_key')) {
            return Company::where('company_key', $key)->first();
        }

        // Without SUPERPDP_COMPANY_KEY only a single-company instance is unambiguous.
        return Company::query()->limit(2)->get()->count() === 1 ? Company::first() : null;
    }

    public static function allows(int $companyId): bool
    {
        return self::target()?->id === $companyId;
    }
}
