<?php
/**
 * Canonical WebSite schema ownership.
 *
 * Yoast and the NUVANX medical graph can both contribute the canonical
 * `/#website` node. Merge those contributions before the final @id dedupe so
 * output does not depend on filter insertion order and Yoast properties such as
 * SearchAction/inLanguage are not lost.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine whether an array uses sequential integer keys.
 *
 * @param array $value Array to inspect.
 * @return bool
 */
function nvx_schema_is_list( array $value ): bool {
	return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
}

/**
 * Deep-merge associative schema objects while replacing scalar and list values.
 *
 * @param array $base     Existing schema value.
 * @param array $override Later schema value.
 * @return array
 */
function nvx_schema_deep_merge( array $base, array $override ): array {
	foreach ( $override as $key => $value ) {
		if (
			isset( $base[ $key ] ) &&
			is_array( $base[ $key ] ) &&
			is_array( $value ) &&
			! nvx_schema_is_list( $base[ $key ] ) &&
			! nvx_schema_is_list( $value )
		) {
			$base[ $key ] = nvx_schema_deep_merge( $base[ $key ], $value );
			continue;
		}

		$base[ $key ] = $value;
	}

	return $base;
}

/**
 * Normalize one action object or a list of action objects to a list.
 *
 * @param array $actions Action value.
 * @return array<int,array>
 */
function nvx_schema_action_list( array $actions ): array {
	return nvx_schema_is_list( $actions ) ? $actions : array( $actions );
}

/**
 * Preserve distinct WebSite actions and merge contributions to the same type.
 *
 * @param array $base     Existing action value.
 * @param array $override Later action value.
 * @return array<int,array>
 */
function nvx_schema_merge_actions( array $base, array $override ): array {
	$merged = nvx_schema_action_list( $base );

	foreach ( nvx_schema_action_list( $override ) as $action ) {
		if ( ! is_array( $action ) ) {
			continue;
		}

		$action_type = $action['@type'] ?? null;
		$match_key   = null;
		foreach ( $merged as $key => $existing ) {
			if ( is_array( $existing ) && null !== $action_type && ( $existing['@type'] ?? null ) === $action_type ) {
				$match_key = $key;
				break;
			}
		}

		if ( null === $match_key ) {
			$merged[] = $action;
		} else {
			$merged[ $match_key ] = nvx_schema_deep_merge( $merged[ $match_key ], $action );
		}
	}

	return array_values( $merged );
}

/**
 * Determine whether a graph node is the canonical WebSite node.
 *
 * @param mixed  $node       Graph node.
 * @param string $website_id Canonical WebSite identifier.
 * @return bool
 */
function nvx_schema_is_canonical_website_node( $node, string $website_id ): bool {
	if ( ! is_array( $node ) || ( $node['@id'] ?? '' ) !== $website_id ) {
		return false;
	}

	$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
	return in_array( 'WebSite', $types, true );
}

/**
 * Merge one later WebSite contribution into the accumulated node.
 *
 * @param array $merged Existing canonical node.
 * @param array $node   Later node contribution.
 * @return array
 */
function nvx_schema_merge_website_node( array $merged, array $node ): array {
	$base_actions     = isset( $merged['potentialAction'] ) && is_array( $merged['potentialAction'] ) ? $merged['potentialAction'] : array();
	$override_actions = isset( $node['potentialAction'] ) && is_array( $node['potentialAction'] ) ? $node['potentialAction'] : array();
	$merged           = nvx_schema_deep_merge( $merged, $node );

	if ( $base_actions && $override_actions ) {
		$merged['potentialAction'] = nvx_schema_merge_actions( $base_actions, $override_actions );
	}

	return $merged;
}

/**
 * Merge duplicate canonical WebSite nodes into their first graph position.
 *
 * Only performs merge when multiple WebSite nodes exist to avoid creating
 * duplicates or unnecessary processing when there's only one node.
 *
 * @param array $graph Yoast schema graph.
 * @return array
 */
function nvx_schema_merge_canonical_website_nodes( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() || ! is_front_page() ) {
		return $graph;
	}

	$website_id = home_url( '/#website' );
	$website_nodes = array();

	// First, collect all WebSite nodes
	foreach ( $graph as $key => $node ) {
		if ( nvx_schema_is_canonical_website_node( $node, $website_id ) ) {
			$website_nodes[ $key ] = $node;
		}
	}

	// Only merge if there are multiple WebSite nodes (avoid creating duplicates)
	if ( count( $website_nodes ) > 1 ) {
		$first_key  = array_key_first( $website_nodes );
		$merged     = $website_nodes[ $first_key ];

		foreach ( $website_nodes as $key => $node ) {
			if ( $key === $first_key ) {
				continue;
			}
			$merged = nvx_schema_merge_website_node( $merged, $node );
			unset( $graph[ $key ] );
		}

		$graph[ $first_key ] = $merged;
	}

	return array_values( $graph );
}
nvx_add_filter_with_priority( 'wpseo_schema_graph', 'nvx_schema_merge_canonical_website_nodes' );

/**
 * Add SiteLinksSearchBox to the canonical WebSite schema.
 */

/**
 * Add SiteLinksSearchBox to the canonical WebSite schema.
 */
function nvx_schema_add_sitelinks_searchbox( array $data ): array {
	$new_action = array(
		'@type'       => 'SearchAction',
		'target'      => array(
			'@type'       => 'EntryPoint',
			'urlTemplate' => home_url( '/?s={search_term_string}' )
		),
		'query-input' => 'required name=search_term_string',
	);

	if ( ! isset( $data['potentialAction'] ) ) {
		$data['potentialAction'] = array( $new_action );
	} else {
		$data['potentialAction'] = nvx_schema_merge_actions( $data['potentialAction'], $new_action );
	}

	return $data;
}
add_filter( 'wpseo_schema_website', 'nvx_schema_add_sitelinks_searchbox', 11 );
