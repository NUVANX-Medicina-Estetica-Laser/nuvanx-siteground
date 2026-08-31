<?php
/**
 * Canonical server-side marketing-consent authority.
 *
 * Browser fields are informational only. Attribution transport decisions must
 * use this server-verifiable Complianz state so the direct form, secure HubSpot
 * bridge and Supabase relay cannot disagree.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether the current request has server-verifiable marketing consent. */
function nvx_marketing_consent_granted(): bool {
	if ( function_exists( 'cmplz_has_consent' ) ) {
		return cmplz_has_consent( 'marketing' ) === true;
	}

	$cookie = isset( $_COOKIE['cmplz_marketing'] )
		? sanitize_text_field( wp_unslash( (string) $_COOKIE['cmplz_marketing'] ) )
		: '';

	return 'allow' === strtolower( trim( $cookie ) );
}
