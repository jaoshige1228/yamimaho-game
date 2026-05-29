#!/usr/bin/env bash
set -euo pipefail

STACK="${1:-yamimaho}"
case "$STACK" in
  janken-card|yamimaho) ;;
  *)
    echo "Usage: $0 [janken-card|yamimaho]" >&2
    exit 1
    ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TF_DIR="$ROOT/infra/terraform"

BUCKET="$("$ROOT/scripts/tf-stack.sh" "$STACK" output -raw s3_bucket_name)"
DIST_ID="$("$ROOT/scripts/tf-stack.sh" "$STACK" output -raw cloudfront_distribution_id)"

cd "$ROOT/frontend"
npm ci
npm run build

aws s3 sync dist/ "s3://${BUCKET}/" --delete
aws cloudfront create-invalidation --distribution-id "$DIST_ID" --paths "/*"

echo "Frontend deployed (${STACK}) to s3://${BUCKET}"
"$ROOT/scripts/tf-stack.sh" "$STACK" output -raw cloudfront_url
