#!/usr/bin/env bash
# Hook PreToolUse (Bash) Claude Code: audit otomatis sebelum `git commit` dan `git push`.
# Exit 2 = perintah diblokir, isi stderr dikirim ke AI agar diperbaiki.
# Aturan lengkap: .ai/guidelines/audit-sebelum-commit.md

set -uo pipefail

perintah=$(jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$perintah" ] && exit 0

pola_awal='(^|[;&|({[:space:]])git[[:space:]]+(-C[[:space:]]+[^[:space:]]+[[:space:]]+)?'
akan_commit=0
akan_push=0
echo "$perintah" | grep -Eq "${pola_awal}commit([[:space:]]|$)" && akan_commit=1
echo "$perintah" | grep -Eq "${pola_awal}push([[:space:]]|$)" && akan_push=1
[ "$akan_commit" -eq 0 ] && [ "$akan_push" -eq 0 ] && exit 0

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}" || exit 0

gagal=()
peringatan=()

jalankan() {
    # jalankan <label> <perintah...>: simpan 40 baris terakhir output bila gagal
    local label=$1
    shift
    local keluaran
    if ! keluaran=$("$@" 2>&1); then
        gagal+=("[$label] $*"$'\n'"$(echo "$keluaran" | tail -n 40)")
        return 1
    fi
}

# File yang berubah: staged + unstaged + untracked (perintah `git add ... && git commit` belum men-stage saat hook jalan)
file_berubah=$( { git diff --name-only HEAD 2>/dev/null; git ls-files --others --exclude-standard; } | sort -u)

# Commit yang akan di-push
if git rev-parse --abbrev-ref '@{u}' >/dev/null 2>&1; then
    basis_push='@{u}'
else
    basis_push='origin/main'
fi
file_push=$(git diff --name-only "$basis_push"...HEAD 2>/dev/null)

# 1. File rahasia
semua_file=$(printf '%s\n%s\n' "$file_berubah" "$file_push" | sort -u)
rahasia=$(echo "$semua_file" | grep -E '(^|/)\.env($|\.)|\.(pem|key|p12|pfx|sqlite)$|(^|/)id_(rsa|ed25519)|auth\.json$' | grep -vE '\.env\.example$')
if [ -n "$rahasia" ]; then
    gagal+=("[File rahasia] File ini tidak boleh di-commit/push:"$'\n'"$rahasia")
fi

# 2. Sisa debug di baris yang ditambahkan
debug=$( { git diff HEAD -U0 -- '*.php' '*.js' '*.jsx' '*.ts' '*.tsx' 2>/dev/null
          git ls-files --others --exclude-standard -- '*.php' '*.js' '*.jsx' '*.ts' '*.tsx' | while read -r f; do sed 's/^/+/' "$f"; done
        } | grep -E '^\+' | grep -vE '^\+\+\+' \
          | grep -E '(^|[^A-Za-z0-9_>:$])(dd|dump|var_dump|ray)[[:space:]]*\(|console\.log[[:space:]]*\(|(^|[^A-Za-z0-9_])debugger([^A-Za-z0-9_]|$)' | head -n 10)
if [ -n "$debug" ]; then
    gagal+=("[Sisa debug] Hapus dd()/dump()/var_dump()/ray()/console.log()/debugger:"$'\n'"$debug")
fi

# 3. Format kode PHP
if echo "$file_berubah" | grep -q '\.php$'; then
    jalankan "Pint" vendor/bin/pint --test --dirty || gagal[${#gagal[@]}-1]+=$'\n'"Perbaiki dengan: vendor/bin/pint --dirty --format agent"
fi

# 4. Seluruh tes PHPUnit
jalankan "PHPUnit" php artisan test --compact

# 5. Khusus push: build frontend & audit dependency
if [ "$akan_push" -eq 1 ]; then
    if echo "$file_push$file_berubah" | grep -Eq '^(resources/|package(-lock)?\.json|vite\.config)'; then
        jalankan "Build frontend" npm run build
    fi

    if ! composer audit --locked --abandoned=ignore --no-interaction -q >/dev/null 2>&1; then
        if echo "$file_push" | grep -q '^composer\.lock$'; then
            gagal+=("[composer audit] composer.lock berubah dan ada kerentanan. Jalankan: composer audit --locked")
        else
            peringatan+=("composer audit menemukan kerentanan pada dependency PHP yang sudah ada (bukan dari perubahan ini). Laporkan ke pengguna dan tawarkan update; jangan update tanpa persetujuan.")
        fi
    fi

    if ! npm audit --omit=dev --audit-level=high >/dev/null 2>&1; then
        if echo "$file_push" | grep -q '^package-lock\.json$'; then
            gagal+=("[npm audit] package-lock.json berubah dan ada kerentanan high/critical. Jalankan: npm audit --omit=dev")
        else
            peringatan+=("npm audit menemukan kerentanan high/critical pada dependency JS yang sudah ada. Laporkan ke pengguna.")
        fi
    fi
fi

if [ ${#gagal[@]} -gt 0 ]; then
    {
        echo "AUDIT SEBELUM COMMIT/PUSH GAGAL. Perintah diblokir. Perbaiki semua masalah berikut lalu ulangi:"
        for g in "${gagal[@]}"; do
            echo
            echo "$g"
        done
        echo
        echo "Jangan melewati pemeriksaan ini (--no-verify, menonaktifkan hook, atau menghapus tes). Lihat .ai/guidelines/audit-sebelum-commit.md"
    } >&2
    exit 2
fi

konteks="Audit otomatis lulus (tes, Pint, file rahasia, sisa debug$([ "$akan_push" -eq 1 ] && echo ', build, audit dependency')). Pastikan audit manual keamanan, performa, dan tampilan HP sesuai .ai/guidelines/audit-sebelum-commit.md sudah dilaporkan ke pengguna."
for p in "${peringatan[@]+"${peringatan[@]}"}"; do
    konteks+=" PERINGATAN: $p"
done

jq -n --arg konteks "$konteks" '{hookSpecificOutput: {hookEventName: "PreToolUse", additionalContext: $konteks}}'
exit 0
