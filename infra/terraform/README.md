# Terraform（マルチスタック）

**janken-card** と **yamimaho** は同じ `.tf` 定義を使い、**state と tfvars を分離**して別々の AWS リソースとして運用します。

| スタック | state | tfvars |
|----------|-------|--------|
| janken-card（既存本番） | `state/janken-card.tfstate` | `environments/janken-card.tfvars` |
| yamimaho（新規） | `state/yamimaho.tfstate` | `environments/yamimaho.tfvars` |

## 初回セットアップ

```bash
cp environments/janken-card.tfvars.example environments/janken-card.tfvars
cp environments/yamimaho.tfvars.example environments/yamimaho.tfvars
# 各 tfvars を実値に編集（yamimaho は janken-card と別パスワード推奨）

./scripts/tf-stack.sh janken-card init
./scripts/tf-stack.sh yamimaho init
```

既存の `terraform.tfstate` は `state/janken-card.tfstate` に移済みです。`plan` / `apply` の前にスタックごとに `init` が走り、state ファイルが切り替わります。

**スタックを切り替えるとき**は、毎回 `./scripts/tf-stack.sh <名前> ...` を使ってください（内部で `backend-config path=state/<名前>.tfstate` を指定します）。

## 日常コマンド

```bash
./scripts/tf-stack.sh janken-card plan
./scripts/tf-stack.sh janken-card apply

./scripts/tf-stack.sh yamimaho plan
./scripts/tf-stack.sh yamimaho apply
```

出力例:

```bash
./scripts/tf-stack.sh yamimaho output -raw cloudfront_url
```

生成される Laravel `.env`:

- janken-card: `generated/janken-card/app.env` → Lightsail の `/opt/janken/.env`
- yamimaho: `generated/yamimaho/app.env` → `/opt/yamimaho/.env`

フロントデプロイ:

```bash
./scripts/deploy-frontend.sh janken-card
./scripts/deploy-frontend.sh yamimaho
```

## リソース名の衝突回避

`project_name` が AWS 上の名前の基点です（例: `janken-card-app`, `yamimaho-db`, S3 `yamimaho-frontend-<account>`）。**同じ `project_name` を両方の tfvars に書かないでください。**

## Apple Silicon で plan がタイムアウトする場合

arm64 用 Terraform を入れ、`rm -rf .terraform` のあと `init` し直してください。`TF_PLUGIN_TIMEOUT=120` は `tf-stack.sh` が既定で設定します。
