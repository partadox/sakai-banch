#!/bin/bash
# Deployment script — Sakai Benchmark Dashboard
set -e

echo "📥 Pulling latest code..."
git pull

echo "🔨 Building & starting containers..."
podman compose down --remove-orphans
podman compose up -d --build

echo "✅ Deployment finished! Dashboard: http://$(hostname -I | awk '{print $1}'):8083"
