#!/bin/bash
set -e

SERVER="root@164.92.220.156"
REPO_PATH="/var/www/repo"

# Prompt for commit message
echo ""
read -p "Commit message: " msg
if [ -z "$msg" ]; then
    echo "Commit message required."
    exit 1
fi

# Stage, commit, push
echo ""
echo "→ Committing and pushing to GitHub..."
git add -A
git commit -m "$msg"
git push origin main

# Deploy to server
echo ""
echo "→ Deploying to server..."
ssh "$SERVER" "cd $REPO_PATH && git pull"

echo ""
echo "✓ Done — changes are live."
