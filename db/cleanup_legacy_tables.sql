USE clemexique;

-- Cleanup only the obsolete legacy property tables.
--
-- KEEP:
-- - users
-- - property_cards
-- - property_card_images
-- - restaurant_categories
-- - restaurants
-- - excursion_categories
-- - excursions
-- - todo_categories
-- - todo_items
--
-- DROP:
-- - favorites
-- - inquiries
-- - properties
-- - property_types
-- - listing_types
-- - property_listing_types
-- - property_pictures
--
-- This script:
-- 1. drops the fully unused legacy tables
--
-- It is written to be re-runnable.

DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS inquiries;
DROP TABLE IF EXISTS property_listing_types;
DROP TABLE IF EXISTS property_pictures;
DROP TABLE IF EXISTS properties;
DROP TABLE IF EXISTS listing_types;
DROP TABLE IF EXISTS property_types;
