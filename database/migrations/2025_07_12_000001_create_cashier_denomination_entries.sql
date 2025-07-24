-- Migration: Create cashier_denomination_entries table
CREATE TABLE IF NOT EXISTS cashier_denomination_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    entry_date DATE NOT NULL,
    denom_200 INT NOT NULL DEFAULT 0,
    denom_100 INT NOT NULL DEFAULT 0,
    denom_50 INT NOT NULL DEFAULT 0,
    denom_20 INT NOT NULL DEFAULT 0,
    denom_10 INT NOT NULL DEFAULT 0,
    denom_5 INT NOT NULL DEFAULT 0,
    denom_2 INT NOT NULL DEFAULT 0,
    denom_1 INT NOT NULL DEFAULT 0,
    denom_2_Coin INT NOT NULL DEFAULT 0,
    denom_1_Coin INT NOT NULL DEFAULT 0,
    denom_50_p INT NOT NULL DEFAULT 0,
    denom_20_p INT NOT NULL DEFAULT 0,
    denom_10_p INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cashier_date (cashier_id, entry_date)
);
