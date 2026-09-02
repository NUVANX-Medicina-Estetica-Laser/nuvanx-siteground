import fs from 'fs';
import path from 'path';

const content = fs.readFileSync('wp-content/themes/nuvanx-medical/functions.php', 'utf-8');
const filesToTest = [
    'inc/nvx-environment-flags.php',
    'inc/nvx-marketing-consent.php',
    'inc/nvx-integrations.php',
    'inc/nvx-hubspot-secure-attribution.php',
    'inc/nvx-valoracion-direct-form.php',
    'inc/nvx-blog-system.php',
    'header.php',
    'single-post.php',
    'inc/nvx-gtm-integration.php'
];

let failed = false;
for (const file of filesToTest) {
    const fPath = path.join('wp-content/themes/nuvanx-medical', file);
    if (!fs.existsSync(fPath)) continue;
    const fContent = fs.readFileSync(fPath, 'utf-8');
    // Ensure no lateral require_once
    if (fContent.match(/^require_once .*;/m) && !file.includes('single-post.php')) {
        console.error(`Lateral require_once found in ${file}`);
        failed = true;
    }
}

if (!content.includes('function nvx_theme_bootstrap')) {
    console.error('nvx_theme_bootstrap function missing');
    failed = true;
}
if (!content.includes("add_action( 'after_setup_theme', 'nvx_theme_bootstrap', -1000 );")) {
    console.error('after_setup_theme action missing or incorrect priority');
    failed = true;
}

if (failed) process.exit(1);
console.log('THEME_BOOTSTRAP_SINGLE_OWNER=PASS');
