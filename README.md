# clemexique

Slim 4 PHP application for the CLeMexique site.

## Local setup

1. Install PHP 8.1+ and Composer.
2. Copy [.env.example](E:/eCommerce/Wampoon/htdocs/clemexique/.env.example) to `.env` and fill in your values.
3. Run `composer install`.
4. Point your web server document root to [public](E:/eCommerce/Wampoon/htdocs/clemexique/public).
5. Import [db/schema.sql](E:/eCommerce/Wampoon/htdocs/clemexique/db/schema.sql) or let the app create the database when `APP_AUTO_CREATE_DATABASE=true`.

## AWS

Deployment notes are in [docs/aws-deployment.md](E:/eCommerce/Wampoon/htdocs/clemexique/docs/aws-deployment.md).

Use this to save chages after pushing to the `main` branch:

cd /var/www/clemexique
git pull
sudo systemctl restart apache2
