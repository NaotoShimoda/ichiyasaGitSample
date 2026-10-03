# CLAUDE.md

## ユーザー
- MQL（MT4/MT5）の開発・販売者。C / C# / Windows デスクトップアプリも得意。裁量トレーダー
- 回答は日本語で、単純明快かつ短く。指定ロジックは諦めず、調査・検証してから実装する
- 不明な点は最適と思う選択肢を複数挙げて提案する
- Claude は「りん」と呼ばれる

## リポジトリ
- ルート（index.html, assets, images）は Git 教本のサンプルなので触らない
- 作業対象は `fx-monitor/`（仕様と設置手順は `fx-monitor/README.md`）

## fx-monitor の概要
MT5 の EA → WebRequest → ラッコサーバー（共有サーバー: PHP + MySQL）
- `server/api.php` 受信（heartbeat / snapshot / signal）、X-Api-Key 認証、シグナル重複排除
- `server/index.php` 管理ダッシュボード（パスワード認証）
- `server/board.php` HP 埋め込み用の公開ボード（iframe、60秒毎更新）
- `server/cron_check.php` EA 停止検知 → Discord 通知（cron 5分毎、CLI 専用）
- `mql/FxMonitor.mqh` 送信ライブラリ（MQL4/5 共通） / `mql/FxMonitorSample.mq5` サンプル EA

### 決定済みの方針（理由付き、覆す場合はユーザーに確認）
- 通知は Discord（LINE Notify は 2025/3 終了）
- 公開ボードは口座番号・売買シグナルを出さない（投資助言とみなされるリスク／販売 EA の価値保護）
- WebRequest は同期処理のため、送信は足確定時・シグナル時のみ
- 共有サーバーは常駐プロセス不可 → Grafana / Gitea は使わない。ソース管理は GitHub
- ライセンス認証サーバーはラッコサーバー上に構築済み（同じ DB を使う場合も `fxm_` 接頭辞で共存）

## 状況
- 完了: 実装、PHP 8.3 + SQLite でのローカル検証（PR #1 マージ済み）
- 未検証: MySQL 実機、MQL のコンパイル

## 残作業（デスクトップアプリで継続）
1. MQL コンパイル確認・エラー修正
   - MT5 のデータフォルダ（MT5 メニュー: ファイル → データフォルダを開く）の `MQL5\Experts\FxMonitor\` に `FxMonitor.mqh` と `FxMonitorSample.mq5` をコピー
   - `"<MT5インストール先>\metaeditor64.exe" /compile:"<パス>\FxMonitorSample.mq5" /log:"<パス>\compile.log"`
   - ログは UTF-16LE。`0 errors` になるまで修正。修正はリポジトリの `fx-monitor/mql/` にも反映
2. サーバー設置（ラッコサーバー）
   - DB 作成（コントロールパネル）→ phpMyAdmin で `server/schema.sql` 実行
   - `server/` の中身を `public_html/fxmon/` へ SFTP/FTPS でアップロード
   - `config.sample.php` を元にサーバー上で `config.php` を作成
   - cron: `*/5 * * * * /usr/bin/php <設置パス>/cron_check.php`（php のパスはパネル表記に合わせる）
3. 動作確認: curl で api.php に heartbeat を POST → index.php / board.php に反映されるか
4. MT5（ユーザーの手作業）: WebRequest 許可 URL にドメイン追加 → サンプル EA 適用
5. HP に board.php の iframe を追加（README の HTML）

## 守ること
- `config.php` は絶対にコミットしない（`.gitignore` 済み）
- パスワード・API キー・Webhook URL をチャットに貼らせない。SSH 鍵 / 保存済み接続設定 / 環境変数を使う
- サーバーへの書き込み・削除は実行前に内容を示す
- コミットは小さく。master へ直接 push せず、ブランチ + PR
