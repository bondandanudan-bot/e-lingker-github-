CREATE TABLE lingker_master (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    data_json LONGTEXT NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lingker_auth_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    admin_created TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lingker_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) NOT NULL,
    username_normalized VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'paramedic') NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lingker_records (
    id VARCHAR(80) NOT NULL PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    record_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_lingker_records_owner (owner_user_id),
    CONSTRAINT fk_lingker_records_owner
        FOREIGN KEY (owner_user_id) REFERENCES lingker_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lingker_login_limits (
    rate_key CHAR(64) NOT NULL PRIMARY KEY,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lingker_auth_state (id, admin_created) VALUES (1, 0);

INSERT INTO lingker_master (id, data_json, revision)
VALUES (
    1,
    '{"areas":["PIT / Penambangan Terbuka","Tambang Bawah Tanah (Underground)","Workshop & Maintenance Unit","Processing Plant / Crusher / Smelter","Hauling Road / Jalan Angkut","Port & Stockpile / Jetty","Laboratorium & Sampling Room","Office & Mess / Camp Area"],"places":[]}',
    0
);
