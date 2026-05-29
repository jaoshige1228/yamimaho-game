#!/usr/bin/env bash
# Run Terraform for a named stack (separate state per product).
# Usage: ./scripts/tf-stack.sh <janken-card|yamimaho> <terraform-command> [args...]
set -euo pipefail

STACK="${1:?Usage: $0 <janken-card|yamimaho> <terraform-command> [args...]}"
shift

case "$STACK" in
  janken-card|yamimaho) ;;
  *)
    echo "Unknown stack: $STACK (use janken-card or yamimaho)" >&2
    exit 1
    ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TF_DIR="$ROOT/infra/terraform"
STATE_FILE="state/${STACK}.tfstate"
VAR_FILE="$TF_DIR/environments/${STACK}.tfvars"

if [[ ! -f "$VAR_FILE" ]]; then
  echo "Missing $VAR_FILE — copy from environments/${STACK}.tfvars.example" >&2
  exit 1
fi

mkdir -p "$TF_DIR/state" "$TF_DIR/generated/${STACK}"

export TF_PLUGIN_TIMEOUT="${TF_PLUGIN_TIMEOUT:-120}"

cd "$TF_DIR"

configure_backend() {
  terraform init -input=false -backend-config="path=${STATE_FILE}" "$@"
}

CMD="${1:?Usage: $0 $STACK <init|plan|apply|output|...>}"
shift

if [[ "$CMD" == "init" ]]; then
  configure_backend "$@"
  exit 0
fi

configure_backend -reconfigure >/dev/null

# output / show / state などは -var-file 非対応
case "$CMD" in
  output|show|state|version|providers|fmt|validate)
    exec terraform "$CMD" "$@"
    ;;
  *)
    exec terraform "$CMD" -var-file="$VAR_FILE" "$@"
    ;;
esac
