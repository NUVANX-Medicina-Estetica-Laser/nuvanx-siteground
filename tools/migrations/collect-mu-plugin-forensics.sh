#!/usr/bin/env bash
# Read-only production MU-plugin forensic extraction.
# Raw sources stay in RAW_DIR (mode 700) and are never written to stdout.
set -Eeuo pipefail

: "${SSH_ALIAS:?Missing SSH_ALIAS}"
: "${PROD_ROOT:?Missing PROD_ROOT}"
: "${RAW_DIR:?Missing RAW_DIR}"
: "${REPORT_DIR:?Missing REPORT_DIR}"
: "${SCANNER:?Missing SCANNER}"
: "${PUBLIC_BASE_URL:?Missing PUBLIC_BASE_URL}"

umask 077
mkdir -p "$RAW_DIR/mu-plugins" "$RAW_DIR/backups" "$RAW_DIR/snippets" "$REPORT_DIR"
REPORT_DIR="$(cd "$REPORT_DIR" && pwd)"
chmod 700 "$RAW_DIR" "$RAW_DIR/mu-plugins" "$RAW_DIR/backups" "$RAW_DIR/snippets"

mu_files=(
  nuvanx-home-unified-faq-schema.php
  nuvanx-meta-dedupe-event-id.php
  nuvanx-meta-match-quality.php
  nuvanx-redirects.php
  nuvanx-seo-geo-config.php
  nuvanx-seo-geo.php
  nvx-asset-versioning.php
  nvx-theme-preview.php
)

remote_meta="$RAW_DIR/remote-mu-meta.tsv"
: > "$remote_meta"
echo 'FORENSIC_REMOTE_LIST=START' >&2
ssh -n "$SSH_ALIAS" "ls -1 '$PROD_ROOT/wp-content/mu-plugins'" >&2 || true
echo 'FORENSIC_REMOTE_LIST=END' >&2
found_mu_files=()
for file in "${mu_files[@]}"; do
  remote_path="$PROD_ROOT/wp-content/mu-plugins/$file"
  if ! ssh -n "$SSH_ALIAS" "test -f '$remote_path'"; then
    echo "FORENSIC_MU_PLUGIN=MISSING file=$file" >&2
    continue
  fi
  ssh "$SSH_ALIAS" "REMOTE_PATH='$remote_path' bash -s" <<'REMOTE' >> "$remote_meta"
set -Eeuo pipefail
p="$REMOTE_PATH"
test -f "$p"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
  "$p" "$(stat -c '%s' "$p")" "$(stat -c '%a' "$p")" \
  "$(stat -c '%U' "$p")" "$(stat -c '%G' "$p")" "$(stat -c '%y' "$p")" "$(sha256sum "$p" | awk '{print $1}')"
if php -l "$p" >/dev/null 2>&1; then printf 'PHP_LINT\t%s\tPASS\n' "$p"; else printf 'PHP_LINT\t%s\tFAIL\n' "$p"; fi
REMOTE
  scp -q "$SSH_ALIAS:$remote_path" "$RAW_DIR/mu-plugins/$file"
  found_mu_files+=("$file")
done
if (( ${#found_mu_files[@]} == 0 )); then
  echo 'FORENSIC_MU_PLUGIN=FAIL no expected production MU plugins found' >&2
  exit 1
fi

# The historical GTM file is copied only for the requested logic review; wp-config is never copied.
gtm_backup="$PROD_ROOT/wp-content/mu-plugins/nuvanx-google-tag-manager.php.bak"
ssh "$SSH_ALIAS" "ACTIVE_CONFIG='$PROD_ROOT/wp-config.php' CONFIG_BACKUP='$PROD_ROOT/wp-config.php.bak' GTM_BACKUP='$gtm_backup' bash -s" <<'REMOTE' > "$RAW_DIR/remote-backup-meta.tsv"
set -Eeuo pipefail
active="$ACTIVE_CONFIG"
backup="$CONFIG_BACKUP"
gtm="$GTM_BACKUP"
for label in ACTIVE_CONFIG CONFIG_BACKUP GTM_BACKUP; do
  case "$label" in
    ACTIVE_CONFIG) p="$active" ;;
    CONFIG_BACKUP) p="$backup" ;;
    GTM_BACKUP) p="$gtm" ;;
  esac
  if test -f "$p"; then
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$label" "$p" "$(stat -c '%s' "$p")" "$(stat -c '%a' "$p")" "$(stat -c '%U' "$p")" "$(stat -c '%G' "$p")" "$(stat -c '%y' "$p")"
    printf 'SHA256\t%s\t%s\n' "$p" "$(sha256sum "$p" | awk '{print $1}')"
  else
    printf '%s\t%s\tMISSING\tMISSING\tMISSING\tMISSING\tMISSING\n' "$label" "$p"
  fi
done
if test -f "$active" && test -f "$backup"; then
  if cmp -s "$active" "$backup"; then echo 'CONFIG_IDENTICAL	true'; else echo 'CONFIG_IDENTICAL	false'; fi
fi
if test -f "$gtm"; then
  if php -l "$gtm" >/dev/null 2>&1; then echo "PHP_LINT	$gtm	PASS"; else echo "PHP_LINT	$gtm	FAIL"; fi
fi
REMOTE

if ssh -n "$SSH_ALIAS" "test -f '$gtm_backup'"; then
  scp -q "$SSH_ALIAS:$gtm_backup" "$RAW_DIR/backups/nuvanx-google-tag-manager.php.bak"
fi

# Extract the three requested Code Snippets through wp-cli without invoking plugin code.
for id in 7 8 11; do
  snippet_json="$RAW_DIR/snippet-$id.json"
  ssh "$SSH_ALIAS" "PROD_ROOT='$PROD_ROOT' SNIPPET_ID='$id' bash -s" <<'REMOTE' > "$snippet_json"
set -Eeuo pipefail
cd "$PROD_ROOT"
wp eval '
global $wpdb;
$id = (int) getenv("SNIPPET_ID");
$table = $wpdb->prefix . "snippets";
$row = $wpdb->get_row($wpdb->prepare("SELECT id, name, code, active, scope, priority, tags FROM {$table} WHERE id = %d LIMIT 1", $id), ARRAY_A);
if (!is_array($row)) { fwrite(STDERR, "Snippet not found\n"); exit(2); }
echo wp_json_encode(array(
  "id" => (int) $row["id"], "name" => (string) $row["name"], "active" => (int) $row["active"],
  "scope" => (string) $row["scope"], "priority" => (int) $row["priority"], "tags" => (string) $row["tags"],
  "code_b64" => base64_encode((string) $row["code"])
), JSON_UNESCAPED_SLASHES);
' --skip-plugins --skip-themes --allow-root
REMOTE
  jq -e '.id == '"$id"' and (.code_b64 | type == "string")' "$snippet_json" >/dev/null
  jq -r '.code_b64' "$snippet_json" | base64 -d > "$RAW_DIR/snippets/snippet-$id.php"
  jq 'del(.code_b64)' "$snippet_json" > "$REPORT_DIR/snippet-$id-metadata.json"
done

# Scan raw sources locally before packaging; source values are never included in the report.
python3 "$SCANNER" "$RAW_DIR" "$REPORT_DIR/mu-plugins-secret-scan-redacted.json"
secret_count="$(jq '[.findings[] | select(.category == "SECRET")] | length' "$REPORT_DIR/mu-plugins-secret-scan-redacted.json")"

python3 - "$remote_meta" "$RAW_DIR/mu-plugins" "$REPORT_DIR/mu-plugins-production-manifest.json" <<'PY'
import hashlib, json, os, sys
meta_path, source_dir, out_path = sys.argv[1:]
entries, lint = {}, {}
for raw in open(meta_path, encoding='utf-8'):
    parts = raw.rstrip('\n').split('\t')
    if not parts: continue
    if parts[0] == 'PHP_LINT':
        lint[os.path.basename(parts[1])] = parts[2]
    elif len(parts) == 7:
        path, size, perms, owner, group, mtime, digest = parts
        name = os.path.basename(path)
        local = os.path.join(source_dir, name)
        with open(local, 'rb') as fh: local_digest = hashlib.sha256(fh.read()).hexdigest()
        entries[name] = {
            'filename': name, 'absolute_path': path, 'bytes': int(size), 'permissions': perms,
            'owner': owner, 'group': group, 'mtime': mtime, 'sha256': digest,
            'local_sha256': local_digest, 'hash_match': digest == local_digest,
            'php_syntax': lint.get(name, 'UNKNOWN')
        }
report = {
    'schema': 'nuvanx-mu-plugin-production-manifest/v1',
    'mode': 'read_only',
    'source_content': 'private_runner_workspace_only',
    'entries': [entries[k] for k in sorted(entries)],
}
with open(out_path, 'w', encoding='utf-8') as fh: json.dump(report, fh, indent=2); fh.write('\n')
PY

python3 - "$RAW_DIR/remote-backup-meta.tsv" "$PUBLIC_BASE_URL" "$REPORT_DIR/backup-drift-metadata.json" <<'PY'
import json, subprocess, sys
meta, base, out = sys.argv[1:]
records, hashes, identical, lint = {}, {}, None, {}
for raw in open(meta, encoding='utf-8'):
    parts = raw.rstrip('\n').split('\t')
    if not parts: continue
    if parts[0] == 'SHA256': hashes[parts[1]] = parts[2]
    elif parts[0] == 'CONFIG_IDENTICAL': identical = parts[1] == 'true'
    elif parts[0] == 'PHP_LINT': lint[parts[1]] = parts[2]
    elif len(parts) == 7:
        label, path, size, perms, owner, group, mtime = parts
        records[label] = {'absolute_path': path, 'bytes': None if size == 'MISSING' else int(size), 'permissions': perms, 'owner': owner, 'group': group, 'mtime': mtime, 'sha256': hashes.get(path), 'php_syntax': lint.get(path)}
# hashes arrive after records; attach in a second pass
for r in records.values(): r['sha256'] = hashes.get(r['absolute_path'])
try:
    response = subprocess.run(['curl', '-sS', '-I', '--max-time', '20', base.rstrip('/') + '/wp-config.php.bak'], capture_output=True, text=True, check=False)
    headers = response.stdout.splitlines()
    report_headers = [h for h in headers if h.lower().startswith(('http/', 'content-type:', 'content-length:', 'location:', 'cache-control:'))]
except Exception as exc:
    report_headers = ['CURL_ERROR:' + type(exc).__name__]
report = {'schema': 'nuvanx-backup-drift-metadata/v1', 'mode': 'read_only', 'records': records, 'config_identical': identical, 'wp_config_bak_http_headers': report_headers}
with open(out, 'w', encoding='utf-8') as fh: json.dump(report, fh, indent=2); fh.write('\n')
PY

jq -s '{schema:"nuvanx-snippets-forensic-metadata/v1", mode:"read_only", snippets:.}' "$REPORT_DIR"/snippet-*-metadata.json > "$REPORT_DIR/snippets-7-8-11-metadata.json"
rm -f "$REPORT_DIR"/snippet-*-metadata.json

if [[ "$secret_count" -eq 0 ]]; then
  stamp="${FORENSIC_STAMP:-$(date -u +%Y%m%d)}"
  ( cd "$RAW_DIR/mu-plugins" && zip -q -X "$REPORT_DIR/mu-plugins-production-${stamp}.zip" "${found_mu_files[@]}" )
  ln -sfn "mu-plugins-production-${stamp}.zip" "$REPORT_DIR/mu-plugins-production.zip"
  ( cd "$RAW_DIR" && zip -q -X "$REPORT_DIR/forensic-source-review-private.zip" backups/nuvanx-google-tag-manager.php.bak snippets/snippet-7.php snippets/snippet-8.php snippets/snippet-11.php )
  echo "FORENSIC_SOURCE_PACKAGE=PASS secret_findings=0 stamp=$stamp"
else
  echo "FORENSIC_SOURCE_PACKAGE=BLOCKED secret_findings=$secret_count" >&2
  exit 42
fi
