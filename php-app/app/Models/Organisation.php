<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasUuids;

    protected $fillable = ['taxpayer_id', 'tax_authority_id', 'legal_name', 'trading_name', 'status'];

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    /**
     * Multi-tenant SaaS pivot phase 2 (2026-09-24): which licensed
     * national platform this organisation belongs to -- see
     * database/migrations/2026_09_24_000001_add_tax_authority_id_to_organisations_table.php's
     * own doc comment. Defaults to NamRA at the database level, so every
     * organisation created before this column existed, and every test
     * fixture that never names one, still resolves to today's only
     * tenant without any code change required here.
     */
    public function taxAuthority(): BelongsTo
    {
        return $this->belongsTo(TaxAuthority::class);
    }

    /**
     * Multi-tenant SaaS pivot phase 3 (2026-09-24): the country/jurisdiction
     * code this organisation's licensed tax authority actually operates in
     * (e.g. 'NA'), resolved through taxAuthority()->jurisdiction() rather
     * than the hardcoded 'NA' literal VatLifecycleService::generateReturn()
     * used to filter tax_rule_sets by. The '??' fallback is defensive only:
     * tax_authority_id is NOT NULL with a real FK (see the phase 2
     * migration's own doc comment), so the chain always resolves today --
     * if it somehow didn't, falling back to Namibia/NAMRA matches that
     * column's own DB-level DEFAULT, the current single tenant.
     */
    public function jurisdictionCountryCode(): string
    {
        return $this->taxAuthority?->jurisdiction?->country_code ?? 'NA';
    }

    /**
     * Multi-tenant SaaS pivot phase 3 (2026-09-24): the currency this
     * organisation's licensed tax authority actually certifies invoices in,
     * resolved the same way jurisdictionCountryCode() is -- see that
     * method's own doc comment. Used by InvoiceService::submit() in place
     * of InvoiceCalculator's previously hardcoded 'NAD' literal.
     */
    public function currencyCode(): string
    {
        return $this->taxAuthority?->jurisdiction?->country?->currency_code ?? 'NAD';
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(OrganisationCapability::class);
    }
}
