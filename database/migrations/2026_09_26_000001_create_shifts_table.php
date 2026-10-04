CREATE TABLE shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NULL,

    check_in_latitude DECIMAL(10, 7) NULL,
    check_in_longitude DECIMAL(10, 7) NULL,

    check_out_latitude DECIMAL(10, 7) NULL,
    check_out_longitude DECIMAL(10, 7) NULL,

    total_seconds INT UNSIGNED DEFAULT 0,

    status ENUM('active', 'completed', 'cancelled')
        NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_shifts_user_id (user_id),
    INDEX idx_shifts_status (status),
    INDEX idx_shifts_check_in_at (check_in_at),

    CONSTRAINT fk_shifts_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);