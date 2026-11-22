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

CREATE TABLE IF NOT EXISTS daily_coach_state (
  id INTEGER PRIMARY KEY,
  settings TEXT NOT NULL,
  updated_at VARCHAR(32) NOT NULL
);

CREATE TABLE IF NOT EXISTS daily_processing_jobs (
        note_id VARCHAR(36) NOT NULL, revision INTEGER NOT NULL, state VARCHAR(16) NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0, lease VARCHAR(32) DEFAULT NULL, lease_until BIGINT NOT NULL DEFAULT 0,
        next_at BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(280) DEFAULT NULL,
        PRIMARY KEY (note_id, revision));

CREATE TABLE IF NOT EXISTS daily_workout_plans (
        log_date VARCHAR(10) PRIMARY KEY, plan TEXT DEFAULT NULL, generated_at VARCHAR(32) DEFAULT NULL,
        lease VARCHAR(32) DEFAULT NULL, lease_until BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(280) DEFAULT NULL,
        last_started_at BIGINT NOT NULL DEFAULT 0);

CREATE TABLE IF NOT EXISTS daily_ai_usage (
        id VARCHAR(32) PRIMARY KEY, purpose VARCHAR(16) NOT NULL, model VARCHAR(100) NOT NULL,
        input_tokens INTEGER NOT NULL, output_tokens INTEGER NOT NULL, cost DECIMAL(12,8) NOT NULL,
        created_at VARCHAR(32) NOT NULL);
