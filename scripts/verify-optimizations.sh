#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# nuvanx — Script de verificación post-implementación
# Uso: bash scripts/verify-optimizations.sh [https://nuvanx.com]
# ─────────────────────────────────────────────────────────────────────────────

BASE="${1:-https://nuvanx.com}"
PASS=0
FAIL=0
WARN=0

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "${GREEN}✓${NC} $1"; ((PASS++)); }
fail() { echo -e "${RED}✗${NC} $1"; ((FAIL++)); }
warn() { echo -e "${YELLOW}⚠${NC} $1"; ((WARN++)); }

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Verificación de optimizaciones — $BASE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── 1. LCP Preload ────────────────────────────────────────
echo -e "\n[1] LCP Preload"
HTML=$(curl -sL "$BASE/")
if echo "$HTML" | grep -q 'rel="preload".*as="image"'; then
    ok "Preload de imagen encontrado en <head>"
else
    fail "No se encontró <link rel=\"preload\" as=\"image\"> — revisar nuvanx_preload_lcp_hero()"
fi

if echo "$HTML" | grep -q 'fetchpriority="high"'; then
    ok "fetchpriority=\"high\" presente"
else
    fail "fetchpriority=\"high\" no encontrado"
fi

if echo "$HTML" | grep -q 'type="image/webp"'; then
    ok "Preload WebP presente"
else
    warn "Preload WebP no encontrado (OK si no se generan WebP todavía)"
fi

# ── 2. Hero <img> sin lazy ────────────────────────────────
echo -e "\n[2] Hero img attributes"
if echo "$HTML" | grep -qE 'class="[^"]*hero[^"]*"[^>]*loading="lazy"'; then
    fail "Hero img tiene loading=\"lazy\" — debería ser eager"
else
    ok "Hero img no tiene loading lazy (probablemente eager)"
fi

# ── 3. Headers HTTP ───────────────────────────────────────
echo -e "\n[3] Headers HTTP"
HEADERS=$(curl -sI "$BASE/")

if echo "$HEADERS" | grep -qi "x-cache: HIT\|cf-cache-status: HIT\|x-sg-cache: HIT"; then
    ok "Página servida desde caché"
elif echo "$HEADERS" | grep -qi "x-cache\|cf-cache\|x-sg-cache"; then
    warn "Header de caché encontrado pero puede ser MISS (primera visita)"
else
    warn "No se detectó header de caché — verificar SiteGround SuperCacher"
fi

if echo "$HEADERS" | grep -qi "cache-control"; then
    ok "Cache-Control presente"
else
    fail "Cache-Control no encontrado"
fi

# ── 4. Compresión GZIP/Brotli ─────────────────────────────
echo -e "\n[4] Compresión"
ENC=$(curl -sI -H "Accept-Encoding: br, gzip" "$BASE/" | grep -i "content-encoding")
if echo "$ENC" | grep -qi "br"; then
    ok "Brotli activo (mejor que gzip)"
elif echo "$ENC" | grep -qi "gzip"; then
    ok "GZIP activo"
else
    fail "Sin compresión detectada"
fi

# ── 5. WebP en imágenes ───────────────────────────────────
echo -e "\n[5] Soporte WebP"
FIRST_IMG=$(echo "$HTML" | grep -oE 'src="[^"]*\.(jpg|jpeg|png)"' | head -1 | grep -oE '"[^"]+"' | tr -d '"')
if [ -n "$FIRST_IMG" ]; then
    IMG_URL="${BASE}${FIRST_IMG}"
    WEBP_URL="${IMG_URL%.*}.webp"
    WEBP_STATUS=$(curl -so /dev/null -w "%{http_code}" "$WEBP_URL")
    if [ "$WEBP_STATUS" = "200" ]; then
        ok "WebP disponible: $WEBP_URL"
    else
        warn "WebP no encontrado para: $FIRST_IMG (código: $WEBP_STATUS)"
    fi
else
    warn "No se encontró imagen JPG/PNG en la home para verificar WebP"
fi

# ── 6. Fonts locales ─────────────────────────────────────
echo -e "\n[6] Fuentes"
if echo "$HTML" | grep -q "fonts.googleapis.com/css"; then
    warn "Aún se carga Google Fonts externo — considerar alojar localmente"
else
    ok "Google Fonts no cargado como recurso bloqueante"
fi

# ── 7. Preconnect ────────────────────────────────────────
echo -e "\n[7] Resource hints"
if echo "$HTML" | grep -q 'rel="preconnect"'; then
    ok "Preconnect encontrado"
else
    warn "No se encontraron preconnect hints"
fi

if echo "$HTML" | grep -q 'rel="dns-prefetch"'; then
    ok "DNS prefetch encontrado"
else
    warn "DNS prefetch no encontrado"
fi

# ── 8. GTM ───────────────────────────────────────────────
echo -e "\n[8] Google Tag Manager (vía Site Kit)"
if echo "$HTML" | grep -q "googletagmanager.com/gtm.js"; then
    ok "GTM detectado en el HTML (inyectado por Site Kit)"
else
    warn "GTM no detectado en HTML estático — puede estar inyectado por JS o Site Kit en runtime"
fi

# ── 9. Limpieza WordPress ─────────────────────────────────
echo -e "\n[9] Limpieza WordPress"
if echo "$HTML" | grep -q 'name="generator".*WordPress'; then
    fail "Meta generator de WordPress visible — nuvanx_strip_wp_head_noise() no está activo"
else
    ok "Meta generator de WordPress eliminado"
fi

if echo "$HTML" | grep -q '<link[^>]*rsd\.xml'; then
    warn "RSD link aún presente"
else
    ok "RSD link eliminado"
fi

if echo "$HTML" | grep -q '?ver='; then
    warn "Query string ?ver= detectada en algunos assets — puede afectar cache hit rate"
else
    ok "Query strings ?ver= eliminadas de los assets enqueued"
fi

# ── 10. HTTPS ────────────────────────────────────────────
echo -e "\n[10] HTTPS"
HTTP_CODE=$(curl -so /dev/null -w "%{http_code}" "http://nuvanx.com/")
if [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "308" ]; then
    ok "Redirección HTTP → HTTPS correcta ($HTTP_CODE)"
else
    warn "HTTP devuelve código $HTTP_CODE — verificar redirección"
fi

# ── Resumen ───────────────────────────────────────────────
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "  ${GREEN}✓ Pasadas: $PASS${NC} | ${RED}✗ Falladas: $FAIL${NC} | ${YELLOW}⚠ Avisos: $WARN${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}  ✅ Implementación correcta. Lanzar re-auditoría PageSpeed.${NC}"
else
    echo -e "${RED}  ❌ $FAIL optimizaciones pendientes. Revisar los fallos antes de re-auditar.${NC}"
fi
echo ""
