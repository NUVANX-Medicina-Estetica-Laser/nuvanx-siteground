const fs = require('fs');
const file = 'wp-content/themes/nuvanx-medical/functions.php';
let content = fs.readFileSync(file, 'utf8');

// Add the module loader
content = content.replace(
    "require_once get_template_directory() . '/inc/nvx-page-render-helpers.php';",
    "require_once get_template_directory() . '/inc/nvx-page-render-helpers.php';\nrequire_once get_template_directory() . '/inc/nvx-page-module-loader.php';"
);

// Remove the 10 files
const toRemove = [
    "require_once get_template_directory() . '/inc/nvx-bridal-page.php';",
    "require_once get_template_directory() . '/inc/nvx-solutions-page.php';",
    "require_once get_template_directory() . '/inc/nvx-endolift-page.php';",
    "require_once get_template_directory() . '/inc/nvx-exion-page.php';",
    "require_once get_template_directory() . '/inc/nvx-profhilo-page.php';",
    "require_once get_template_directory() . '/inc/nvx-endolaser-page.php';",
    "require_once get_template_directory() . '/inc/nvx-co2-page.php';",
    "require_once get_template_directory() . '/inc/nvx-equipo-page.php';",
    "require_once get_template_directory() . '/inc/nvx-nosotros-page.php';",
    "require_once get_template_directory() . '/inc/nvx-que-exigir-page.php';"
];

for (const line of toRemove) {
    content = content.replace(line + '\n', '');
    content = content.replace(line, '');
}

fs.writeFileSync(file, content);
