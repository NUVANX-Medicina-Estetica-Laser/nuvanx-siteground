import os

files_to_strip = [
    'wp-content/themes/nuvanx-medical/inc/nvx-seo-legacy-retirement.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-exion-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-aesthetic-medicine-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-environment-flags.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-endolift-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-blog-system.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-structured-data.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-btl-detail-pages.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-co2-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-signature-phase-pages.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-clinical-governance.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-endolaser-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-bridal-page.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-integrations.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-content-presentation.php',
    'wp-content/themes/nuvanx-medical/inc/nvx-profhilo-page.php',
    'wp-content/themes/nuvanx-medical/header.php',
    'wp-content/themes/nuvanx-medical/single-post.php'
]

for filepath in files_to_strip:
    if not os.path.exists(filepath):
        continue
    with open(filepath, 'r') as f:
        lines = f.readlines()
    
    new_lines = []
    for line in lines:
        if line.startswith('require_once') and ('__DIR__' in line or 'inc/' in line):
            # Special case for single-post.php which requires single.php
            if 'single.php' in line:
                new_lines.append(line)
        else:
            new_lines.append(line)
            
    with open(filepath, 'w') as f:
        f.writelines(new_lines)
    print(f"Stripped {filepath}")
