-- Create the Database
CREATE DATABASE IF NOT EXISTS clemexique
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE clemexique;

CREATE TABLE property_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_fr VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    description_fr TEXT NULL,
    description_en TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE listing_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_fr VARCHAR(50) NOT NULL,
    name_en VARCHAR(50) NOT NULL
);

CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,

    property_type_id INT NOT NULL,

    name VARCHAR(255) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    city VARCHAR(100) NOT NULL,

    bedrooms INT NOT NULL,
    bathrooms INT NOT NULL,

    pool_type VARCHAR(100) NULL,
    distance_from_beach VARCHAR(100) NULL,

    parking_type ENUM('interieur', 'exterieur', 'les_deux', 'aucun') NOT NULL DEFAULT 'aucun',
    parking_count INT DEFAULT 0,

    has_elevator BOOLEAN NOT NULL DEFAULT FALSE,
    animals_allowed BOOLEAN NOT NULL DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (property_type_id) REFERENCES property_types(id)
);

CREATE TABLE property_listing_types (
    property_id INT NOT NULL,
    listing_type_id INT NOT NULL,

    PRIMARY KEY (property_id, listing_type_id),

    FOREIGN KEY (property_id) REFERENCES properties(id)
    ON DELETE CASCADE,

    FOREIGN KEY (listing_type_id) REFERENCES listing_types(id)
    ON DELETE CASCADE
);

CREATE TABLE property_pictures (
    id INT AUTO_INCREMENT PRIMARY KEY,

    property_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_main BOOLEAN NOT NULL DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (property_id) REFERENCES properties(id)
    ON DELETE CASCADE
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    property_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (user_id, property_id),

    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,

    FOREIGN KEY (property_id) REFERENCES properties(id)
    ON DELETE CASCADE
);

CREATE TABLE inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    property_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,

    FOREIGN KEY (property_id) REFERENCES properties(id)
    ON DELETE CASCADE
);

INSERT INTO property_types (name_fr, name_en) VALUES
('Condo', 'Condo'),
('Maison', 'House'),
('Villa', 'Villa'),
('Appartement', 'Apartment'),
('Terrain', 'Land'),
('Penthouse', 'Penthouse');

INSERT INTO listing_types (name_fr, name_en) VALUES
('Vente', 'Sale'),
('Location', 'Rent');