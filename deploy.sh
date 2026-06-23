#!/usr/bin/env bash
#
# EGYGOMBOS DEPLOY (helyi gépről).
# 1) helyi változások commit + push a GitHub-ra (nem kell kézzel pusholni)
# 2) a szerveren lefuttatja a deploy-remote.sh-t SSH-n (git pull + composer + migrációk)
#
# Beállítás: másold a deploy/deploy.config.example fájlt deploy/deploy.config néven,
# és töltsd ki az SSH adatokkal. Futtatás:  ./deploy.sh
#
set -euo pipefail
cd "$(dirname "$0")"

CONF="deploy/deploy.config"
if [ ! -f "$CONF" ]; then
  echo "Hiányzik a $CONF. Másold le: cp deploy/deploy.config.example deploy/deploy.config" >&2
  exit 1
fi
# shellcheck disable=SC1090
source "$CONF"

SSH_PORT="${SSH_PORT:-22}"
APP_DIR="${APP_DIR:-~/zsolti-crm}"
MSG="${1:-deploy: $(date '+%Y-%m-%d %H:%M')}"

echo "==> [1/3] Helyi commit (ha van változás)…"
if [ -n "$(git status --porcelain)" ]; then
  git add -A
  git commit -m "$MSG"
else
  echo "    Nincs helyi változás."
fi

echo "==> [2/3] Push a GitHub-ra…"
git push origin main

echo "==> [3/3] Szerver-deploy SSH-n ($SSH_USER@$SSH_HOST)…"
ssh -p "$SSH_PORT" "${SSH_USER}@${SSH_HOST}" "cd ${APP_DIR} && bash deploy/deploy-remote.sh"

echo "==> Kész. Minden szinkronban."
