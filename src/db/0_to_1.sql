PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS properties (
    variable TEXT NOT NULL UNIQUE,
    value TEXT
);

CREATE TABLE IF NOT EXISTS service_type (
    name TEXT NOT NULL UNIQUE,
    type INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS service_0 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    usage REAL,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_0_name_time ON service_0(name, time);

CREATE TABLE IF NOT EXISTS service_1 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_1_name_time ON service_1(name, time);

CREATE TABLE IF NOT EXISTS service_2 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    size INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_2_name_time ON service_2 (name, time);

CREATE TABLE IF NOT EXISTS service_3 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    cpu REAL,
    memory REAL,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_3_name_time ON service_3(name, time);

CREATE TABLE IF NOT EXISTS service_4 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_4_name_time ON service_4(name, time);

CREATE TABLE IF NOT EXISTS service_5 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    cpu REAL,
    memory REAL,
    swap REAL,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_5_name_time ON service_5(name, time);

CREATE TABLE IF NOT EXISTS service_6 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_6_name_time ON service_6(name, time);

CREATE TABLE IF NOT EXISTS service_7 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    code INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_7_name_time ON service_7(name, time);

CREATE TABLE IF NOT EXISTS service_8 (
    name TEXT NOT NULL,
    time INTEGER NOT NULL,
    monitor INTEGER,
    status INTEGER,
    alert INTEGER,
    download INTEGER,
    upload INTEGER,

    UNIQUE(name, time)
);

CREATE INDEX IF NOT EXISTS idx_service_8_name_time ON service_8(name, time);
