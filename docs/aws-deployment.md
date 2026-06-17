# AWS deployment

This application is a Slim 4 PHP app with:

- Apache or Nginx pointing at `public/`
- PHP 8.1+ with `pdo_mysql`, `mbstring`, `zip`, and `gd` or ImageMagick
- MySQL or MariaDB
- Writable `public/uploads/` and `var/upload-progress/`

## HEIC / AVIF uploads on AWS

If you want HEIC or AVIF property uploads to work in production, the server must have a converter installed.

Recommended production setup:

- Install `ImageMagick`
- Make sure it has `HEIC` / `AVIF` support through `libheif`
- Set an explicit env var so the app uses the correct binary path

Recommended `.env` values:

- `IMAGE_MAGICK_BINARY=/usr/bin/magick`
- `HEIF_CONVERT_BINARY=/usr/bin/heif-convert`

The app can auto-discover converters, but on EC2 it is safer to set the binary paths explicitly.

### Ubuntu example

```bash
sudo apt update
sudo apt install -y imagemagick libheif1 libheif-examples
magick -list format | grep -E 'HEIC|HEIF|AVIF'
which magick
which heif-convert
```

### Amazon Linux example

Package names can vary by image and repo state, but the goal is the same:

```bash
sudo dnf install -y imagemagick libheif
magick -list format | grep -E 'HEIC|HEIF|AVIF'
which magick
```

If `HEIC` / `AVIF` do not appear in `magick -list format`, ImageMagick was installed without the needed decoder support and uploads will still fail.

## Recommended first production setup

For the current codebase, the cleanest AWS path is:

1. Launch a single Lightsail or EC2 instance for the web app.
2. Use RDS MySQL for the database.
3. Store the app on the instance filesystem.
4. Keep uploads on the same instance for now.

This is the best short-term fit because property images are stored on local disk. A multi-instance setup would need a follow-up move to S3 or EFS for shared uploads.

## Server layout

- App code: `/var/www/clemexique`
- Web root: `/var/www/clemexique/public`
- Uploads: `/var/www/clemexique/public/uploads`
- Upload progress files: `/var/www/clemexique/var/upload-progress`

## Provisioning steps

1. Create an RDS MySQL instance and note the host, database name, username, and password.
2. Launch an Ubuntu 24.04 Lightsail instance or an Amazon Linux / Ubuntu EC2 instance.
3. Install Apache, PHP, Composer, and required PHP extensions.
4. Point the site document root to the `public/` directory.
5. Copy the project onto the server.
6. Run `composer install --no-dev --optimize-autoloader`.
7. Create `.env` from `.env.example`.
8. Import `db/schema.sql` into the RDS database.
9. Make `public/uploads/` and `var/upload-progress/` writable by the web server user.
10. Add HTTPS with an AWS Load Balancer certificate or with Let's Encrypt on the instance.

## Required environment variables

Set these in `.env` on the server:

- `APP_BASE_PATH=`
- `APP_AUTO_CREATE_DATABASE=false`
- `DB_HOST=your-rds-endpoint`
- `DB_PORT=3306`
- `DB_NAME=clemexique`
- `DB_USER=...`
- `DB_PASSWORD=...`
- `DB_CHARSET=utf8mb4`
- `MAIL_HOST=...`
- `MAIL_PORT=587`
- `MAIL_USERNAME=...`
- `MAIL_PASSWORD=...`
- `MAIL_FROM_EMAIL=...`
- `MAIL_FROM_NAME=CLeMexique`
- `MAIL_TO_EMAIL=...`
- `ADMIN_PIN=change-this`
- `ADMIN_2FA_EMAIL=...`
- `ADMIN_2FA_BACKUP_EMAIL=...`
- `IMAGE_MAGICK_BINARY=/usr/bin/magick`
- `HEIF_CONVERT_BINARY=/usr/bin/heif-convert`

## Apache virtual host example

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/clemexique/public

    <Directory /var/www/clemexique/public>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/clemexique-error.log
    CustomLog ${APACHE_LOG_DIR}/clemexique-access.log combined
</VirtualHost>
```

## File permissions

```bash
sudo mkdir -p /var/www/clemexique/public/uploads /var/www/clemexique/var/upload-progress
sudo chown -R www-data:www-data /var/www/clemexique/public/uploads /var/www/clemexique/var/upload-progress
sudo chmod -R 775 /var/www/clemexique/public/uploads /var/www/clemexique/var/upload-progress
```

## Important production notes

- Do not commit a real `.env` file.
- Change `ADMIN_PIN` before launch.
- The app currently keeps uploaded property media on local disk. If you want auto-scaling or zero-downtime instance replacement, move uploads to S3 first.
- If you host the app at the domain root, leave `APP_BASE_PATH` empty.
- If you host it under a subdirectory such as `/clemexique`, set `APP_BASE_PATH=/clemexique`.
