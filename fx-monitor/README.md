# FX Monitor（ラッコサーバー版）

MT5/MT4 の EA から環境認識・シグナルをラッコサーバーへ送信し、
ダッシュボード表示と Discord 通知、EA 停止検知を行う。

```
[MT5 + EA] --WebRequest(HTTPS)--> [ラッコサーバー api.php] --> MySQL
                                        |--> Discord 通知
[cron 5分毎 cron_check.php] --> EA停止なら Discord 通知
[ブラウザ/スマホ] --> index.php（環境認識マトリクス・シグナル履歴）
```

## 構成
| ファイル | 役割 |
|---|---|
| server/api.php | EA からの受信（heartbeat / snapshot / signal） |
| server/index.php | ダッシュボード（パスワード認証） |
| server/cron_check.php | EA 停止検知（CLI 専用） |
| server/schema.sql | テーブル定義（`fxm_` 接頭辞） |
| mql/FxMonitor.mqh | 送信ライブラリ（MQL4/MQL5 共通） |
| mql/FxMonitorSample.mq5 | サンプル EA（多通貨×多時間足の MA 環境認識＋クロス通知、発注なし） |

## サーバー設置手順
1. コントロールパネルで MySQL DB を作成（既存のライセンス用 DB でも可）
2. phpMyAdmin で `server/schema.sql` を実行
3. `server/` の中身を `public_html/fxmon/` などへアップロード
4. `config.sample.php` を `config.php` にコピーして編集
   - `api_key` : 長いランダム文字列
   - `discord_webhook` : Discord チャンネル設定 → 連携サービス → ウェブフック で発行
5. cron 設定（5分毎）
   `/usr/bin/php /home/<ユーザー>/<ドメイン>/public_html/fxmon/cron_check.php`
   ※ php のパスはコントロールパネルの cron 画面の表記に合わせる
6. `https://<ドメイン>/fxmon/` を開いてログイン確認

## MT5 側
1. `FxMonitor.mqh` と `FxMonitorSample.mq5` を `MQL5/Experts/FxMonitor/` に置いてコンパイル
2. ツール → オプション → エキスパートアドバイザー
   「WebRequest を許可する URL リスト」に `https://<ドメイン>` を追加
3. EA をチャートに適用し、`InpUrl` と `InpApiKey` を設定

既存 EA に組み込む場合は `#include "FxMonitor.mqh"` して
`FxmInit()` → `FxmSendSignal()` / `FxmSendSnapshot()` / `FxmSendHeartbeat()` を呼ぶ。

## 仕様・注意
- WebRequest は EA/スクリプトのみ（インジケーター不可）。ストラテジーテスターでは送信しない
- WebRequest は同期処理なので、送信は「足確定時」「シグナル時」に限定（ティック毎の送信はしない）
- シグナルは (口座, 通貨ペア, 足, side, 足時刻) で重複排除 → 再送・EA再起動でも二重通知しない
- EA 停止後 `heartbeat_timeout_min` 分で通知、復帰時も通知
- LINE Notify は 2025/3 で終了しているため Discord を採用
