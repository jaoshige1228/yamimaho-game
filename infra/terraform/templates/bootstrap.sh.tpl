#!/bin/bash
set -euo pipefail

dnf install -y docker git
systemctl enable --now docker
usermod -aG docker ec2-user || true
mkdir -p ${opt_dir}
