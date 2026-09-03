<?php
/**
 * Canonical server-side marketing-consent authority.
 *
 * Browser fields are informational only. Attribution transport decisions must
 * use the Complianz server API so the direct form, secure HubSpot bridge and
 * Supabase relays cannot disagree or trust a client-forged consent marker.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether the current request has server-resolved marketing consent. */
function nvx_marketing_consent_granted(): bool {
	if ( ! function_exists( 'cmplz_has_consent' ) ) {
		return false;
	}

	return cmplz_has_consent( 'marketing' ) === true;
}
