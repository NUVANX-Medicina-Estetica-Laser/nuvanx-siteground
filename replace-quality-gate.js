const fs = require('fs');

function updateWorkflow(filename) {
    let content = fs.readFileSync(filename, 'utf8');
    
    // The chunk to replace
    const regex = /      - name: Run deep PHP quality gates[\s\S]*?(?=\n      - name: Scan committed history for secrets)/m;
    
    const replacement = `      - name: Set up PHP 8.0 and lint
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2
        with:
          php-version: '8.0'
          tools: composer
      - name: Run deep PHP quality gates (8.0)
        shell: bash
        run: |
          set -euo pipefail
          cd wp-content/themes/nuvanx-medical
          composer install --no-interaction --no-progress --prefer-dist
          ./vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary -s
          ./vendor/bin/phpstan analyse --configuration=phpstan.neon --error-format=table --no-progress --memory-limit=2G

      - name: Set up PHP 8.1 and lint
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2
        with:
          php-version: '8.1'
          tools: composer
      - name: Run deep PHP quality gates (8.1)
        shell: bash
        run: |
          set -euo pipefail
          cd wp-content/themes/nuvanx-medical
          ./vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary -s
          ./vendor/bin/phpstan analyse --configuration=phpstan.neon --error-format=table --no-progress --memory-limit=2G

      - name: Set up PHP 8.2 and lint
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2
        with:
          php-version: '8.2'
          tools: composer
      - name: Run deep PHP quality gates (8.2)
        shell: bash
        run: |
          set -euo pipefail
          cd wp-content/themes/nuvanx-medical
          ./vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary -s
          ./vendor/bin/phpstan analyse --configuration=phpstan.neon --error-format=table --no-progress --memory-limit=2G`;

    if (regex.test(content)) {
        content = content.replace(regex, replacement);
        fs.writeFileSync(filename, content);
        console.log("Updated", filename);
    } else {
        console.log("Could not find chunk in", filename);
    }
}

updateWorkflow('.github/workflows/staging.yml');
updateWorkflow('.github/workflows/production.yml');
