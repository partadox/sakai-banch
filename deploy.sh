#!/bin/bash
# Skrip untuk otomatisasi proses deployment

# Hentikan eksekusi jika ada perintah yang gagal
set -e

# 1. Tarik kode terbaru dari Git
echo " pulling latest code..."
git pull

# 2. Bangun ulang image Podman
echo " Building new container image..."
podman build -t sakai-banch .

# 3. Hentikan dan hapus container lama (jika ada)
# '|| true' agar skrip tidak error jika container tidak ditemukan
echo " Stopping and removing old container..."
podman stop sakai-banch || true
podman rm sakai-banch || true

# 4. Jalankan container baru
echo " Starting new container..."

echo " Deployment finished successfully!"



