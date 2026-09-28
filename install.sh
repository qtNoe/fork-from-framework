#!/usr/bin/env bash


# ----------------
# Check Environment
# ----------------

if ! command -v docker >/dev/null; then
    echo "Error: Docker is not installed."
    exit 1
fi


# ----------------
# Project Directory
# ----------------

read -r -p "Project directory (leave empty for current directory): " directory
directory="${directory:-.}"

mkdir -p "$directory"
directory="$(cd "$directory" && pwd)"
echo "Installing ZubZet into $directory"

# ----------------
# Require Framework, Run Install Command
# ----------------

docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$directory":/app --entrypoint sh ghcr.io/zubzet/php:8.5-apache -c '
    mkdir /tmp/installer && cd /tmp/installer &&
    composer require -q --prefer-source qtnoe/zubzet-framework:dev-feat/install-command &&
    cd vendor/qtnoe/zubzet-framework/tests/e2e && COMPOSER_VENDOR_DIR=/tmp/installer/vendor php index.php install /app
'