CREATE TABLE IF NOT EXISTS trade_sim_accounts (
    account_key VARCHAR(32) NOT NULL,
    account_name VARCHAR(80) NOT NULL,
    initial_balance DECIMAL(20, 8) NOT NULL DEFAULT 100.00000000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
