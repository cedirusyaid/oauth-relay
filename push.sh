#!/bin/bash
# Auto Git Commit & Push Script - Standard Sinjai v2.6
# Format Commit: YYMMDD - [Tipe]: Deskripsi

# 1. Deteksi branch aktif
BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null | tr -d '\r\n')
if [ -z "$BRANCH" ]; then
    BRANCH="main"
fi

# 2. Cek status perubahan
STATUS=$(git status --porcelain)
if [ -z "$STATUS" ]; then
    echo "============================================="
    echo "ℹ️  Tidak ada perubahan yang perlu dicommit."
    echo "============================================="
    exit 0
fi

echo "============================================="
echo "📁 Status Perubahan:"
git status -s
echo "============================================="

# 3. Handle parameter argumen atau prompt interaktif
if [ -n "$1" ]; then
    PESAN_COMMIT="$1"
else
    # Pilih Tipe Commit
    echo "Pilih tipe commit:"
    echo "1) ✨ Added      (Fitur baru)"
    echo "2) 🐛 Fixed      (Perbaikan bug)"
    echo "3) 🔄 Changed    (Refactor, optimasi, dll)"
    echo "4) 🗑️ Deprecated (Fitur lama)"
    echo "5) 🛡️ Security   (Celah keamanan)"
    read -p "Masukkan pilihan (1-5): " PILIHAN

    case $PILIHAN in
        1) TIPE="✨ Added" ;;
        2) TIPE="🐛 Fixed" ;;
        3) TIPE="🔄 Changed" ;;
        4) TIPE="🗑️ Deprecated" ;;
        5) TIPE="🛡️ Security" ;;
        *) echo "❌ Pilihan tidak valid!"; exit 1 ;;
    esac

    # Input Deskripsi
    read -p "Masukkan deskripsi commit: " DESKRIPSI
    if [ -z "$DESKRIPSI" ]; then
        echo "❌ Deskripsi commit tidak boleh kosong!"
        exit 1
    fi

    # Format Tanggal & Pesan Commit (YYMMDD - [Tipe]: Deskripsi)
    TANGGAL=$(date +"%y%m%d")
    PESAN_COMMIT="$TANGGAL - [$TIPE]: $DESKRIPSI"
fi

# 4. Jalankan Pengecekan Sintaks PHP (Linting) sebelum commit
echo "🔍 Menjalankan pemeriksaan sintaks PHP..."
MODIFIED_PHP=$(git status --porcelain | grep -E '\.php$' | awk '{print $2}')
LINT_FAIL=0

for FILE in $MODIFIED_PHP; do
    if [ -f "$FILE" ]; then
        php -l "$FILE" > /dev/null 2>&1
        if [ $? -ne 0 ]; then
            echo "❌ Error sintaks ditemukan pada: $FILE"
            LINT_FAIL=1
        fi
    fi
done

if [ $LINT_FAIL -eq 1 ]; then
    echo "❌ Commit dibatalkan karena kesalahan sintaks PHP!"
    exit 1
fi
echo "✅ Pemeriksaan sintaks selesai (Aman)."

# 5. Eksekusi Git
echo "🚀 Memulai proses commit dan push..."
git add .
git commit -m "$PESAN_COMMIT"
git push origin "$BRANCH"

echo "============================================="
echo "🎉 Berhasil dicommit & push ke branch: $BRANCH"
echo "============================================="
