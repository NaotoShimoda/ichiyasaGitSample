-- MySQL / MariaDB 用（phpMyAdmin の SQL タブで実行）
-- 既存のライセンスサーバーと同じDBでも衝突しないよう fxm_ 接頭辞を付けている

CREATE TABLE IF NOT EXISTS fxm_snapshots (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account    VARCHAR(32)  NOT NULL DEFAULT '',
  symbol     VARCHAR(32)  NOT NULL,
  timeframe  VARCHAR(8)   NOT NULL,
  trend      TINYINT      NOT NULL DEFAULT 0,   -- 1:上昇 -1:下降 0:レンジ
  price      DOUBLE       NOT NULL DEFAULT 0,
  bar_time   VARCHAR(20)  NOT NULL DEFAULT '',  -- ブローカー時間
  data       TEXT         NULL,                 -- 任意のインジ値(JSON)
  updated_at DATETIME     NOT NULL,
  UNIQUE KEY uq_snap (account, symbol, timeframe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fxm_signals (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account    VARCHAR(32)  NOT NULL DEFAULT '',
  symbol     VARCHAR(32)  NOT NULL,
  timeframe  VARCHAR(8)   NOT NULL,
  side       VARCHAR(16)  NOT NULL,             -- BUY / SELL など
  price      DOUBLE       NOT NULL DEFAULT 0,
  bar_time   VARCHAR(20)  NOT NULL DEFAULT '',
  message    VARCHAR(255) NOT NULL DEFAULT '',
  notified   TINYINT      NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_sig (account, symbol, timeframe, side, bar_time),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fxm_heartbeats (
  account    VARCHAR(32)  NOT NULL PRIMARY KEY,
  info       VARCHAR(255) NOT NULL DEFAULT '',
  last_seen  DATETIME     NOT NULL,
  alerted    TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
