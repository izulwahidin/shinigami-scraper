PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS projects (
    manga_id              TEXT PRIMARY KEY,
    title                 TEXT NOT NULL,
    slug                  TEXT NOT NULL,
    format                TEXT NOT NULL DEFAULT 'manga',
    cover_url             TEXT,
    cover_portrait_url    TEXT,
    latest_chapter_id     TEXT,
    latest_chapter_number REAL,
    source_updated_at     TEXT,
    payload               TEXT,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    last_synced_at        TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_projects_format_slug
    ON projects (format, slug);

CREATE INDEX IF NOT EXISTS ix_projects_latest
    ON projects (latest_chapter_id);

CREATE TABLE IF NOT EXISTS chapters (
    chapter_id         TEXT PRIMARY KEY,
    manga_id           TEXT NOT NULL REFERENCES projects (manga_id) ON DELETE CASCADE,
    chapter_number     REAL NOT NULL DEFAULT 0,
    title              TEXT,
    folder             TEXT NOT NULL,
    release_date       TEXT,
    source_updated_at  TEXT,
    image_count        INTEGER NOT NULL DEFAULT 0,
    downloaded_count   INTEGER NOT NULL DEFAULT 0,
    bytes              INTEGER NOT NULL DEFAULT 0,
    status             TEXT NOT NULL DEFAULT 'pending',
    dirty              INTEGER NOT NULL DEFAULT 0,
    attempts           INTEGER NOT NULL DEFAULT 0,
    last_error         TEXT,
    discovered_at      TEXT NOT NULL,
    updated_at         TEXT NOT NULL,
    completed_at       TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_chapters_manga_folder
    ON chapters (manga_id, folder);

CREATE INDEX IF NOT EXISTS ix_chapters_manga_number
    ON chapters (manga_id, chapter_number DESC);

CREATE INDEX IF NOT EXISTS ix_chapters_status
    ON chapters (status);

CREATE TABLE IF NOT EXISTS runs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    command             TEXT NOT NULL,
    started_at          TEXT NOT NULL,
    finished_at         TEXT,
    status              TEXT NOT NULL DEFAULT 'running',
    projects_seen       INTEGER NOT NULL DEFAULT 0,
    projects_synced     INTEGER NOT NULL DEFAULT 0,
    projects_skipped    INTEGER NOT NULL DEFAULT 0,
    chapters_new        INTEGER NOT NULL DEFAULT 0,
    chapters_done       INTEGER NOT NULL DEFAULT 0,
    chapters_failed     INTEGER NOT NULL DEFAULT 0,
    chapters_skipped    INTEGER NOT NULL DEFAULT 0,
    images_downloaded   INTEGER NOT NULL DEFAULT 0,
    bytes_downloaded    INTEGER NOT NULL DEFAULT 0,
    error               TEXT
);
