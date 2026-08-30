# Plan de Optimización de Rendimiento — nuvanx.com

> **Objetivo:** Mobile 68 → 90+ | Desktop 87.7 → 95+  
> **Basado en:** Auditoría Lighthouse de 162 análisis, 81 URLs  
> **Estado:** Fase 1 implementada ✅

---

## Diagnóstico de partida

| Métrica | Mobile actual | Desktop actual | Objetivo |
|---|---|---|---|
| Performance | 68 | 87.7 | 90+ / 95+ |
| LCP | 8–10 s | 0.8–1.9 s | < 3 s / < 1.5 s |
| TBT | 50–760 ms | n/a crít. | < 150 ms |
| CLS | 0.000 ✅ | 0.000 ✅ | Mantener |
| Accesibilidad | 99.5 ✅ | 99.6 ✅ | Mantener |
| SEO | 100 ✅ | 100 ✅ | Mantener |
| Best Practices | 94.7 | 94.3 | 100 |

**Causa raíz principal LCP mobile:** 3 cuellos de botella:
1. Hero images no preloadeadas (descubrimiento tardío por el parser) → ✅ **RESUELTO** en `nuvanx-performance.php`
2. Imágenes hero en JPG/PNG sin conversión a WebP/AVIF
3. Scripts de terceros (GTM vía Site Kit, Cookiebot) generando TBT > 300 ms en páginas específicas

---

## FASE 1 — Preload LCP + fetchpriority ✅ IMPLEMENTADA
**Impacto estimado: +12–18 puntos mobile**

Implementado en `inc/performance/nuvanx-performance.php`:

- ✅ `nuvanx_preload_lcp_hero()` — emite `<link rel="preload" as="image" fetchpriority="high">` en `wp_head` priority 1 (antes de cualquier recurso render-blocking). Resuelve el hero dinámicamente desde featured image o ACF option.
- ✅ `nuvanx_hero_image_priority_attrs()` — fuerza `loading="eager"` + `fetchpriority="high"` + `decoding="sync"` en el attachment de la imagen hero.
- ✅ `nuvanx_emit_preconnect_hints()` — preconnect a `googletagmanager.com`, `google-analytics.com`, `stats.g.doubleclick.net` (fonts.googleapis.com ya está en header.php).
- ✅ `nuvanx_register_hero_image_sizes()` — tamaños `hero-mobile` (768px), `hero-tablet` (1024px), `hero-desktop` (1440px).
- ✅ `nuvanx_strip_wp_head_noise()` — elimina `wp_generator`, `rsd_link`, `wlwmanifest_link`, shortlink, oEmbed discovery.
- ✅ `nuvanx_strip_asset_version_query()` — quita `?ver=` de URLs de assets para mejorar cache hit rate en SiteGround/CDN.
- ✅ `nuvanx_external_links_rel()` — inyecta `rel="noopener noreferrer"` en links externos del contenido.

**Acción post-deploy:** Regenerar thumbnails para crear derivados hero-mobile/tablet/desktop:
```bash
# Vía WP-CLI en SiteGround SSH:
wp media regenerate --only-registered --yes
```

---

## FASE 2 — Imágenes WebP/AVIF + compresión
**Impacto estimado: +6–10 puntos mobile, LCP –30%**

Las imágenes hero pesan típicamente 300–1500 KB. WebP reduce esto un 25–35%.

### Plugin recomendado: Imagify
```
Imagify → Settings:
  ✓ Auto-optimize on upload: ON
  ✓ Image format: WebP
  ✓ Optimization level: Aggressive
  ✓ Resize larger images: 1920px max width
  ✓ Convert to WebP: ON
  ✓ Display WebP in HTML: ON
```

El `nuvanx_preload_lcp_hero()` ya emite el hint WebP automáticamente cuando
encuentra un `.webp` sibling del URL canónico.

---

## FASE 3 — Caching SiteGround + TTFB
**Impacto estimado: TTFB –40%, +4–6 puntos overall**

```
Site Tools → Speed → Caching:
  ✓ Nginx Direct Delivery: ACTIVADO
  ✓ Dynamic Cache: ACTIVADO
  ✓ Memcached: ACTIVADO (si disponible en el plan)

SG Optimizer Plugin → Frontend:
  ✓ Minify HTML: ON
  ✓ Minify CSS: ON
  ✓ Minify JavaScript: ON
  ✓ Combine CSS: OFF (riesgo con Critical CSS)
  ✓ Combine JavaScript: OFF (riesgo con scripts async/defer)
  ✓ Remove Query Strings: ON  ← ya lo hace también nuvanx-performance.php
  ✓ Disable Emojis: ON
```

Cache-Control para assets estáticos en `.htaccess`:
```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png  "access plus 1 year"
    ExpiresByType text/css   "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
```

---

## FASE 4 — Reducir TBT: JS diferido y terceros
**Impacto estimado: +8–15 puntos mobile**

Páginas críticas con TBT > 400 ms:
- `/madrid/valoracion/` — 710 ms
- `/protocolo-novias-madrid/` — 760 ms
- `/endolift-ciencia-laser/` — 610 ms
- `/papada-sin-cirugia/` — 650 ms

El tema ya tiene `nvx_theme_defer_local_script_tags` (nvx-native-style-governance.php)
y `nvx_theme_defer_auxiliary_script_tags` (nvx-integrations.php). Los TBT altos
probablemente vienen de Site Kit / Cookiebot ejecutando en el hilo principal.

**Acción:** Configurar Site Kit para cargar GTM en modo "Consentimiento" (cargar
solo tras interacción del usuario si Complianz no ha otorgado consentimiento).

---

## FASE 5 — Fuentes web y preconnect
**Impacto estimado: +3–5 puntos, LCP –200 ms**

Preconnects ya implementados:
- `fonts.googleapis.com` + `fonts.gstatic.com` → `header.php` (hardcoded, antes del parser)
- `googletagmanager.com` + `google-analytics.com` + `stats.g.doubleclick.net` → `nuvanx-performance.php` ✅

Para subir a 100 Best Practices, valorar alojar Google Fonts localmente:
```bash
# Descargar fuentes con google-webfonts-helper:
# https://gwfh.mranftl.com/fonts/manrope
# https://gwfh.mranftl.com/fonts/playfair-display
# Copiar .woff2 a assets/fonts/ y actualizar nvx-fonts.css
```

---

## FASE 6 — Best Practices: de 94 a 100
**Impacto: reputación + señales Core Web Vitals**

Pérdidas de puntos actuales:
- Cookies de terceros sin consentimiento previo a carga
- Posibles errores de consola JS en `/madrid/valoracion/` (77 BP)

Orden correcto de carga en `wp_head`:
1. Preload + preconnect (priority 1) ✅
2. CMP Complianz (priority 2) ✅
3. GTM vía Site Kit: solo tras consentimiento

---

## FASE 7 — Cloudflare APO (opcional, ~$5/mes)
**Impacto: LCP mobile –2–4 s, TTFB –60%**

Cloudflare APO cachea el HTML generado por WordPress en edge, eliminando el
TTFB de PHP. Es la mejora de mayor impacto tras las imágenes.

URLs a excluir del caché APO:
- `/madrid/valoracion/` (formulario dinámico)
- `/contacto/`
- `/gracias/`
- `/wp-admin/*`
- `/wp-login.php`

---

## Plan de implementación por semanas

| Semana | Fases | Ganancia esperada (mobile) |
|---|---|---|
| **Semana 1** (hecho ✅) | Fase 1: preload LCP + head hygiene | +12–18 puntos |
| Semana 1–2 | Fase 2: imágenes WebP + Fase 3: SiteGround cache | +10–16 puntos |
| Semana 2 | Fase 4: TBT / JS diferido | +5–10 puntos |
| Semana 2–3 | Fase 5: fuentes locales + Fase 6: Best Practices 100 | +3–8 puntos |
| Semana 3 (opcional) | Fase 7: Cloudflare APO | +5–8 puntos |
| **Total acumulado** | | **+35–60 puntos mobile** |

**Resultado proyectado: Performance mobile 68 → 90–95 | Desktop 87.7 → 97–99**

---

## Páginas prioritarias para re-auditar post-deploy

| Página | Mobile actual | Objetivo |
|---|---|---|
| `/` (portada) | 64 | 90+ |
| `/madrid/valoracion/` | 57 | 85+ |
| `/endolift-facial-papada-mandibula/` | 75 | 90+ |
| `/medicina-estetica-laser/` | 58 | 85+ |
| `/protocolo-novias-madrid/` | 58 | 85+ |
| `/papada-sin-cirugia-madrid-opciones-endolift/` | 55 | 85+ |

---

## Verificación post-implementación

```bash
# Script completo de verificación:
bash scripts/verify-optimizations.sh https://nuvanx.com

# Verificar preload en head:
curl -sL https://nuvanx.com/ | grep -o 'rel="preload"[^>]*>'

# Verificar que el meta generator ha desaparecido:
curl -sL https://nuvanx.com/ | grep -i 'generator'

# Verificar headers WebP:
curl -I -H "Accept: image/webp" https://nuvanx.com/wp-content/uploads/hero.jpg

# Verificar Cache-Control:
curl -I https://nuvanx.com/ | grep -i 'cache-control\|age\|x-cache'
```
