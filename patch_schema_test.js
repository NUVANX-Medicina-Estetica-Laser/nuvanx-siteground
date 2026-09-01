const fs = require('fs');
let content = fs.readFileSync('scripts/lint/test-schema-semantic-governance.php', 'utf8');

// Insert new nodes into $graph
const newNodes = `
	array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => 'ID-less',
			),
		),
	),
	array(
		'@type'           => array( 'WebPage', 'BreadcrumbList' ),
		'@id'             => 'https://example.test/composite/#node',
		'name'            => 'Composite Node',
	),
`;
content = content.replace(");\\n\\n\\$result = nvx_schema_semantic_normalize_graph", newNodes + ");\\n\\n\\$result = nvx_schema_semantic_normalize_graph");

// Insert new assertions
const newAssertions = `
$id_less = array_filter( $result, function( $n ) { return isset( $n['itemListElement'] ) && 'ID-less' === $n['itemListElement'][0]['name']; } );
nvx_test_assert( 1 === count( $id_less ), 'ID-less nonempty BreadcrumbList must survive' );

$composite = array_filter( $result, function( $n ) { return isset( $n['@id'] ) && 'https://example.test/composite/#node' === $n['@id']; } );
nvx_test_assert( 1 === count( $composite ), 'Composite node must survive even if BreadcrumbList is empty' );
$composite_node = array_pop( $composite );
nvx_test_assert( array( 'WebPage' ) === $composite_node['@type'], 'Empty BreadcrumbList role must be removed from composite node' );
nvx_test_assert( 'Composite Node' === $composite_node['name'], 'Other properties of composite node must survive' );
`;
content = content.replace("nvx_test_assert( ! isset( \\$result[3]['priceRange'] )", newAssertions + "\\nnvx_test_assert( ! isset( \\$result[3]['priceRange'] )");

fs.writeFileSync('scripts/lint/test-schema-semantic-governance.php', content);
