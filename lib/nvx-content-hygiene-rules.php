<?php
/**
 * nvx-content-hygiene-rules.php
 *
 * NVX Content Hygiene Rules — Single Source of Truth.
 *
 * Consumed by:
 *   - tools/migrations/audit-content-divergence.php
 *   - tools/migrations/content-hygiene-shared.php
 *   - tools/migrations/content-hygiene-staging-only.php
 *
 * NEVER add mutation logic here. Rule definitions only.
 *
 * @package NVX\Migrations
 * @version 1.2.0
 */

declare( strict_types = 1 );

const NVX_LITERAL_VALORACION = 'valoración médica';
const NVX_LITERAL_CONSULTA   = 'consulta médica';


/**
 * Plain-string replacements applied to wp_posts fields.
 *
 * Ordering rules:
 *   1. Longest / most-specific strings first to prevent partial shadowing.
 *   2. Within the same prefix, dot-terminated variants precede bare ones
 *      (e.g. "Tu mejor versión empieza aquí." before "Tu mejor versión empieza aquí").
 *   3. UPPERCASE variants precede Title-case variants (EXILITET before Exilitet).
 *
 * @return list<array{from: string, to: string}>
 */
function nvx_hygiene_str_reps(): array {
    return [

        // ── /medicina-estetica/ legacy media references ─────────────────────
        // Full-tag matches keep these repairs surgical. Production is already
        // reconciled; shared hygiene makes independent Staging2 DBs converge.
        [
            'from' => '<img class="wp-image-3062" alt="Medicina estética con criterio médico en NUVANX Madrid" decoding="async" loading="eager" src="https://nuvanx.com/wp-content/uploads/2026/07/nuvanx.commedicina-estetica.webp" />',
            'to'   => '<img class="nvx-brand-hero__image" alt="Medicina estética con criterio médico en NUVANX Madrid" decoding="async" loading="eager" src="https://nuvanx.com/wp-content/uploads/2026/07/nuvanx.commedicina-estetica.webp" />',
        ],
        [
            'from' => '<img class="wp-image-1135" src="https://nuvanx.com/wp-content/uploads/2026/04/aumento-labios-nuvanx.webp" alt="Ácido hialurónico - Labios naturales" loading="lazy" decoding="async">',
            'to'   => '<img class="wp-image-1156" src="https://nuvanx.com/wp-content/uploads/2026/04/aumento-labios-2.jpg" alt="Tratamiento médico de labios en NUVANX Madrid" loading="lazy" decoding="async">',
        ],
        [
            'from' => '<img class="wp-image-1133" src="https://nuvanx.com/wp-content/uploads/2026/04/rinomodelacion-nuvanx.webp" alt="Perfil nasal - Rinomodelación sin cirugía" loading="lazy" decoding="async">',
            'to'   => '<img class="wp-image-1134" src="https://nuvanx.com/wp-content/uploads/2026/04/rinomodelacion.jpg" alt="Rinomodelación médica en NUVANX Madrid" loading="lazy" decoding="async">',
        ],
        [
            'from' => '<img class="wp-image-2102" src="https://nuvanx.com/wp-content/uploads/2026/06/tratamiento-ojeras-mirada-nuvanx-madrid.webp" alt="Mirada - Ojeras" loading="lazy" decoding="async">',
            'to'   => '<img class="wp-image-2450" src="https://nuvanx.com/wp-content/uploads/2026/06/Acercamiento-Rostro.jpg" alt="Mirada y contorno periocular en NUVANX Madrid" loading="lazy" decoding="async">',
        ],

        // ── Valoración ───────────────────────────────────────────────────────
        [ 'from' => 'valoración médica gratuita', 'to' => NVX_LITERAL_VALORACION  ],
        [ 'from' => 'valoración gratuita',        'to' => NVX_LITERAL_VALORACION  ],
        [ 'from' => 'valoración gratis',          'to' => NVX_LITERAL_VALORACION  ],

        // ── Consulta ─────────────────────────────────────────────────────────
        [ 'from' => 'consulta médica gratuita',   'to' => NVX_LITERAL_CONSULTA    ],
        [ 'from' => 'consulta gratuita',          'to' => NVX_LITERAL_CONSULTA    ],
        [ 'from' => 'consulta gratis',            'to' => NVX_LITERAL_CONSULTA    ],

        // ── Headline (dot-terminated variant first) ──────────────────────────
        [ 'from' => 'Tu mejor versión empieza aquí.', 'to' => 'Reserva 15–30 min de valoración médica.' ],
        [ 'from' => 'Tu mejor versión empieza aquí',  'to' => 'Reserva 15–30 min de valoración médica'  ],

        // ── Brand ────────────────────────────────────────────────────────────
        [ 'from' => 'EXILITET', 'to' => 'EXILITE™' ],
        [ 'from' => 'Exilitet', 'to' => 'EXILITE™' ],

        // ── CTA ──────────────────────────────────────────────────────────────
        // "Solicitar." with period first to avoid matching the bare word.
        [ 'from' => 'Solicitar.',                 'to' => 'Solicitar valoración médica' ],

        // ── Claims ───────────────────────────────────────────────────────────
        [ 'from' => 'enfoque médico premium',     'to' => 'misma dirección médica que Chamberí' ],
        [ 'from' => 'presupuestos personalizados','to' => 'presupuesto individualizado tras la valoración médica' ],
        [ 'from' => 'sin compromiso',             'to' => 'sin obligación de continuar con un tratamiento' ],

    ];
}

/**
 * PCRE-regex replacements (patterns without delimiters; flags applied per-rule).
 *
 * Used for accent/entity variants and governed HTML cleanup that plain
 * str_replace cannot safely express.
 *
 * @return list<array{pattern: string, replacement: string, flags: string}>
 */
function nvx_hygiene_regex_reps(): array {
    return [
        // Handles é/e, ó/o, í/i mixed with HTML entities or incorrect encoding.
        [
            'pattern'     => '\bvaloraci[oó]n\s+m[eé]dica\s+gratu[íi]ta\b',
            'replacement' => NVX_LITERAL_VALORACION,
            'flags'       => 'iu',
        ],
        [
            'pattern'     => '\bconsulta\s+m[eé]dica\s+gratu[íi]ta\b',
            'replacement' => 'consulta médica',
            'flags'       => 'iu',
        ],

        // Canonical structured data is emitted by the governed Yoast @graph.
        // Remove only persisted application/ld+json blocks whose own script
        // body contains a Schema.org signature. The tempered sections prevent
        // a later script on the page from causing a non-Schema JSON-LD block
        // to match accidentally.
        [
            'pattern'     => '<script\b(?=[^>]*\btype\s*=\s*(["\'])application\/ld\+json\1)[^>]*>(?:(?!<\/script>)[\s\S])*(?:schema\.org|@graph\b|["\']@type["\']\s*:)(?:(?!<\/script>)[\s\S])*<\/script>',
            'replacement' => '',
            'flags'       => 'iu',
        ],
    ];
}

/**
 * wp_posts fields that hygiene rules are applied against.
 *
 * @return string[]
 */
function nvx_hygiene_fields(): array {
    return [ 'post_title', 'post_content', 'post_excerpt' ];
}

/**
 * Legal pages that must have exactly one H1 matching the expected text.
 *
 * Key   = post_name (slug)
 * Value = expected H1 text node (no HTML tags)
 *
 * IMPORTANT: The shared migration VERIFIES these; it does NOT overwrite
 * existing correct values. Production already has these correct as of 2026-08-12.
 *
 * @return array<string, string>
 */
function nvx_hygiene_legal_pages(): array {
    return [
        'politica-privacidad' => 'Política de privacidad',
        'aviso-legal'         => 'Aviso legal',
    ];
}

/**
 * Retired internal strategy/prototype pages that must never be public records.
 *
 * These slugs remain redirectable at the HTTP layer for legacy links, but any
 * matching WordPress content record must be in trash so it cannot re-enter the
 * published-page inventory, sitemap generation, navigation or Block C.
 *
 * @return array<string, array{target:string,status:string}>
 */
function nvx_hygiene_retired_strategy_pages(): array {
    return [
        'liposculpt-air' => [
            'target' => '/remodelacion-corporal-laser-madrid/',
            'status' => 'trash',
        ],
        'v-lift-awake' => [
            'target' => '/papada-definicion-mandibular-madrid/',
            'status' => 'trash',
        ],
    ];
}
