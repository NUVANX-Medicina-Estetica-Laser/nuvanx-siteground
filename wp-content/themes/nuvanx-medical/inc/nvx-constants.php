<?php
/**
 * Global registry for the_content filter priorities.
 *
 * This file maps all hook priorities to named constants to provide a
 * self-documenting record of hook priorities without magic numbers.
 *
 * ARCHITECTURE NOTE — Collisions are intentional and load-order dependent:
 * Several groups of constants share the same integer value (e.g. block-19
 * restructuradores, block-99 governance, block-21 signature). Within each
 * group, WordPress executes callbacks in the order they were registered via
 * add_filter(), which is determined by the require_once sequence in
 * functions.php. Renaming files or reordering the bootstrap WILL silently
 * change render order. To achieve true determinism, space these values apart.
 *
 * NOTE: This scope currently covers ONLY the_content filters. Other priority
 * graphs (e.g. wpseo_metadesc, template_include) are deferred as future debt.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Robots Policy Directives
// -----------------------------------------------------------------------------
const NVX_ROBOTS_INHERIT          = 0;
const NVX_ROBOTS_INDEX_FOLLOW     = 1;
const NVX_ROBOTS_NOINDEX_FOLLOW   = 2;
const NVX_ROBOTS_NOINDEX_NOFOLLOW = 3;

// Early Content Modifiers
const NVX_HOOK_PRIO_JSONLD_STRIP   = 5;
const NVX_HOOK_PRIO_BLOG_HEADINGS = 8;
const NVX_HOOK_PRIO_BLOG_BYLINES  = 9;

// Page & Hub Rendering
const NVX_HOOK_PRIO_VALORACION_MANAGED = 10;
const NVX_HOOK_PRIO_CLINICS_HUB        = 11;
const NVX_HOOK_PRIO_SOLUTIONS_PAGE     = 11;
const NVX_HOOK_PRIO_HERO_MEDIA         = 12;

// Internal Links & Form Hooks
const NVX_HOOK_PRIO_INTERNAL_LINKS        = 13;
const NVX_HOOK_PRIO_VALORACION_FORM_FIRST = 14;
const NVX_HOOK_PRIO_VALORACION_FORM_CLASS = 15;
const NVX_HOOK_PRIO_EXTERNAL_LINKS_REL    = 15; // Intentional collision: performance rel hardening follows registration order.
const NVX_HOOK_PRIO_VALORACION_ENHANCE    = 16;
const NVX_HOOK_PRIO_TREATMENTS_INDEX      = 18;

// Specific Restructuradores (Block 19)
const NVX_HOOK_PRIO_AESTHETIC_MEDICINE = 19;
const NVX_HOOK_PRIO_BTL_DETAIL         = 19;
const NVX_HOOK_PRIO_CO2_MODULE         = 19;
const NVX_HOOK_PRIO_ENDOLASER          = 19;
const NVX_HOOK_PRIO_ENDOLIFT           = 19;
const NVX_HOOK_PRIO_EQUIPO             = 19;
const NVX_HOOK_PRIO_LASER_MEDICINE     = 19;
const NVX_HOOK_PRIO_NOSOTROS           = 19;
const NVX_HOOK_PRIO_PROFIHILO_MODULE   = 19;

// Global Enhancements (priority 20 collisions are intentional and bootstrap-order dependent).
const NVX_HOOK_PRIO_PRESENTATION_ENHANCE  = 20;
const NVX_HOOK_PRIO_CONTACT_MAPS          = 20;
const NVX_HOOK_PRIO_SIGNATURE_PHASE_MARKUP = 20;
const NVX_HOOK_PRIO_REMOVE_MISSING_LOCAL_IMAGES = 20;
const NVX_HOOK_PRIO_GLOBAL_TREATMENT      = 21;
const NVX_HOOK_PRIO_SIGNATURE_HUB         = 21;
const NVX_HOOK_PRIO_TRUST_BADGES          = 22;
const NVX_HOOK_PRIO_PRIORITY_TREATMENT_LINKS = 24;

// Layout Cleanups
const NVX_HOOK_PRIO_SEDE_INLINE_STYLES = 28;
const NVX_HOOK_PRIO_BRIDAL_MEDIA       = 29;
const NVX_HOOK_PRIO_CLINICS_ENHANCE    = 30;

// High Priority Overrides
const NVX_HOOK_PRIO_AESTHETIC_TREATMENT   = 80;
const NVX_HOOK_PRIO_STRATEGY_PAGES        = 82;
const NVX_HOOK_PRIO_JOURNAL_TECH_ARTICLE  = 83;

// Governance & Rules
const NVX_HOOK_PRIO_ENDOLIFT_AUTHORITY_GRAPH = 97;
const NVX_HOOK_PRIO_CLINICAL_EVIDENCE        = 98;

// Governance & Rules (Block 99)
const NVX_HOOK_PRIO_BUSINESS_RULES  = 99;
const NVX_HOOK_PRIO_STRIP_PAGE_CTAS = 99;
const NVX_HOOK_PRIO_BTL_GOVERNANCE  = 99;

// Late Hijacks & Enforcements
const NVX_HOOK_PRIO_DR_RIVERA                  = 121;
const NVX_HOOK_PRIO_QUE_EXIGIR                 = 122;
const NVX_HOOK_PRIO_EXION_INVESTMENT           = 126;
const NVX_HOOK_PRIO_MEDICAL_REVIEW             = 144;
const NVX_HOOK_PRIO_CLINICAL_AUTHORITY_BYLINE  = 145;
const NVX_HOOK_PRIO_TREATMENT_AUTHORITY_CASES  = 146;

// Late Editorial / Media Governance
const NVX_HOOK_PRIO_AUTHENTIC_PHOTOGRAPHY   = 175;
const NVX_HOOK_PRIO_PUBLIC_VENDOR_IMAGES     = 198;
const NVX_HOOK_PRIO_CLINIC_VENDOR_PACKSHOTS = 199;
const NVX_HOOK_PRIO_RESPONSIVE_IMAGES        = 200;
const NVX_HOOK_PRIO_MAPS_IFRAMES             = 201;
const NVX_HOOK_PRIO_INVALID_LIST_ROLES       = 202;

// Extreme Late Normalization
const NVX_HOOK_PRIO_SIGNATURE_NAMES                   = 219;
const NVX_HOOK_PRIO_CLINICS_APPROVED_EQUIPMENT        = 220;
const NVX_HOOK_PRIO_BRAND_WRAPPER_NORMALIZE           = PHP_INT_MAX;
const NVX_HOOK_PRIO_MANAGED_COMPONENT_PROSE_WRAPPER   = PHP_INT_MAX; // Intentional collision; load order determines final normalizer order.
