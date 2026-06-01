# やみまほ — 魔法学園 RPG

Laravel（API）・Vue（SPA）・MySQL 構成のブラウザゲームです。フロントエンドのビルド・開発には **Node.js v22** を使用してください（`frontend/.nvmrc` 参照）。

初期実装として **4人パーティ vs 敵3体** のターン制戦闘デモ（勝敗まで）を提供しています。WebSocket / Reverb は使用しません。

---

## 戦闘の正（サーバー権威）— 外せない仕様

**戦闘のすべての状態変化・ターン進行・ダメージ計算はバックエンドが担います。** フロントエンドは API を呼び出し、返ってきた `state` を表示し `events` を演出するだけです。戦闘ロジックをクライアントに置きません。

| 層 | 役割 |
|----|------|
| `BattleEngine` | 1アクションの適用・ダメージ・バフ・勝敗判定 |
| `BattleOrchestrator` | プレイヤー1手 → 敵フェーズ自動進行（**API とテストで共通**） |
| `BattleController` | HTTP で state を保存・返却 |
| `BattleSimulator` | DB/API なしで戦闘を最後まで自動再生（**バランス検証用**） |
| Vue 戦闘画面 | 表示・入力・SE/BGM のみ |

### ターンの流れ（API で完結）

1. `POST /api/battles/demo` … 戦闘開始。`current_actor` が味方なら入力待ち。
2. `POST /api/battles/{id}/actions` … こぶし / 防御 / 魔法を送信。
3. サーバーが行動を解決し、必要なら **敵1→敵2→敵3** まで自動で進める。
4. 次の味方ターン、または `victory` / `defeat` まで繰り返す。

**フロントなし**でも `curl` や PHPUnit だけで上記の一連の流れを再現できます。

### バランス検証

大量の戦闘を自動実行する場合:

```bash
cd backend
php artisan battle:simulate --runs=100
```

`BattleSimulator` + `BattleActionStrategy`（例: `AggressivePunchStrategy`）で、同じ `BattleOrchestrator` 経路を使います。独自 AI を試すときは `BattleActionStrategy` を実装してください。

---

## キャラクターステータス

筋力・魔力・防御力・素早さ・知力・精神力の **6ステータス**、ダメージ算出式、CSV/DB の管理方針は [`docs/キャラクターステータス.md`](docs/キャラクターステータス.md) にまとめています。

| データ | 場所 |
|--------|------|
| 初期値（CSV） | [`backend/database/csv/`](backend/database/csv/) |
| マスター定義（DB） | `character_masters` / `enemy_masters` / `spell_masters` |
| ユーザーごとの成長後 | `user_characters` |

デモ戦闘は CSV/DB マスターと新ダメージ式を使用します。`php artisan db:seed --class=MasterDataSeeder` で CSV を DB に投入できます。

---

## ダンジョン探索

待機画面から **1 層** に入り、「進む」で深さを進めます（全 3 層構成、現状プレイ可能なのは 1 層のみ）。各層の最深部でボス戦があり、撃破で次の層が解放されます。戦闘（30%）と探索イベント（70%）の抽選、矢トラップ・宝箱などの詳細は [`docs/ダンジョン探索.md`](docs/ダンジョン探索.md) を参照してください。

---

## 前提条件

| 用途 | 必要なもの |
|------|----------------|
| Docker で起動 | Docker Desktop など（Compose v2） |
| フロントをビルド | Node.js **22.x** |
| AWS デプロイ | AWS アカウント、Terraform 1.5+、AWS CLI |

---

## 環境構築（ローカル・Docker）

リポジトリルートで作業します。

### 1. フロントエンドのビルド

```bash
cd frontend
npm ci
npm run build
cd ..
```

または:

```bash
docker compose --profile build run --rm frontend-build
```

### 2. コンテナ起動

```bash
docker compose up -d --build
```

- **アプリ（SPA + API）**: http://localhost
- タイトル画面 → **デモ戦闘を始める** で戦闘画面へ

### 3. 環境変数（Docker）

バックエンド用: [`docker/laravel.env`](docker/laravel.env)

フロントの API は **相対パス `/api`** で呼び出します（`localhost` と `127.0.0.1` の混在を避けるため）。開発時は `npm run dev` の Vite プロキシを PHP に向けてください。

### 4. よくある操作

| 操作 | コマンド |
|------|----------|
| ログ確認 | `docker compose logs -f php` |
| 停止 | `docker compose down` |
| DB ごと消す | `docker compose down -v` |

---

## 戦闘 API

| メソッド | パス | 説明 |
|----------|------|------|
| `POST` | `/api/battles/demo` | デモ戦闘開始 |
| `GET` | `/api/battles/{id}` | 状態取得 |
| `POST` | `/api/battles/{id}/actions` | 行動（`punch` / `defend` / `spell`） |

ターン順: PC1 → PC2 → PC3 → PC4 → 敵1 → 敵2 → 敵3 → …

---

## テスト（バックエンド）

戦闘は **ユニットテスト**（`BattleOrchestrator` 直叩き・DB 不要）と **Feature テスト**（HTTP API のみで開始〜勝敗まで）の両方でカバーしています。

```bash
cd backend
composer install
php artisan test
```

| テスト | 内容 |
|--------|------|
| `tests/Unit/Battle/BattleOrchestratorTest.php` | ターン順・敵フェーズ・防御 |
| `tests/Unit/Battle/BattleSimulatorTest.php` | 自動シミュレーションで勝敗まで |
| `tests/Feature/BattleApiTest.php` | API の基本動作 |
| `tests/Feature/BattleApiFullFlowTest.php` | **API だけ**で戦闘を最後まで進行 |

---

## デプロイ（AWS・Terraform）

本番構成は **Vue → S3 + CloudFront**、**Laravel → Lightsail（Docker）**、**MySQL → Lightsail Managed Database** です。

| コンポーネント | AWS リソース |
|----------------|--------------|
| フロント | S3 + CloudFront |
| API | Lightsail 静的 IP + Docker |
| MySQL | Lightsail Managed Database |

CloudFront は `/api/*` と `/up` を Lightsail に振り分け、それ以外を S3 の SPA に渡します（WebSocket プロキシはありません）。

### マルチスタック（janken-card / yamimaho）

同じ Terraform 定義で **別 state・別 AWS リソース** として2本運用します。詳細は [`infra/terraform/README.md`](infra/terraform/README.md)。

```bash
cp infra/terraform/environments/janken-card.tfvars.example infra/terraform/environments/janken-card.tfvars
cp infra/terraform/environments/yamimaho.tfvars.example infra/terraform/environments/yamimaho.tfvars
# 各ファイルを実値に編集（yamimaho の DB パスワードは janken-card と別にすること）

./scripts/tf-stack.sh janken-card init
./scripts/tf-stack.sh yamimaho init
./scripts/tf-stack.sh janken-card plan   # 既存本番の差分確認
./scripts/tf-stack.sh yamimaho apply     # やみまほ新規スタック作成
```

### 手順概要（1スタックあたり）

1. `environments/<スタック名>.tfvars` を用意
2. `./scripts/tf-stack.sh <スタック名> apply`
3. `./scripts/deploy-frontend.sh <スタック名>`
4. Lightsail で Docker 起動（`.env` は `infra/terraform/generated/<スタック名>/app.env` → 各 `app_opt_dir`）。初回起動時に `migrate` + `db:seed` が走る（本番イメージは Faker なしのため Factory は使わない）

**既にコンテナを起動済みのとき**（マスタ未投入で API が 404 のとき）:

```bash
docker exec -it <コンテナ名> php artisan db:seed --force
```

### デプロイ後チェック（重要）

```bash
# オリジン直叩き
curl -i -X POST "http://<lightsail-ip>/api/battles/demo" -H "Accept: application/json"

# CloudFront 経由
curl -i -X POST "https://<cloudfront>/api/battles/demo" -H "Accept: application/json"
```

両方とも `content-type: application/json` であること。HTML が返る場合は CloudFront の `/api/*` ビヘイビアまたは Nginx の `location ^~ /api/` を確認してください（旧プロジェクトの README §10 参照）。

---

## ディレクトリ構成

```
├── docs/             # ゲーム仕様（戦闘の流れ・ステータスなど）
├── backend/          # Laravel（戦闘エンジン・API）
│   └── database/csv/ # キャラ・敵の初期ステータス CSV
├── frontend/         # Vue + Vite（戦闘画面）
├── docker/           # 開発用 Docker
├── docker/prod/      # 本番バックエンド Docker
├── scripts/          # deploy-frontend.sh
├── 素材_sample/      # 元素材（public/assets にコピー済み）
└── infra/terraform/  # AWS 定義
```

---

## プレイヤー向け文言の方針

やみまほは **ゲーム** です。画面に表示するテキストは、システムやアプリの操作説明ではなく、プレイヤーが世界に没入できる言葉遣いに統一します。

| 避ける | 使う |
|--------|------|
| 実行 / 実行する | **決定** |
| ログ / 戦闘ログ | **記録** / **戦いの記録** |
| 準備中… | **少々お待ちを…** |
| 閉じる / 戻る | **やめる** |
| タップでコマンド | **タップして選ぶ** |

- ボタンや案内文は「〜してください」より、ゲーム内の指示として自然な表現を優先する
- 新しい画面・モーダルを追加するときも、この方針に従う
- 戦闘のゲーム仕様（プレイヤー視点）は [`docs/戦闘の流れ.md`](docs/戦闘の流れ.md) を参照

---

## 素材

戦闘で使用する画像・音声は `frontend/public/assets/` に配置されています（`素材_sample` からコピー）。

- キャラクター: PC1–PC4
- 敵: kappa（3体とも同一画像）
- BGM: 戦闘中ループ
- SE: 攻撃時

### 立ち絵（キャラクター画像）の仕様

味方 PC の立ち絵は **縦 1200 × 横 600 px**（全身）です。CSS ではこの比率（高さ:幅 = 2:1）を前提に表示します。

| 用途 | 画像内の目安 |
|------|----------------|
| 全身 | 画像全体 |
| 上半身アップ | 上から約 50%（戦闘画面のキャラ枠はこちらを使用） |
| 顔アップ | 上から約 25% など、必要に応じて `object-position` で調整 |

戦闘画面のキャラ枠は `overflow: hidden` で枠外を切り抜き、上半身が見えるようにしています。画像を差し替えるときも **1200×600 px・全身立ち** を維持してください。



## スペシャルサンクス
敵アイコン
https://pipoya.net/sozai/assets/enemyillust/enemy-image/