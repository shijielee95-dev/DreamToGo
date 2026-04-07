<?php
/**
 * config/lhdn.php
 * ─────────────────────────────────────────────
 * LHDN MyInvois API configuration.
 * Switch LHDN_ENV to 'production' when ready.
 * ─────────────────────────────────────────────
 */

define('LHDN_ENV', 'sandbox'); // 'sandbox' | 'production'

const LHDN = [
    'sandbox' => [
        'identity_url' => 'https://preprod-api.myinvois.hasil.gov.my/connect/token',
        'api_base'     => 'https://preprod-api.myinvois.hasil.gov.my',
        'client_id'    => '',   // fill from company_details
        'client_secret'=> '',   // fill from company_details
    ],
    'production' => [
        'identity_url' => 'https://api.myinvois.hasil.gov.my/connect/token',
        'api_base'     => 'https://api.myinvois.hasil.gov.my',
        'client_id'    => '',
        'client_secret'=> '',
    ],
];

function lhdn(): array {
    return LHDN[LHDN_ENV];
}
