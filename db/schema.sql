CREATE DATABASE IF NOT EXISTS clemexique
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE clemexique;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE property_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_url TEXT NOT NULL,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(255) NOT NULL,
    description TEXT NULL,
    description_fr TEXT NULL,
    description_en TEXT NULL,
    description_es TEXT NULL,
    listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat',
    price_amount DECIMAL(12,2) NOT NULL,
    price_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    external_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE property_card_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_card_id INT NOT NULL,
    image_url TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_property_card_images_property_card_id (property_card_id),
    CONSTRAINT fk_property_card_images_property_card
        FOREIGN KEY (property_card_id) REFERENCES property_cards(id)
        ON DELETE CASCADE
);


CREATE TABLE restaurant_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    language_code CHAR(2) NOT NULL,
    title VARCHAR(255) NOT NULL,
    icon_code VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_restaurant_categories_language_sort (language_code, sort_order)
);

CREATE TABLE restaurants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NULL,
    price VARCHAR(20) NULL,
    reference_label VARCHAR(255) NULL,
    website_url TEXT NULL,
    website_label VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_restaurants_category_sort (category_id, sort_order),
    CONSTRAINT fk_restaurants_category
        FOREIGN KEY (category_id) REFERENCES restaurant_categories(id)
        ON DELETE CASCADE
);

CREATE TABLE excursion_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    language_code CHAR(2) NOT NULL,
    title VARCHAR(255) NOT NULL,
    icon_code VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_excursion_categories_language_sort (language_code, sort_order)
);

CREATE TABLE excursions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NULL,
    note TEXT NULL,
    website_url TEXT NULL,
    website_label VARCHAR(255) NULL,
    video_url TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_excursions_category_sort (category_id, sort_order),
    CONSTRAINT fk_excursions_category
        FOREIGN KEY (category_id) REFERENCES excursion_categories(id)
        ON DELETE CASCADE
);

CREATE TABLE todo_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    language_code CHAR(2) NOT NULL,
    title VARCHAR(255) NOT NULL,
    icon_code VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_todo_categories_language_sort (language_code, sort_order)
);

CREATE TABLE todo_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NULL,
    note TEXT NULL,
    website_url TEXT NULL,
    website_label VARCHAR(255) NULL,
    video_url TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_todo_items_category_sort (category_id, sort_order),
    CONSTRAINT fk_todo_items_category
        FOREIGN KEY (category_id) REFERENCES todo_categories(id)
        ON DELETE CASCADE
);
