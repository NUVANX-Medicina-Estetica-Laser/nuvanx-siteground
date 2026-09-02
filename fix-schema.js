const fs = require('fs');
let content = fs.readFileSync('wp-content/themes/nuvanx-medical/inc/nvx-aesthetic-treatment-schema.php', 'utf8');
content = content.replace(/if \( true \) \{\s*\}/, '$numeric_price = min( $prices );\n\t\t\t\t$high_price = max( $prices );');
fs.writeFileSync('wp-content/themes/nuvanx-medical/inc/nvx-aesthetic-treatment-schema.php', content);
