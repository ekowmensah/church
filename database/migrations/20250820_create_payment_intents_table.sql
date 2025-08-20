CREATE TABLE IF NOT EXISTS payment_intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_reference VARCHAR(64) NOT NULL UNIQUE,
    member_id INT,
    church_id INT,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255),
    customer_name VARCHAR(128),
    customer_phone VARCHAR(32),
    status VARCHAR(32) NOT NULL DEFAULT 'Pending',
    payment_type_id INT,
    payment_period VARCHAR(32),
    payment_period_description VARCHAR(64),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
