# Tariff shortcode contract

`[nvx_tariff]` renders a display price from the canonical tariff catalog:

`wp-content/themes/nuvanx-medical/inc/data/tariff-catalog.json`

This document intentionally does **not** list current prices or catalog groups. Those values change and the JSON catalog is their only source of truth.

## Syntax

```text
[nvx_tariff key="group.subkey"]
```

Example using a catalog key, without duplicating its value:

```html
<p>Precio: [nvx_tariff key="laser_co2.facial"].</p>
```

Do not hardcode a euro amount into WordPress content when the value is tariff-governed.

## Ownership

- shortcode registration: `wp-content/themes/nuvanx-medical/inc/nvx-tariff-shortcode.php`
- catalog loading/formatting: repository catalog helpers
- current keys and values: `inc/data/tariff-catalog.json`
- release validation: tariff/catalog lint and rendered-price contracts under `scripts/lint/`

When adding or renaming a tariff key, update the catalog and all executable consumers/tests in the same reviewed change. Do not update this guide merely to mirror the catalog.

## Failure behavior

The shortcode must fail safely for invalid/missing keys and must not expose internal errors on the public page. Debug information may be emitted only through the approved WordPress debug path when debugging is enabled.

Frontend output/formatting behavior is owned by the implementation and its tests; do not use Markdown examples as acceptance evidence for a current price.
