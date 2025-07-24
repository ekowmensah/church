-- Migration: Create sunday_school table
CREATE TABLE sunday_school (
    id INT AUTO_INCREMENT PRIMARY KEY,
    srn VARCHAR(32) NOT NULL UNIQUE,
    photo VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    other_name VARCHAR(100) DEFAULT NULL,
    dob DATE DEFAULT NULL,
    contact VARCHAR(32) DEFAULT NULL,
    gps_address VARCHAR(255) DEFAULT NULL,
    residential_address VARCHAR(255) DEFAULT NULL,
    organization VARCHAR(100) DEFAULT NULL,
    school_attend VARCHAR(150) DEFAULT NULL,
    parents VARCHAR(255) DEFAULT NULL,
    father_name VARCHAR(100) DEFAULT NULL,
    father_contact VARCHAR(32) DEFAULT NULL,
    father_occupation VARCHAR(100) DEFAULT NULL,
    mother_name VARCHAR(100) DEFAULT NULL,
    mother_contact VARCHAR(32) DEFAULT NULL,
    mother_occupation VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
