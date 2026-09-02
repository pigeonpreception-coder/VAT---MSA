<?php

namespace App\Support\Business;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;

/**
 * Ported from lib/data/identity-repository.ts's classifyTransaction (Module
 * 1 Buyer/Seller ClassifyTransaction) -- a pre-flight check for whether a
 * VAT number would resolve as a valid transaction counterparty, using the
 * exact same taxpayer/organisation/capability resolution rules invoice
 * certification already enforces (see InvoiceService::resolveCapableTaxpayer),
 * single-sourced here rather than duplicated. This is the one function
 * pulled out of `identity-repository.ts` -- the rest of that file remains
 * unported (see docs/MIGRATION_MATRIX.md). Reveals nothing a caller
 * couldn't already learn indirectly from a rejected invoice submission, so
 * no tenant scoping is required: a cross-tenant, public-posture lookup by
 * design, not a privilege boundary.
 */
class TransactionClassifier
{
    /** @return array{vat_number: string, taxpayer_active: bool, organisation_active: bool, capabilities: list<string>, can_act_as_seller: bool, can_act_as_buyer: bool} */
    public static function classify(string $vatNumber): array
    {
        $vatNumber = mb_strtoupper(trim($vatNumber));
        $taxpayer = Taxpayer::where('vat_number', $vatNumber)->where('vat_status', 'ACTIVE')->first();
        if (! $taxpayer) {
            return ['vat_number' => $vatNumber, 'taxpayer_active' => false, 'organisation_active' => false, 'capabilities' => [], 'can_act_as_seller' => false, 'can_act_as_buyer' => false];
        }
        $organisation = Organisation::where('taxpayer_id', $taxpayer->id)->where('status', 'ACTIVE')->first();
        if (! $organisation) {
            return ['vat_number' => $vatNumber, 'taxpayer_active' => true, 'organisation_active' => false, 'capabilities' => [], 'can_act_as_seller' => false, 'can_act_as_buyer' => false];
        }
        $now = now();
        $capabilities = OrganisationCapability::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->pluck('capability')->all();

        return [
            'vat_number' => $vatNumber, 'taxpayer_active' => true, 'organisation_active' => true, 'capabilities' => $capabilities,
            'can_act_as_seller' => in_array('SELLER', $capabilities, true), 'can_act_as_buyer' => in_array('BUYER', $capabilities, true),
        ];
    }
}
