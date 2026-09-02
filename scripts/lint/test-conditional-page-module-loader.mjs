import fs from 'fs';
import path from 'path';

const THEME_DIR = 'wp-content/themes/nuvanx-medical';
const FUNCTIONS_FILE = path.join(THEME_DIR, 'functions.php');
const LOADER_FILE = path.join(THEME_DIR, 'inc', 'nvx-page-module-loader.php');

function read(file) {
    return fs.readFileSync(file, 'utf8');
}

function pass(name) {
    console.log(`${name}=PASS`);
}

function fail(name, reason) {
    console.error(`${name}=FAIL reason=${reason}`);
    process.exit(1);
}

// 1. Check loader existence and structure
if (!fs.existsSync(LOADER_FILE)) fail('PAGE_MODULE_LOADER_PRESENT', 'not_found');
const loaderContent = read(LOADER_FILE);

pass('PAGE_MODULE_LOADER_PRESENT');

if (loaderContent.includes('nvx_get_canonical_page_registry')) {
    pass('PAGE_MODULE_LOADER_USES_CANONICAL_REGISTRY');
} else {
    fail('PAGE_MODULE_LOADER_USES_CANONICAL_REGISTRY', 'missing_call');
}

if (loaderContent.includes("add_action(\n\t\t'parse_request',") || loaderContent.includes("add_action( 'parse_request'")) {
    pass('PAGE_MODULE_LOADER_PARSE_REQUEST');
} else {
    fail('PAGE_MODULE_LOADER_PARSE_REQUEST', 'missing_hook');
}

if (loaderContent.includes("add_action(\n\t\t'wp',") || loaderContent.includes("add_action( 'wp'")) {
    pass('PAGE_MODULE_LOADER_WP_FALLBACK');
} else {
    fail('PAGE_MODULE_LOADER_WP_FALLBACK', 'missing_hook');
}

if (!loaderContent.includes('require_once $_SERVER') && !loaderContent.includes('require_once $request')) {
    pass('PAGE_MODULE_LOADER_NO_USER_PATH_REQUIRE');
} else {
    fail('PAGE_MODULE_LOADER_NO_USER_PATH_REQUIRE', 'unsafe_require');
}

if (loaderContent.includes('in_array(') && loaderContent.includes('nvx_page_module_all_files')) {
    pass('PAGE_MODULE_LOADER_WHITELIST_ONLY');
} else {
    fail('PAGE_MODULE_LOADER_WHITELIST_ONLY', 'missing_whitelist_check');
}

if (loaderContent.includes("add_action(\n\t\t'rest_api_init',") || loaderContent.includes("add_action( 'rest_api_init'")) {
    pass('PAGE_MODULE_REST_COMPATIBILITY');
} else {
    fail('PAGE_MODULE_REST_COMPATIBILITY', 'missing_rest_hook');
}

if (loaderContent.includes('NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD')) {
    pass('PAGE_MODULE_YOAST_CLI_COMPATIBILITY');
} else {
    fail('PAGE_MODULE_YOAST_CLI_COMPATIBILITY', 'missing_yoast_cli');
}

// 2. Check functions.php globals
const functionsContent = read(FUNCTIONS_FILE);
const modules = [
    { name: 'ENDOLIFT', file: 'nvx-endolift-page.php' },
    { name: 'EXION', file: 'nvx-exion-page.php' },
    { name: 'PROFHILO', file: 'nvx-profhilo-page.php' },
    { name: 'ENDOLASER', file: 'nvx-endolaser-page.php' },
    { name: 'CO2', file: 'nvx-co2-page.php' },
    { name: 'EQUIPO', file: 'nvx-equipo-page.php' },
    { name: 'NOSOTROS', file: 'nvx-nosotros-page.php' },
    { name: 'BRIDAL', file: 'nvx-bridal-page.php' },
    { name: 'SOLUTIONS', file: 'nvx-solutions-page.php' },
    { name: 'QUE_EXIGIR', file: 'nvx-que-exigir-page.php' }
];

for (const mod of modules) {
    if (functionsContent.includes(`require_once get_template_directory() . '/inc/${mod.file}';`)) {
        fail(`FUNCTIONS_NO_GLOBAL_${mod.name}`, 'still_global');
    } else {
        pass(`FUNCTIONS_NO_GLOBAL_${mod.name}`);
    }
}

// 3. Ensure none of these modules use early hooks
const forbiddenHooks = ['init', 'pre_get_posts', 'parse_query', 'parse_request', 'request', 'posts_pre_query'];
for (const mod of modules) {
    const modContent = read(path.join(THEME_DIR, 'inc', mod.file));
    for (const hook of forbiddenHooks) {
        // Regex strictly checking add_action/add_filter for these exact hooks
        const regex = new RegExp(`(?:add_action|add_filter)\\s*\\(\\s*(?:'|")\\s*${hook}\\s*(?:'|")`);
        if (regex.test(modContent)) {
            fail(`MODULE_${mod.name}_SAFE_FOR_LAZY_LOAD`, `uses_forbidden_hook_${hook}`);
        }
    }
}

console.log('ALL CONDITIONAL LOADER TESTS PASSED');
