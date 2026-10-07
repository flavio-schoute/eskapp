<?php

namespace Database\Seeders;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Models\Affiliate;
use Illuminate\Database\Seeder;

class AffiliateSeeder extends Seeder
{
    /**
     * Seed example affiliates to preview the list and the pipeline.
     */
    public function run(): void
    {
        Affiliate::firstOrCreate(
            ['name' => 'Demo Partner BV'],
            [
                'type' => AffiliateType::Affiliate,
                'status' => AffiliateStatus::Active,
                'login_url' => 'https://partners.demo-partner.test/login',
                'username' => 'eskapp-demo',
                'password' => 'demo-password-123',
                'agreement' => "20% recurring per sale.\nMonthly automatic payout.\nTracking via affiliate link.",
                'payment_method' => AffiliatePaymentMethod::Automatic,
                'affiliate_link' => 'https://demo-partner.test/?ref=eskapp',
                'notes' => 'Dummy record for previewing the affiliates list.',
            ],
        );

        Affiliate::firstOrCreate(
            ['name' => 'Pipeline Prospect BV'],
            [
                'type' => AffiliateType::Partnership,
                'status' => AffiliateStatus::Pipeline,
                'login_url' => 'https://partners.pipeline-prospect.test/login',
                'username' => 'eskapp-prospect',
                'password' => 'demo-password-456',
                'agreement' => 'Proposal: 15% per sale, payout by invoice.',
                'payment_method' => AffiliatePaymentMethod::Invoice,
                'notes' => 'Dummy record for previewing the pipeline block.',
            ],
        );
    }
}
