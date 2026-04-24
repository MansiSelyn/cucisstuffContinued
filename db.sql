-- Adatbázis létrehozása
CREATE DATABASE IF NOT EXISTS cuci_ady_pepa_hu;

USE cuci_ady_pepa_hu;

-- Jelszavak tábla
CREATE TABLE
    IF NOT EXISTS passwords (
        id INT AUTO_INCREMENT PRIMARY KEY,
        password_hash VARCHAR(255) NOT NULL,
        UNIQUE KEY uniq_password_hash (password_hash)
    ) ENGINE = InnoDB;

-- Felhasználók tábla (profile_picture mezővel)
CREATE TABLE
    IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        username VARCHAR(100) NOT NULL,
        password_id INT NOT NULL,
        profile_picture VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_email (email),
        UNIQUE KEY uniq_username (username),
        CONSTRAINT fk_users_passwords FOREIGN KEY (password_id) REFERENCES passwords (id) ON DELETE RESTRICT
    ) ENGINE = InnoDB;

-- Adminok tábla
CREATE TABLE
    IF NOT EXISTS admins (
        user_id INT PRIMARY KEY,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_admins_users FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- Termékek tábla (sold és updated_at mezőkkel)
CREATE TABLE
    IF NOT EXISTS items (
        id CHAR(12) PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        sold BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_items_users FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- Termék képek tábla
CREATE TABLE
    IF NOT EXISTS item_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id CHAR(12) NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        image_filename VARCHAR(255) NOT NULL,
        is_primary BOOLEAN DEFAULT FALSE,
        sort_order INT DEFAULT 0,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_images_items FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- Termék jelentések tábla
CREATE TABLE
    IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id CHAR(12) NOT NULL,
        user_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM ('pending', 'resolved', 'dismissed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- Üzenetek tábla
CREATE TABLE
    IF NOT EXISTS uzenetek (
        id CHAR(25) PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_read BOOLEAN DEFAULT FALSE,
        CONSTRAINT fk_uzenetek_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_uzenetek_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
        INDEX idx_sender (sender_id),
        INDEX idx_receiver (receiver_id),
        INDEX idx_sent_at (sent_at)
    ) ENGINE = InnoDB;

-- Üzenet jelentések tábla
CREATE TABLE
    IF NOT EXISTS message_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id CHAR(25) NOT NULL,
        reporter_user_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM ('pending', 'resolved', 'dismissed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (message_id) REFERENCES uzenetek (id) ON DELETE CASCADE,
        FOREIGN KEY (reporter_user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- Rendelések tábla
CREATE TABLE
    IF NOT EXISTS orders (
        id CHAR(12) PRIMARY KEY,
        buyer_id INT NOT NULL,
        seller_id INT NOT NULL,
        item_id CHAR(12) NOT NULL,
        status ENUM ('pending', 'completed', 'cancelled') DEFAULT 'pending',
        shipping_name VARCHAR(255) NOT NULL,
        shipping_email VARCHAR(255) NOT NULL,
        shipping_phone VARCHAR(50) NOT NULL,
        shipping_zip VARCHAR(20) NOT NULL,
        shipping_city VARCHAR(100) NOT NULL,
        shipping_address VARCHAR(255) NOT NULL,
        payment_method ENUM ('cod', 'transfer', 'pickup') NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE = InnoDB;

-- ============================================================
-- HIÁNYZÓ OSZLOPOK PÓTLÁSA - FUTTATHATÓ TÖBBSZÖR IS
-- ============================================================

DELIMITER //

-- Segédeljárás: egy oszlop hozzáadása, ha még nem létezik
CREATE PROCEDURE AddColumnIfNotExists(
    IN tableName VARCHAR(64),
    IN columnName VARCHAR(64),
    IN columnDef VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = tableName
          AND COLUMN_NAME = columnName
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tableName, '` ADD COLUMN `', columnName, '` ', columnDef);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- Oszlopok, amik a fejlesztés során kerültek bele, de lehet, hogy hiányoznak a régi adatbázisból

CALL AddColumnIfNotExists('users', 'profile_picture', 'VARCHAR(255) NULL');
CALL AddColumnIfNotExists('items', 'sold', 'BOOLEAN DEFAULT FALSE');
CALL AddColumnIfNotExists('items', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL AddColumnIfNotExists('uzenetek', 'is_read', 'BOOLEAN DEFAULT FALSE');   -- alapból benne van, de biztos, ami biztos

-- Takarítás
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;

-- Admin felhasználó hozzáadása az adminok közé (ha létezik az admin nevű user)
INSERT INTO
    admins (user_id, assigned_at)
SELECT
    id,
    CURRENT_TIMESTAMP
FROM
    users
WHERE
    username = 'admin'
    AND id NOT IN (
        SELECT user_id
        FROM admins
    )
    AND EXISTS (
        SELECT 1
        FROM users
        WHERE username = 'admin'
    );