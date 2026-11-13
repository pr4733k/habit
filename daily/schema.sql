CREATE TABLE IF NOT EXISTS daily_notes (
  id VARCHAR(36) PRIMARY KEY,
  log_date VARCHAR(10) NOT NULL,
  raw_note TEXT NOT NULL,
  revision INTEGER NOT NULL,
  created_at VARCHAR(26) NOT NULL,
  parsed TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS daily_goal_targets (
  effective_date VARCHAR(10) PRIMARY KEY,
  protein INTEGER NOT NULL,
  carbs INTEGER NOT NULL,
  fat INTEGER NOT NULL,
  water_ml INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS daily_runs (
  activity_id VARCHAR(32) PRIMARY KEY,
  log_date VARCHAR(10) NOT NULL,
  summary TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS daily_sync_request (
  id INTEGER PRIMARY KEY,
  state VARCHAR(8) NOT NULL,
  lease VARCHAR(32) DEFAULT NULL,
  updated_at BIGINT NOT NULL
);
