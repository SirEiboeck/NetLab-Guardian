PRAGMA foreign_keys = ON;

CREATE TABLE device (
    id INTEGER PRIMARY KEY,
    hostname TEXT,
    device_type TEXT NOT NULL,
    model TEXT,
    serial_number TEXT UNIQUE,
    firmware TEXT,
    management_ip TEXT,
    rack_position TEXT,
    console_port TEXT,
    inventory_number TEXT,
    notes TEXT,
    first_seen TEXT NOT NULL DEFAULT (datetime('now')),
    removed_at TEXT,
    last_checked_at TEXT,
    CHECK (removed_at IS NULL OR removed_at >= first_seen)
);

CREATE TABLE issue (
    id INTEGER PRIMARY KEY,
    device_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    description TEXT NOT NULL,
    detected_at TEXT NOT NULL DEFAULT (datetime('now')),
    resolved_at TEXT,
    FOREIGN KEY (device_id) REFERENCES device(id) ON DELETE RESTRICT,
    CHECK (resolved_at IS NULL OR resolved_at >= detected_at)
);

CREATE TABLE credential (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL,
    pwd TEXT NOT NULL
);

CREATE TABLE device_credential (
    device_id INTEGER NOT NULL,
    credential_id INTEGER NOT NULL,
    PRIMARY KEY (device_id, credential_id),
    FOREIGN KEY (device_id) REFERENCES device(id) ON DELETE CASCADE,
    FOREIGN KEY (credential_id) REFERENCES credential(id) ON DELETE CASCADE
);

CREATE TABLE recipient (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE command (
    command TEXT PRIMARY KEY
);

CREATE INDEX idx_issue_device_id ON issue(device_id);
CREATE INDEX idx_issue_open ON issue(resolved_at);
CREATE INDEX idx_device_credential_credential_id ON device_credential(credential_id);
CREATE INDEX idx_device_rack ON device(rack_position);

-- Example queries:
-- SELECT * FROM device WHERE removed_at IS NULL;
-- SELECT * FROM issue WHERE resolved_at IS NULL;
-- SELECT d.hostname, i.type, i.description, i.detected_at
-- FROM issue AS i JOIN device AS d ON d.id = i.device_id
-- WHERE i.resolved_at IS NULL;
