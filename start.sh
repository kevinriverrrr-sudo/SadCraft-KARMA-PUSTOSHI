#!/bin/bash
# SadCraft Server Start Script
# Жанр: КАРМА ПУСТОШИ — Rogue-Anarchy

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

export LD_LIBRARY_PATH="$DIR/bin/php7/lib:$LD_LIBRARY_PATH"

echo "=========================================="
echo "  SADCRAFT — КАРМА ПУСТОШИ"
echo "  Rogue-Anarchy Bedrock Server"
echo "=========================================="

exec "$DIR/bin/php7/bin/php" PocketMine-MP.phar "$@"
