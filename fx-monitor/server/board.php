<?php
// 公開用 環境認識ボード（ログイン不要・HP に iframe で埋め込む）
//   <iframe src="https://<ドメイン>/fxmon/board.php" style="width:100%;height:420px;border:0"></iframe>
// 口座番号・シグナルは出さない。60秒毎に JSON を取得して画面を更新する
declare(strict_types=1);
require __DIR__ . '/lib.php';

function fxm_board_data(): array
{
    $cfg = fxm_config();
    $db  = fxm_db();
    $account = (string)($cfg['public_account'] ?? '');
    if ($account === '') {
        $account = (string)$db->query('SELECT account FROM fxm_heartbeats ORDER BY last_seen DESC LIMIT 1')->fetchColumn();
    }

    $st = $db->prepare('SELECT symbol, timeframe, trend, price, updated_at FROM fxm_snapshots WHERE account = ? ORDER BY symbol');
    $st->execute([$account]);

    $order = ['MN1', 'W1', 'D1', 'H4', 'H1', 'M30', 'M15', 'M5', 'M1']; // 上位足から表示
    $showPrice = (bool)($cfg['public_show_price'] ?? true);
    $rows = []; $tfs = [];
    foreach ($st->fetchAll() as $r) {
        $rows[$r['symbol']][$r['timeframe']] = ['t' => (int)$r['trend'], 'p' => $showPrice ? (string)$r['price'] : null];
        $tfs[$r['timeframe']] = true;
    }
    $tfs = array_values(array_filter($order, fn($tf) => isset($tfs[$tf])));

    $out = [];
    foreach ($rows as $symbol => $cells) {
        $out[] = ['symbol' => $symbol, 'cells' => $cells, 'score' => array_sum(array_column($cells, 't'))];
    }

    $hb = $db->prepare('SELECT last_seen FROM fxm_heartbeats WHERE account = ?');
    $hb->execute([$account]);
    $lastSeen = (string)$hb->fetchColumn();
    $live = $lastSeen !== '' && time() - strtotime($lastSeen) < 60 * (int)$cfg['heartbeat_timeout_min'];

    return ['updated' => $lastSeen, 'live' => $live, 'tfs' => $tfs, 'rows' => $out];
}

$data = fxm_board_data();
header('Cache-Control: public, max-age=30'); // 訪問者が多くても DB 負荷を抑える

if (isset($_GET['json'])) {
    fxm_json(200, $data);
}
$title = (string)(fxm_config()['public_title'] ?? 'リアルタイム環境認識ボード');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= fxm_h($title) ?></title>
<style>
:root{--bg:#0f1218;--card:#171b23;--line:#262c38;--txt:#e6e9ef;--sub:#8a93a6;
      --up:#1f9d61;--upbg:rgba(31,157,97,.16);--dn:#e0464e;--dnbg:rgba(224,70,78,.16);--fl:#8a93a6;--flbg:rgba(138,147,166,.12)}
@media (prefers-color-scheme: light){:root{--bg:#fff;--card:#f6f7f9;--line:#e1e4ea;--txt:#1b1f27;--sub:#667085;
      --up:#13804c;--upbg:rgba(19,128,76,.10);--dn:#c62f37;--dnbg:rgba(198,47,55,.10);--fl:#667085;--flbg:rgba(102,112,133,.10)}}
*{box-sizing:border-box}
body{margin:0;padding:12px;background:var(--bg);color:var(--txt);font-family:-apple-system,"Hiragino Sans","Yu Gothic UI",sans-serif}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
.head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:6px;margin-bottom:10px}
h1{font-size:16px;margin:0}
.st{font-size:12px;color:var(--sub)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px;background:var(--fl)}
.dot.live{background:var(--up);animation:pulse 2s infinite}
@keyframes pulse{50%{opacity:.35}}
.wrap{overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:4px;font-variant-numeric:tabular-nums}
th{font-size:12px;color:var(--sub);font-weight:600;padding:4px}
th.sym{text-align:left}
td{text-align:center;border-radius:6px;padding:6px 4px;font-size:13px;white-space:nowrap;transition:background .6s}
td.sym{text-align:left;font-weight:700;background:none}
td.up{background:var(--upbg);color:var(--up)}td.dn{background:var(--dnbg);color:var(--dn)}td.fl{background:var(--flbg);color:var(--fl)}
td.flash{outline:2px solid currentColor}
.p{display:block;font-size:11px;opacity:.8}
.foot{margin-top:8px;font-size:11px;color:var(--sub);line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <div class="head">
    <h1><?= fxm_h($title) ?></h1>
    <span class="st"><span class="dot" id="dot"></span><span id="status"></span></span>
  </div>
  <div class="wrap"><table id="board"></table></div>
  <div class="foot">▲上昇 ▼下降 ◆レンジ（確定足ベース・MA判定）。総合＝全時間足の方向の合計。<br>
  本表示は情報提供のみを目的としたもので、投資助言ではありません。売買は自己責任でお願いします。</div>
</div>
<script>
const INITIAL = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const mark = t => t > 0 ? '▲' : (t < 0 ? '▼' : '◆');
const cls  = t => t > 0 ? 'up' : (t < 0 ? 'dn' : 'fl');
const esc  = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let prev = {};

function scoreLabel(s, n) {
  if (s >= Math.ceil(n * 0.75)) return [1, '強い上昇'];
  if (s > 0) return [1, '上昇寄り'];
  if (s <= -Math.ceil(n * 0.75)) return [-1, '強い下降'];
  if (s < 0) return [-1, '下降寄り'];
  return [0, '中立'];
}

function render(d) {
  const n = d.tfs.length;
  let h = '<tr><th class="sym">通貨ペア</th>' + d.tfs.map(tf => `<th>${esc(tf)}</th>`).join('') + '<th>総合</th></tr>';
  const next = {};
  for (const r of d.rows) {
    h += `<tr><td class="sym">${esc(r.symbol)}</td>`;
    for (const tf of d.tfs) {
      const c = r.cells[tf];
      if (!c) { h += '<td>-</td>'; continue; }
      const key = r.symbol + tf;
      next[key] = c.t;
      const changed = key in prev && prev[key] !== c.t;
      h += `<td class="${cls(c.t)}${changed ? ' flash' : ''}">${mark(c.t)}${c.p !== null ? `<span class="p">${esc(c.p)}</span>` : ''}</td>`;
    }
    const [t, label] = scoreLabel(r.score, n);
    h += `<td class="${cls(t)}">${label}</td></tr>`;
  }
  if (!d.rows.length) h += `<tr><td colspan="${n + 2}">データ準備中</td></tr>`;
  prev = next;
  document.getElementById('board').innerHTML = h;
  document.getElementById('dot').className = 'dot' + (d.live ? ' live' : '');
  document.getElementById('status').textContent =
    (d.live ? 'LIVE' : '更新停止中（市場休場・メンテナンス）') + (d.updated ? ' / 最終更新 ' + d.updated.slice(5, 16) : '');
}

render(INITIAL);
setInterval(async () => {
  try {
    const res = await fetch('?json=1', {cache: 'no-store'});
    if (res.ok) render(await res.json());
  } catch (e) { /* 一時的な通信失敗は次回に再試行 */ }
}, 60000);
</script>
</body>
</html>
