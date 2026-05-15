<?php

use App\Controller\PropertyController;
use App\Helper\Translator;
use App\Model\PropertyModel;
use App\Model\UserModel;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helper/auth.php';

if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/../.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

session_start();

$lang = $_GET['lang'] ?? $_SESSION['lang'] ?? 'fr';
$lang = in_array($lang, ['fr', 'en'], true) ? $lang : 'fr';
$_SESSION['lang'] = $lang;

$app = AppFactory::create();

$basePath = '/clemexique/public';
$app->setBasePath($basePath);

$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => false,
]);

$translator = new Translator($lang);
$mailTranslator = new Translator('fr');

$twig->getEnvironment()->addGlobal('app_lang', $lang);
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$twig->getEnvironment()->addGlobal('is_admin_authenticated', isAdminAuthenticated());
$twig->getEnvironment()->addGlobal('admin_username', getAdminDisplayName());
$twig->getEnvironment()->addGlobal('is_user_authenticated', isUserAuthenticated());
$twig->getEnvironment()->addGlobal('user_name', getUserName());
$twig->getEnvironment()->addFunction(
    new \Twig\TwigFunction('trans', function (string $key) use ($translator) {
        return $translator->trans($key);
    })
);

$app->add(TwigMiddleware::create($app, $twig));

$pdo = new PDO(
    'mysql:host=localhost;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdo->exec("CREATE DATABASE IF NOT EXISTS clemexique CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE clemexique");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS property_cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_url TEXT NOT NULL,
        name VARCHAR(255) NOT NULL,
        city VARCHAR(255) NOT NULL,
        description TEXT NULL,
        listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat',
        price_amount DECIMAL(12,2) NOT NULL,
        price_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        external_url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS property_card_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        property_card_id INT NOT NULL,
        image_url TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_property_card_images_property_card_id (property_card_id),
        CONSTRAINT fk_property_card_images_property_card
            FOREIGN KEY (property_card_id) REFERENCES property_cards(id)
            ON DELETE CASCADE
    )
");
$pdo->exec("
    ALTER TABLE property_cards
    ADD COLUMN IF NOT EXISTS listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat'
    AFTER city
");
$pdo->exec("
    ALTER TABLE property_cards
    ADD COLUMN IF NOT EXISTS description TEXT NULL
    AFTER city
");

$propertyModel = new PropertyModel($pdo);
$userModel = new UserModel($pdo);
$propertyController = new PropertyController($propertyModel, $twig, $basePath, $translator);
$adminAuthMiddleware = requireAdminAuth($basePath);
$userAuthMiddleware = requireUserAuth($basePath);

$renderInquiryForm = function ($response, array $params = []) use ($twig, $translator) {
    $defaults = [
        'page_title' => $translator->trans('info_request.page_title'),
        'hide_site_header' => true,
        'body_class' => 'inquiry-body',
        'success' => false,
        'error' => null,
        'form_data' => [
            'intent' => '',
            'name' => '',
            'email' => '',
            'phone' => '',
            'country' => '',
            'city' => '',
            'other_city' => '',
            'bedrooms' => '',
            'bathrooms' => '',
            'pool' => '',
            'beach_minutes' => '',
            'parking' => '',
            'parking_count' => '',
            'elevator' => '',
            'animals' => '',
            'project_name' => '',
            'budget_amount' => '',
            'budget_currency' => '',
            'comments' => '',
        ],
    ];

    return $twig->render($response, 'info-request.html.twig', array_replace_recursive($defaults, $params));
};

$buildLocalizedPath = function (string $path, string $language) use ($basePath): string {
    $language = in_array($language, ['fr', 'en'], true) ? $language : 'fr';

    return $basePath . $path . '?lang=' . $language;
};

$consumeInquiryFlash = function (): array {
    $flash = $_SESSION['inquiry_flash'] ?? [];
    unset($_SESSION['inquiry_flash']);

    return is_array($flash) ? $flash : [];
};

$renderAutoRentalForm = function ($response, array $params = []) use ($twig, $translator) {
    $defaults = [
        'page_title' => $translator->trans('rental_form.page_title'),
        'success' => false,
        'error' => null,
        'form_data' => [
            'full_name' => '',
            'car_type' => '',
            'car_type_other' => '',
            'rental_from' => '',
            'rental_until' => '',
            'pickup_city' => '',
            'pickup_city_other' => '',
            'return_city' => '',
            'return_city_other' => '',
            'email' => '',
            'phone' => '',
            'preferred_contact' => '',
        ],
    ];

    return $twig->render($response, 'auto-rental-form.html.twig', array_replace_recursive($defaults, $params));
};

$consumeAutoRentalFlash = function (): array {
    $flash = $_SESSION['auto_rental_flash'] ?? [];
    unset($_SESSION['auto_rental_flash']);

    return is_array($flash) ? $flash : [];
};

$renderManagementForm = function ($response, array $params = []) use ($twig, $translator) {
    $defaults = [
        'page_title' => $translator->trans('management_form.page_title'),
        'success' => false,
        'error' => null,
        'form_data' => [
            'account_email' => '',
            'full_name' => '',
            'property_city' => '',
            'property_city_other' => '',
            'possession_date' => '',
            'phone' => '',
            'preferred_contact' => '',
        ],
    ];

    return $twig->render($response, 'property-management-form.html.twig', array_replace_recursive($defaults, $params));
};

$consumeManagementFlash = function (): array {
    $flash = $_SESSION['management_form_flash'] ?? [];
    unset($_SESSION['management_form_flash']);

    return is_array($flash) ? $flash : [];
};

$configureMailer = function () {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['MAIL_USERNAME'] ?? 'audreane20@gmail.com';
    $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->CharSet = 'UTF-8';

    return $mail;
};

$formatPreferredLanguageForMail = function (string $language): string {
    return match (strtolower($language)) {
        'en' => 'Anglais',
        default => 'Français',
    };
};

$app->get('/', function ($request, $response) use ($twig, $translator, $propertyModel) {
    $queryParams = $request->getQueryParams();
    $mode = strtolower((string) ($queryParams['mode'] ?? 'all'));

    if (!in_array($mode, ['all', 'achat', 'location'], true)) {
        $mode = 'all';
    }

    return $twig->render($response, 'home.html.twig', [
        'page_title' => $translator->trans('properties.title'),
        'properties' => $propertyModel->findAllByMode($mode),
        'selected_mode' => $mode,
    ]);
});

$app->get('/about', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'about.html.twig', [
        'page_title' => $translator->trans('nav.about'),
    ]);
});

$app->get('/contact', function ($request, $response) use ($twig, $translator) {
    $queryParams = $request->getQueryParams();

    return $twig->render($response, 'contact.html.twig', [
        'page_title' => $translator->trans('nav.contact'),
        'success' => false,
        'error' => null,
        'name' => '',
        'email' => '',
        'phone' => '',
        'project' => trim((string) ($queryParams['project'] ?? '')),
        'message' => '',
    ]);
});

$app->get('/residence-immigration', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'residency.html.twig', [
        'page_title' => $translator->trans('residency.page_title'),
    ]);
});

$app->get('/location-automobiles', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'auto-rental.html.twig', [
        'page_title' => $translator->trans('auto_rental.page_title'),
    ]);
});

$app->get('/gestion-immobiliere', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'property-management.html.twig', [
        'page_title' => $translator->trans('property_management.page_title'),
    ]);
});

$app->get('/restaurants-a-essayer', function ($request, $response) use ($twig, $translator, $lang) {
    $restaurantCategories = $lang === 'en'
        ? [
            [
                'title' => 'Italian',
                'flag' => 'IT',
                'items' => [
                    ['name' => 'Don Mario Ristorante', 'area' => '10th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://don-mario.restaurants-world.net/#google_vignette', 'url_label' => 'don-mario.restaurants-world.net'],
                    ['name' => 'Romeo Trattoria', 'area' => '4th Street', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://romeotrattoriapizzeria.shop/', 'url_label' => 'romeotrattoriapizzeria.shop'],
                    ['name' => 'Osteria de Roma', 'area' => '6th Street', 'price' => '$$', 'reference' => null, 'url' => 'https://osteriaderoma.shop/', 'url_label' => 'osteriaderoma.shop'],
                    ['name' => 'Trattoria Del Centro', 'area' => '26th Avenue', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://trattoriaplaya.wixsite.com/trattoria', 'url_label' => 'trattoriaplaya.wixsite.com/trattoria'],
                    ['name' => 'La Famiglia', 'area' => '10th Avenue', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://lafamiglia.mx/', 'url_label' => 'lafamiglia.mx'],
                    ['name' => 'Roma Spaghetti', 'area' => 'CTM Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/romaspaghettimx', 'url_label' => 'facebook.com/romaspaghettimx'],
                    ['name' => 'Piola Ristorante', 'area' => '38th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://piola.com.mx', 'url_label' => 'piola.com.mx'],
                    ['name' => 'Papaya Slice', 'area' => '38th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://www.tripadvisor.ca/Restaurant_Review-g150812-d26812600-Reviews-Papaya_Slice-Playa_del_Carmen_Yucatan_Peninsula.html', 'url_label' => 'tripadvisor.ca/Papaya_Slice'],
                ],
            ],
            [
                'title' => 'Bistro / bakery',
                'flag' => 'FR',
                'items' => [
                    ['name' => 'Francesca Italian Bakery', 'area' => '10th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://francescatheitalianbakery.shop/', 'url_label' => 'francescatheitalianbakery.shop'],
                    ['name' => 'Choux Choux Cafe', 'area' => '20th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/chouxchouxcafe', 'url_label' => 'facebook.com/chouxchouxcafe'],
                    ['name' => 'Chez Celine', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://chezceline.com.mx', 'url_label' => 'chezceline.com.mx'],
                    ['name' => 'Somos Crisol', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://somoscrisol.com.mx', 'url_label' => 'somoscrisol.com.mx'],
                    ['name' => 'Labrioche de Playa', 'area' => 'Playacar', 'price' => '$$', 'reference' => null, 'url' => 'https://linktr.ee/labriochedeplaya?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQMMjU2MjgxMDQwNTU4AAGnVkjT1p_2CkBvgvHROJypUkVgamDr5rUph4mACDdvLK4bvdLl_QRGKJnSEeI_aem_j-Je-1Yro34mr5LdVTvNYA', 'url_label' => 'linktr.ee/labriochedeplaya'],
                    ['name' => 'Cafe Kaawa', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://cafekaawa.mx', 'url_label' => 'cafekaawa.mx'],
                ],
            ],
            [
                'title' => 'Indian',
                'flag' => 'IN',
                'items' => [
                    ['name' => 'India Jones', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://india-jones.com', 'url_label' => 'india-jones.com'],
                ],
            ],
            [
                'title' => 'Thai',
                'flag' => 'TH',
                'items' => [
                    ['name' => 'Po Thai', 'area' => '10th Avenue', 'price' => '$$', 'reference' => 'Favorite pick', 'url' => 'https://pothai.com.mx', 'url_label' => 'pothai.com.mx'],
                    ['name' => 'Mae Thai', 'area' => '38th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://mae-thai-restaurante.goto-where.com/', 'url_label' => 'mae-thai-restaurante.goto-where.com'],
                    ['name' => 'Yum Yum', 'area' => '10th Avenue', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://grupogeorge.com', 'url_label' => 'grupogeorge.com'],
                    ['name' => 'Kobma', 'area' => '1st Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://kobma.mx/nosotros/', 'url_label' => 'kobma.mx/nosotros/'],
                ],
            ],
            [
                'title' => 'Quebec-owned favorites',
                'flag' => 'CA-QC',
                'items' => [
                    ['name' => 'Viaje de Sabores', 'area' => 'Constituyentes Avenue', 'price' => '$$', 'reference' => 'Favorite pick', 'url' => 'https://viajedesaborespdc.com', 'url_label' => 'viajedesaborespdc.com'],
                    ['name' => 'Hotel Boutique Caché', 'area' => '15th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://hotelboutiquecacheplaya.com', 'url_label' => 'hotelboutiquecacheplaya.com'],
                    ['name' => '3 Amigos Sports Bar', 'area' => '4th Street', 'price' => '$$', 'reference' => null, 'url' => 'https://les3amigos.com', 'url_label' => 'les3amigos.com'],
                    ['name' => 'Los Tabarnacos', 'area' => '10th Street', 'price' => '$$', 'reference' => null, 'url' => 'https://lostabarnacos.com', 'url_label' => 'lostabarnacos.com'],
                ],
            ],
            [
                'title' => 'Mexican',
                'flag' => 'MX',
                'items' => [
                    ['name' => 'Amate 38', 'area' => '38th Avenue', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://amate38.com', 'url_label' => 'amate38.com'],
                    ['name' => 'Zitla Ceiba', 'area' => '34th Avenue', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://linktr.ee/ZitlaCeiba?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQMMjU2MjgxMDQwNTU4AAGnkZxjEuGQ16hM8o_zMwSwIDZp_OqlkYJ5VniRkvehYppOhDssB34oWe4kc8U_aem_dQBgFYiH_VkLRfwdMSy2lg', 'url_label' => 'linktr.ee/ZitlaCeiba'],
                    ['name' => 'Lido Cocina de Playa', 'area' => '1st Avenue', 'price' => '$$$', 'reference' => null, 'url' => 'https://lidococinadeplaya.com', 'url_label' => 'lidococinadeplaya.com'],
                    ['name' => 'Don Sirloin', 'area' => 'Constituyentes Avenue', 'price' => '$', 'reference' => null, 'url' => 'https://www.tripadvisor.ca/Restaurant_Review-g150812-d2241519-Reviews-Don_Sirloin-Playa_del_Carmen_Yucatan_Peninsula.html', 'url_label' => 'tripadvisor.ca/Don_Sirloin'],
                    ['name' => 'El Fogon', 'area' => 'Constituyentes Avenue', 'price' => '$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://el-fogon-77720-playa-del-carmen.goto-where.com/', 'url_label' => 'el-fogon-77720-playa-del-carmen.goto-where.com'],
                    ['name' => 'Chilteplin Marisquillos', 'area' => '34th Street', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://www.chiltepinmarisquillos.com/', 'url_label' => 'chiltepinmarisquillos.com'],
                    ['name' => 'Primo', 'area' => 'Constituyentes Avenue', 'price' => '$$$$', 'reference' => null, 'url' => 'https://primoplaya.com', 'url_label' => 'primoplaya.com'],
                ],
            ],
            [
                'title' => 'Music / nightlife',
                'flag' => 'MUSIC',
                'items' => [
                    ['name' => 'La Vagabunda', 'area' => '38th Avenue and 5th Street', 'price' => '$$', 'reference' => null, 'url' => 'https://vagabundaplaya.com', 'url_label' => 'vagabundaplaya.com'],
                    ['name' => 'Bar FAH', 'area' => '5th Avenue', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://fahrestaurant.com', 'url_label' => 'fahrestaurant.com'],
                    ['name' => 'Zenzi Beach Bar', 'area' => '10th Street', 'price' => '$$', 'reference' => null, 'url' => 'https://zenzi-playa.com', 'url_label' => 'zenzi-playa.com'],
                    ['name' => 'Caiman Tugurio', 'area' => '24th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://caimantugurio.com/menu/', 'url_label' => 'caimantugurio.com/menu'],
                    ['name' => 'Bloody Mary', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://linktr.ee/bloodymaryplaya', 'url_label' => 'linktr.ee/bloodymaryplaya'],
                ],
            ],
            [
                'title' => 'Seafood',
                'flag' => 'SEA',
                'items' => [
                    ['name' => 'Mariskinky', 'area' => '38th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/mariskinky', 'url_label' => 'facebook.com/mariskinky'],
                    ['name' => 'Las Hijas De La Tostada', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://www.lashijasdelatostada.com/', 'url_label' => 'lashijasdelatostada.com'],
                    ['name' => 'Kascabal', 'area' => '5th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://kascabalrestaurante.com', 'url_label' => 'kascabalrestaurante.com'],
                ],
            ],
            [
                'title' => 'Rotisserie',
                'flag' => 'GRILL',
                'items' => [
                    ['name' => 'La Brocherie', 'area' => '15th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://sites.google.com/view/la-brocherie', 'url_label' => 'sites.google.com/view/la-brocherie'],
                    ['name' => 'El Pechugon', 'area' => '30th Avenue', 'price' => '$', 'reference' => null, 'url' => 'https://el-pechugon.menu-world.com/', 'url_label' => 'el-pechugon.menu-world.com'],
                ],
            ],
            [
                'title' => 'Jamaican',
                'flag' => 'JM',
                'items' => [
                    ['name' => 'Ferrons', 'area' => '10th Avenue', 'price' => '$', 'reference' => null, 'url' => 'https://ferronsjerkchicken.com', 'url_label' => 'ferronsjerkchicken.com'],
                    ['name' => 'Rockas', 'area' => '38th Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://rockas-jamaican-kitchen.goto-where.com/', 'url_label' => 'rockas-jamaican-kitchen.goto-where.com'],
                ],
            ],
            [
                'title' => 'Fine dining',
                'flag' => 'LUXE',
                'items' => [
                    ['name' => 'Nicoletta', 'area' => '5th Avenue', 'price' => '$$$$', 'reference' => null, 'url' => 'https://nicolettarestaurant.com/playa-del-carmen', 'url_label' => 'nicolettarestaurant.com/playa-del-carmen'],
                    ['name' => 'Harrys Steak House', 'area' => '5th Avenue', 'price' => '$$$$', 'reference' => null, 'url' => 'https://harrys.com.mx/restaurante-playa-del-carmen', 'url_label' => 'harrys.com.mx/restaurante-playa-del-carmen'],
                    ['name' => 'Ilios Greek Estiatorio', 'area' => '5th Avenue', 'price' => '$$$', 'reference' => 'With show', 'url' => 'https://iliosrestaurante.com.mx', 'url_label' => 'iliosrestaurante.com.mx'],
                    ['name' => 'XAAK', 'area' => 'Xcaret complex', 'price' => '$$$$', 'reference' => 'Tripadvisor', 'url' => 'https://hotelxcaret.com/es/xaak/', 'url_label' => 'hotelxcaret.com/es/xaak/'],
                    ['name' => 'Oh La La !', 'area' => '10th Avenue', 'price' => '$$$$', 'reference' => null, 'url' => 'https://grupobygeorge.com', 'url_label' => 'grupobygeorge.com'],
                    ['name' => 'Ha!', 'area' => 'Xcaret complex', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://hotelxcaretmexico.com/es/restaurante-ha/', 'url_label' => 'hotelxcaretmexico.com/es/restaurante-ha/'],
                    ['name' => 'Cocina de Autor', 'area' => 'Gran Velas Hotel', 'price' => '$$$$', 'reference' => null, 'url' => 'https://rivieramaya.granvelas.com/dining/cocina-de-autor', 'url_label' => 'rivieramaya.granvelas.com/dining/cocina-de-autor'],
                    ['name' => 'Alux', 'area' => 'Benito Juarez Avenue', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://aluxrestaurant.com', 'url_label' => 'aluxrestaurant.com'],
                ],
            ],
            [
                'title' => 'Grill',
                'flag' => 'STEAK',
                'items' => [
                    ['name' => '500 Gramos', 'area' => '5th Avenue', 'price' => '$$$', 'reference' => 'Favorite pick • Tripadvisor 2025', 'url' => 'https://www.500gramos.com/', 'url_label' => '500gramos.com'],
                    ['name' => 'Porfirios', 'area' => '5th Avenue', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://porfirios.com.mx', 'url_label' => 'porfirios.com.mx'],
                ],
            ],
        ]
        : [
            [
                'title' => 'Italien',
                'flag' => 'IT',
                'items' => [
                    ['name' => 'Don Mario Ristorante', 'area' => '10e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://don-mario.restaurants-world.net/#google_vignette', 'url_label' => 'don-mario.restaurants-world.net'],
                    ['name' => 'Romeo Trattoria', 'area' => 'Rue 4', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://romeotrattoriapizzeria.shop/', 'url_label' => 'romeotrattoriapizzeria.shop'],
                    ['name' => 'Osteria de Roma', 'area' => 'Rue 6', 'price' => '$$', 'reference' => null, 'url' => 'https://osteriaderoma.shop/', 'url_label' => 'osteriaderoma.shop'],
                    ['name' => 'Trattoria Del Centro', 'area' => '26e Avenue', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://trattoriaplaya.wixsite.com/trattoria', 'url_label' => 'trattoriaplaya.wixsite.com/trattoria'],
                    ['name' => 'La Famiglia', 'area' => '10e Avenue', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://lafamiglia.mx/', 'url_label' => 'lafamiglia.mx'],
                    ['name' => 'Roma Spaghetti', 'area' => 'Avenue CTM', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/romaspaghettimx', 'url_label' => 'facebook.com/romaspaghettimx'],
                    ['name' => 'Piola Ristorante', 'area' => '38e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://piola.com.mx', 'url_label' => 'piola.com.mx'],
                    ['name' => 'Papaya Slice', 'area' => '38e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://www.tripadvisor.ca/Restaurant_Review-g150812-d26812600-Reviews-Papaya_Slice-Playa_del_Carmen_Yucatan_Peninsula.html', 'url_label' => 'tripadvisor.ca/Papaya_Slice'],
                ],
            ],
            [
                'title' => 'Resto bistro / boulangerie',
                'flag' => 'FR',
                'items' => [
                    ['name' => 'Francesca Italian Bakery', 'area' => '10e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://francescatheitalianbakery.shop/', 'url_label' => 'francescatheitalianbakery.shop'],
                    ['name' => 'Choux Choux Cafe', 'area' => '20e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/chouxchouxcafe', 'url_label' => 'facebook.com/chouxchouxcafe'],
                    ['name' => 'Chez Celine', 'area' => '5e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://chezceline.com.mx', 'url_label' => 'chezceline.com.mx'],
                    ['name' => 'Somos Crisol', 'area' => '5e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://somoscrisol.com.mx', 'url_label' => 'somoscrisol.com.mx'],
                    ['name' => 'Labrioche de Playa', 'area' => 'Playacar', 'price' => '$$', 'reference' => null, 'url' => 'https://linktr.ee/labriochedeplaya?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQMMjU2MjgxMDQwNTU4AAGnVkjT1p_2CkBvgvHROJypUkVgamDr5rUph4mACDdvLK4bvdLl_QRGKJnSEeI_aem_j-Je-1Yro34mr5LdVTvNYA', 'url_label' => 'linktr.ee/labriochedeplaya'],
                    ['name' => 'Cafe Kaawa', 'area' => '5e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://cafekaawa.mx', 'url_label' => 'cafekaawa.mx'],
                ],
            ],
            [
                'title' => 'Resto indien',
                'flag' => 'IN',
                'items' => [
                    ['name' => 'India Jones', 'area' => '5e Avenue', 'price' => '$$', 'reference' => null, 'url' => 'https://india-jones.com', 'url_label' => 'india-jones.com'],
                ],
            ],
            [
                'title' => 'Resto thaï',
                'flag' => 'TH',
                'items' => [
                    ['name' => 'Po Thai', 'area' => 'Avenue 10', 'price' => '$$', 'reference' => 'Coup de coeur', 'url' => 'https://pothai.com.mx', 'url_label' => 'pothai.com.mx'],
                    ['name' => 'Mae Thai', 'area' => 'Avenue 38', 'price' => '$$', 'reference' => null, 'url' => 'https://mae-thai-restaurante.goto-where.com/', 'url_label' => 'mae-thai-restaurante.goto-where.com'],
                    ['name' => 'Yum Yum', 'area' => 'Avenue 10', 'price' => '$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://grupogeorge.com', 'url_label' => 'grupogeorge.com'],
                    ['name' => 'Kobma', 'area' => 'Avenue 1', 'price' => '$$', 'reference' => null, 'url' => 'https://kobma.mx/nosotros/', 'url_label' => 'kobma.mx/nosotros/'],
                ],
            ],
            [
                'title' => 'Resto proprio québécois',
                'flag' => 'CA-QC',
                'items' => [
                    ['name' => 'Viaje de Sabores', 'area' => 'Avenue Constituyentes', 'price' => '$$', 'reference' => 'Coup de coeur', 'url' => 'https://viajedesaborespdc.com', 'url_label' => 'viajedesaborespdc.com'],
                    ['name' => 'Hotel Boutique Caché', 'area' => 'Avenue 15', 'price' => '$$', 'reference' => null, 'url' => 'https://hotelboutiquecacheplaya.com', 'url_label' => 'hotelboutiquecacheplaya.com'],
                    ['name' => '3 Amigos Sports Bar', 'area' => 'Rue 4', 'price' => '$$', 'reference' => null, 'url' => 'https://les3amigos.com', 'url_label' => 'les3amigos.com'],
                    ['name' => 'Los Tabarnacos', 'area' => 'Rue 10', 'price' => '$$', 'reference' => null, 'url' => 'https://lostabarnacos.com', 'url_label' => 'lostabarnacos.com'],
                ],
            ],
            [
                'title' => 'Resto mexicain',
                'flag' => 'MX',
                'items' => [
                    ['name' => 'Amate 38', 'area' => '38e Avenue', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://amate38.com', 'url_label' => 'amate38.com'],
                    ['name' => 'Zitla Ceiba', 'area' => 'Avenue 34', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://linktr.ee/ZitlaCeiba?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAZXh0bgNhZW0CMTEAc3J0YwZhcHBfaWQMMjU2MjgxMDQwNTU4AAGnkZxjEuGQ16hM8o_zMwSwIDZp_OqlkYJ5VniRkvehYppOhDssB34oWe4kc8U_aem_dQBgFYiH_VkLRfwdMSy2lg', 'url_label' => 'linktr.ee/ZitlaCeiba'],
                    ['name' => 'Lido Cocina de Playa', 'area' => 'Avenue 1', 'price' => '$$$', 'reference' => null, 'url' => 'https://lidococinadeplaya.com', 'url_label' => 'lidococinadeplaya.com'],
                    ['name' => 'Don Sirloin', 'area' => 'Avenue Constituyentes', 'price' => '$', 'reference' => null, 'url' => 'https://www.tripadvisor.ca/Restaurant_Review-g150812-d2241519-Reviews-Don_Sirloin-Playa_del_Carmen_Yucatan_Peninsula.html', 'url_label' => 'tripadvisor.ca/Don_Sirloin'],
                    ['name' => 'El Fogon', 'area' => 'Avenue Constituyentes', 'price' => '$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://el-fogon-77720-playa-del-carmen.goto-where.com/', 'url_label' => 'el-fogon-77720-playa-del-carmen.goto-where.com'],
                    ['name' => 'Chilteplin Marisquillos', 'area' => 'Rue 34', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://www.chiltepinmarisquillos.com/', 'url_label' => 'chiltepinmarisquillos.com'],
                    ['name' => 'Primo', 'area' => 'Avenue Constituyentes', 'price' => '$$$$', 'reference' => null, 'url' => 'https://primoplaya.com', 'url_label' => 'primoplaya.com'],
                ],
            ],
            [
                'title' => 'Resto musical',
                'flag' => 'MUSIC',
                'items' => [
                    ['name' => 'La Vagabunda', 'area' => '38e Avenue et Rue 5', 'price' => '$$', 'reference' => null, 'url' => 'https://vagabundaplaya.com', 'url_label' => 'vagabundaplaya.com'],
                    ['name' => 'Bar FAH', 'area' => 'Avenue 5', 'price' => '$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://fahrestaurant.com', 'url_label' => 'fahrestaurant.com'],
                    ['name' => 'Zenzi Beach Bar', 'area' => 'Rue 10', 'price' => '$$', 'reference' => null, 'url' => 'https://zenzi-playa.com', 'url_label' => 'zenzi-playa.com'],
                    ['name' => 'Caiman Tugurio', 'area' => 'Avenue 24', 'price' => '$$', 'reference' => null, 'url' => 'https://caimantugurio.com/menu/', 'url_label' => 'caimantugurio.com/menu'],
                    ['name' => 'Bloody Mary', 'area' => 'Avenue 5', 'price' => '$$', 'reference' => null, 'url' => 'https://linktr.ee/bloodymaryplaya', 'url_label' => 'linktr.ee/bloodymaryplaya'],
                ],
            ],
            [
                'title' => 'Fruits de mer',
                'flag' => 'SEA',
                'items' => [
                    ['name' => 'Mariskinky', 'area' => 'Avenue 38', 'price' => '$$', 'reference' => null, 'url' => 'https://facebook.com/mariskinky', 'url_label' => 'facebook.com/mariskinky'],
                    ['name' => 'Las Hijas De La Tostada', 'area' => 'Avenue 5', 'price' => '$$', 'reference' => null, 'url' => 'https://www.lashijasdelatostada.com/', 'url_label' => 'lashijasdelatostada.com'],
                    ['name' => 'Kascabal', 'area' => 'Avenue 5', 'price' => '$$', 'reference' => null, 'url' => 'https://kascabalrestaurante.com', 'url_label' => 'kascabalrestaurante.com'],
                ],
            ],
            [
                'title' => 'Rôtisserie',
                'flag' => 'GRILL',
                'items' => [
                    ['name' => 'La Brocherie', 'area' => 'Avenue 15', 'price' => '$$', 'reference' => null, 'url' => 'https://sites.google.com/view/la-brocherie', 'url_label' => 'sites.google.com/view/la-brocherie'],
                    ['name' => 'El Pechugon', 'area' => 'Avenue 30', 'price' => '$', 'reference' => null, 'url' => 'https://el-pechugon.menu-world.com/', 'url_label' => 'el-pechugon.menu-world.com'],
                ],
            ],
            [
                'title' => 'Resto jamaïcain',
                'flag' => 'JM',
                'items' => [
                    ['name' => 'Ferrons', 'area' => 'Avenue 10', 'price' => '$', 'reference' => null, 'url' => 'https://ferronsjerkchicken.com', 'url_label' => 'ferronsjerkchicken.com'],
                    ['name' => 'Rockas', 'area' => 'Avenue 38', 'price' => '$$', 'reference' => null, 'url' => 'https://rockas-jamaican-kitchen.goto-where.com/', 'url_label' => 'rockas-jamaican-kitchen.goto-where.com'],
                ],
            ],
            [
                'title' => 'Resto haut de gamme',
                'flag' => 'LUXE',
                'items' => [
                    ['name' => 'Nicoletta', 'area' => 'Avenue 5', 'price' => '$$$$', 'reference' => null, 'url' => 'https://nicolettarestaurant.com/playa-del-carmen', 'url_label' => 'nicolettarestaurant.com/playa-del-carmen'],
                    ['name' => 'Harrys Steak House', 'area' => 'Avenue 5', 'price' => '$$$$', 'reference' => null, 'url' => 'https://harrys.com.mx/restaurante-playa-del-carmen', 'url_label' => 'harrys.com.mx/restaurante-playa-del-carmen'],
                    ['name' => 'Ilios Greek Estiatorio', 'area' => 'Avenue 5', 'price' => '$$$', 'reference' => 'Avec spectacle', 'url' => 'https://iliosrestaurante.com.mx', 'url_label' => 'iliosrestaurante.com.mx'],
                    ['name' => 'XAAK', 'area' => 'Complexe Xcaret', 'price' => '$$$$', 'reference' => 'Tripadvisor', 'url' => 'https://hotelxcaret.com/es/xaak/', 'url_label' => 'hotelxcaret.com/es/xaak/'],
                    ['name' => 'Oh La La !', 'area' => 'Avenue 10', 'price' => '$$$$', 'reference' => null, 'url' => 'https://grupobygeorge.com', 'url_label' => 'grupobygeorge.com'],
                    ['name' => 'Ha!', 'area' => 'Complexe Xcaret', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://hotelxcaretmexico.com/es/restaurante-ha/', 'url_label' => 'hotelxcaretmexico.com/es/restaurante-ha/'],
                    ['name' => 'Cocina de Autor', 'area' => 'Hôtel Gran Velas', 'price' => '$$$$', 'reference' => null, 'url' => 'https://rivieramaya.granvelas.com/dining/cocina-de-autor', 'url_label' => 'rivieramaya.granvelas.com/dining/cocina-de-autor'],
                    ['name' => 'Alux', 'area' => 'Avenue Benito Juarez', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://aluxrestaurant.com', 'url_label' => 'aluxrestaurant.com'],
                ],
            ],
            [
                'title' => 'Grillade',
                'flag' => 'STEAK',
                'items' => [
                    ['name' => '500 Gramos', 'area' => 'Avenue 5', 'price' => '$$$', 'reference' => 'Coup de coeur • Tripadvisor 2025', 'url' => 'https://www.500gramos.com/', 'url_label' => '500gramos.com'],
                    ['name' => 'Porfirios', 'area' => 'Avenue 5', 'price' => '$$$$', 'reference' => 'Tripadvisor 2025', 'url' => 'https://porfirios.com.mx', 'url_label' => 'porfirios.com.mx'],
                ],
            ],
        ];

    return $twig->render($response, 'restaurants.html.twig', [
        'page_title' => $translator->trans('restaurants.page_title'),
        'restaurant_categories' => $restaurantCategories,
    ]);
});

$app->get('/gestion-immobiliere/formulaire', function ($request, $response) use ($renderManagementForm, $consumeManagementFlash) {
    $flash = $consumeManagementFlash();

    return $renderManagementForm($response, [
        'success' => (bool) ($flash['success'] ?? false),
        'error' => $flash['error'] ?? null,
        'form_data' => is_array($flash['form_data'] ?? null) ? $flash['form_data'] : [],
    ]);
});

$app->get('/location-automobiles/formulaire', function ($request, $response) use ($renderAutoRentalForm, $consumeAutoRentalFlash) {
    $flash = $consumeAutoRentalFlash();

    return $renderAutoRentalForm($response, [
        'success' => (bool) ($flash['success'] ?? false),
        'error' => $flash['error'] ?? null,
        'form_data' => is_array($flash['form_data'] ?? null) ? $flash['form_data'] : [],
    ]);
});

$app->get('/demande-information', function ($request, $response) use ($renderInquiryForm, $consumeInquiryFlash) {
    $flash = $consumeInquiryFlash();

    return $renderInquiryForm($response, [
        'success' => (bool) ($flash['success'] ?? false),
        'error' => $flash['error'] ?? null,
        'form_data' => is_array($flash['form_data'] ?? null) ? $flash['form_data'] : [],
    ]);
});

$app->get('/properties', function ($request, $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    $target = $basePath . '/';

    if ($query !== '') {
        $target .= '?' . $query;
    }

    return redirectTo($response, $target);
});

$app->get('/properties/{id}', [$propertyController, 'show']);

$app->post('/contact', function ($request, $response) use ($twig, $translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail) {
    $data = $request->getParsedBody();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $project = trim($data['project'] ?? '');
    $message = trim($data['message'] ?? '');
    $preferredLanguage = trim((string) ($data['preferred_language'] ?? 'fr'));

    if ($name === '' || $email === '' || $message === '') {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => $translator->trans('contact.error_required'),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'project' => $project,
            'message' => $message,
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => $translator->trans('contact.error_invalid_email'),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'project' => $project,
            'message' => $message,
        ]);
    }

    try {
        $mail = $configureMailer();

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'audreane20@gmail.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? 'audreane20@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($email, $name);

        $mail->Subject = $mailTranslator->trans('contact.mail_subject');
        $mail->Body =
            $mailTranslator->trans('contact.name') . ": {$name}\n" .
            $mailTranslator->trans('contact.email') . ": {$email}\n" .
            $mailTranslator->trans('contact.mail_language') . ": " . $formatPreferredLanguageForMail($preferredLanguage) . "\n" .
            $mailTranslator->trans('contact.phone') . ": {$phone}\n" .
            $mailTranslator->trans('contact.project') . ": {$project}\n\n" .
            $mailTranslator->trans('contact.message') . ":\n{$message}";

        $mail->send();

        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => true,
            'error' => null,
            'name' => '',
            'email' => '',
            'phone' => '',
            'project' => '',
            'message' => '',
        ]);
    } catch (Exception $e) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => $translator->trans('contact.error_send'),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'project' => $project,
            'message' => $message,
        ]);
    }
});

$app->post('/demande-information', function ($request, $response) use ($translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail, $buildLocalizedPath) {
    $data = $request->getParsedBody();

    $formData = [
        'preferred_language' => trim((string) ($data['preferred_language'] ?? 'fr')),
        'intent' => trim((string) ($data['intent'] ?? '')),
        'name' => trim((string) ($data['name'] ?? '')),
        'email' => trim((string) ($data['email'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
        'country' => trim((string) ($data['country'] ?? '')),
        'city' => trim((string) ($data['city'] ?? '')),
        'other_city' => trim((string) ($data['other_city'] ?? '')),
        'bedrooms' => trim((string) ($data['bedrooms'] ?? '')),
        'bathrooms' => trim((string) ($data['bathrooms'] ?? '')),
        'pool' => trim((string) ($data['pool'] ?? '')),
        'beach_minutes' => trim((string) ($data['beach_minutes'] ?? '')),
        'parking' => trim((string) ($data['parking'] ?? '')),
        'parking_count' => trim((string) ($data['parking_count'] ?? '')),
        'elevator' => trim((string) ($data['elevator'] ?? '')),
        'animals' => trim((string) ($data['animals'] ?? '')),
        'project_name' => trim((string) ($data['project_name'] ?? '')),
        'budget_amount' => trim((string) ($data['budget_amount'] ?? '')),
        'budget_currency' => trim((string) ($data['budget_currency'] ?? '')),
        'comments' => trim((string) ($data['comments'] ?? '')),
    ];

    $redirectTarget = $buildLocalizedPath('/demande-information', $formData['preferred_language']);

    if ($formData['city'] === '' && $formData['other_city'] !== '') {
        $formData['city'] = 'other';
    }

    $requiredFields = [
        'intent',
        'name',
        'email',
        'phone',
        'country',
        'city',
        'bedrooms',
        'bathrooms',
        'pool',
        'beach_minutes',
        'parking',
        'parking_count',
        'elevator',
        'animals',
        'budget_amount',
        'budget_currency',
    ];

    foreach ($requiredFields as $field) {
        if ($formData[$field] === '') {
            $_SESSION['inquiry_flash'] = [
                'success' => false,
                'error' => $translator->trans('info_request.error_required'),
                'form_data' => $formData,
            ];

            return redirectTo($response, $redirectTarget);
        }
    }

    if ($formData['city'] === 'other' && $formData['other_city'] === '') {
        $_SESSION['inquiry_flash'] = [
            'success' => false,
            'error' => $translator->trans('info_request.error_required'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }

    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $_SESSION['inquiry_flash'] = [
            'success' => false,
            'error' => $translator->trans('info_request.error_invalid_email'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }

    $displayMaps = [
        'intent' => [
            'achat' => $mailTranslator->trans('properties.mode_buy'),
            'location' => $mailTranslator->trans('properties.mode_rent'),
        ],
        'country' => [
            'canada' => $mailTranslator->trans('info_request.country_canada'),
            'united_states' => $mailTranslator->trans('info_request.country_united_states'),
        ],
        'city' => [
            'cancun' => 'Cancun',
            'puerto-morelos' => 'Puerto Morelos',
            'playa-del-carmen' => 'Playa del Carmen',
            'puerto-aventuras' => 'Puerto Aventuras',
            'tulum' => 'Tulum',
            'other' => $formData['other_city'] !== '' ? $formData['other_city'] : $mailTranslator->trans('info_request.other_city'),
        ],
        'bedrooms' => [
            'studio' => $mailTranslator->trans('info_request.studio'),
            '1' => '1 ' . $mailTranslator->trans('info_request.bedroom_unit'),
            '2' => '2 ' . $mailTranslator->trans('info_request.bedroom_unit_plural'),
            '3' => '3 ' . $mailTranslator->trans('info_request.bedroom_unit_plural'),
            '4_plus' => $mailTranslator->trans('info_request.more_than_three'),
        ],
        'bathrooms' => [
            '1' => '1 ' . $mailTranslator->trans('info_request.bathroom_unit'),
            '2' => '2 ' . $mailTranslator->trans('info_request.bathroom_unit_plural'),
            '3' => '3 ' . $mailTranslator->trans('info_request.bathroom_unit_plural'),
            '4_plus' => $mailTranslator->trans('info_request.more_than_three_bathrooms'),
        ],
        'pool' => [
            'ground' => $mailTranslator->trans('info_request.pool_ground'),
            'rooftop' => $mailTranslator->trans('info_request.pool_rooftop'),
            'no_preference' => $mailTranslator->trans('info_request.no_preference'),
        ],
        'parking' => [
            'indoor' => $mailTranslator->trans('info_request.parking_inside'),
            'outdoor' => $mailTranslator->trans('info_request.parking_outside'),
            'both' => $mailTranslator->trans('info_request.parking_both'),
            'none' => $mailTranslator->trans('info_request.parking_none'),
        ],
        'elevator' => [
            'yes' => $mailTranslator->trans('info_request.yes'),
            'no' => $mailTranslator->trans('info_request.no'),
            'no_preference' => $mailTranslator->trans('info_request.no_preference'),
        ],
        'animals' => [
            'allowed' => $mailTranslator->trans('info_request.animals_allowed'),
            'not_allowed' => $mailTranslator->trans('info_request.animals_not_allowed'),
            'no_preference' => $mailTranslator->trans('info_request.no_preference'),
        ],
        'budget_currency' => [
            'CAD' => $mailTranslator->trans('info_request.currency_cad'),
            'USD' => $mailTranslator->trans('info_request.currency_usd'),
            'MXN' => $mailTranslator->trans('info_request.currency_mxn'),
        ],
    ];

    $displayValue = function (string $field) use ($formData, $displayMaps, $mailTranslator) {
        $value = $formData[$field] ?? '';

        if ($value === '') {
            return $mailTranslator->trans('info_request.mail_not_provided');
        }

        return $displayMaps[$field][$value] ?? $value;
    };

    $escape = function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };

    $beachDisplay = $formData['beach_minutes'] !== ''
        ? $formData['beach_minutes'] . ' min'
        : $mailTranslator->trans('info_request.mail_not_provided');

    $projectNameDisplay = $formData['project_name'] !== ''
        ? $formData['project_name']
        : $mailTranslator->trans('info_request.mail_not_provided');

    $parkingCountDisplay = $formData['parking_count'] !== ''
        ? $formData['parking_count']
        : $mailTranslator->trans('info_request.mail_not_provided');

    $commentsDisplay = $formData['comments'] !== ''
        ? nl2br($escape($formData['comments']))
        : $escape($mailTranslator->trans('info_request.mail_not_provided'));

    $budgetDisplay = $formData['budget_amount'] . ' ' . $displayValue('budget_currency');

    $mailRows = [
        $mailTranslator->trans('info_request.full_name') => $formData['name'],
        $mailTranslator->trans('info_request.email') => $formData['email'],
        $mailTranslator->trans('contact.mail_language') => $formatPreferredLanguageForMail($formData['preferred_language']),
        $mailTranslator->trans('info_request.phone') => $formData['phone'],
        $mailTranslator->trans('info_request.country') => $displayValue('country'),
        $mailTranslator->trans('info_request.intent_title') => $displayValue('intent'),
        $mailTranslator->trans('info_request.location_title') => $displayValue('city'),
        $mailTranslator->trans('info_request.bedrooms_title') => $displayValue('bedrooms'),
        $mailTranslator->trans('info_request.bathrooms_title') => $displayValue('bathrooms'),
        $mailTranslator->trans('info_request.pool_title') => $displayValue('pool'),
        $mailTranslator->trans('info_request.beach_title') => $beachDisplay,
        $mailTranslator->trans('info_request.parking_title') => $displayValue('parking'),
        $mailTranslator->trans('info_request.parking_count') => $parkingCountDisplay,
        $mailTranslator->trans('info_request.elevator_title') => $displayValue('elevator'),
        $mailTranslator->trans('info_request.animals_title') => $displayValue('animals'),
        $mailTranslator->trans('info_request.project_name') => $projectNameDisplay,
        $mailTranslator->trans('info_request.budget_title') => $budgetDisplay,
    ];

    $htmlRows = '';
    $textRows = '';

    foreach ($mailRows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;background:#f6f9ff;font-weight:700;color:#17338f;width:34%;">' . $escape($label) . '</td>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;color:#16304d;">' . $escape((string) $value) . '</td>'
            . '</tr>';
        $textRows .= $label . ': ' . $value . "\n";
    }

    $htmlBody = ''
        . '<div style="font-family:Arial,sans-serif;color:#16304d;line-height:1.5;">'
        . '<h2 style="margin:0 0 18px;color:#17338f;">' . $escape($mailTranslator->trans('info_request.mail_subject')) . '</h2>'
        . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px;">' . $htmlRows . '</table>'
        . '<h3 style="margin:0 0 8px;color:#17338f;">' . $escape($mailTranslator->trans('info_request.comments_title')) . '</h3>'
        . '<div style="padding:12px;border:1px solid #dfe7f3;border-radius:8px;background:#ffffff;">' . $commentsDisplay . '</div>'
        . '</div>';

    $textBody = $mailTranslator->trans('info_request.mail_subject') . "\n\n"
        . $textRows . "\n"
        . $mailTranslator->trans('info_request.comments_title') . ":\n"
        . ($formData['comments'] !== '' ? $formData['comments'] : $mailTranslator->trans('info_request.mail_not_provided'));

    try {
        $mail = $configureMailer();

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'audreane20@gmail.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? 'audreane20@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($formData['email'], $formData['name']);
        $mail->isHTML(true);
        $mail->Subject = $mailTranslator->trans('info_request.mail_subject');
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();

        $_SESSION['inquiry_flash'] = [
            'success' => true,
        ];

        return redirectTo($response, $redirectTarget);
    } catch (Exception $e) {
        $_SESSION['inquiry_flash'] = [
            'success' => false,
            'error' => $translator->trans('info_request.error_send'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }
});

$app->post('/location-automobiles/formulaire', function ($request, $response) use ($translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail, $buildLocalizedPath) {
    $data = (array) $request->getParsedBody();

    $formData = [
        'preferred_language' => trim((string) ($data['preferred_language'] ?? 'fr')),
        'full_name' => trim((string) ($data['full_name'] ?? '')),
        'car_type' => trim((string) ($data['car_type'] ?? '')),
        'car_type_other' => trim((string) ($data['car_type_other'] ?? '')),
        'rental_from' => trim((string) ($data['rental_from'] ?? '')),
        'rental_until' => trim((string) ($data['rental_until'] ?? '')),
        'pickup_city' => trim((string) ($data['pickup_city'] ?? '')),
        'pickup_city_other' => trim((string) ($data['pickup_city_other'] ?? '')),
        'return_city' => trim((string) ($data['return_city'] ?? '')),
        'return_city_other' => trim((string) ($data['return_city_other'] ?? '')),
        'email' => trim((string) ($data['email'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
        'preferred_contact' => trim((string) ($data['preferred_contact'] ?? '')),
    ];

    $redirectTarget = $buildLocalizedPath('/location-automobiles/formulaire', $formData['preferred_language']);

    $requiredFields = [
        'full_name',
        'car_type',
        'rental_from',
        'rental_until',
        'pickup_city',
        'return_city',
        'email',
        'phone',
        'preferred_contact',
    ];

    foreach ($requiredFields as $field) {
        if ($formData[$field] === '') {
            $_SESSION['auto_rental_flash'] = [
                'success' => false,
                'error' => $translator->trans('rental_form.error_required'),
                'form_data' => $formData,
            ];

            return redirectTo($response, $redirectTarget);
        }
    }

    foreach (['car_type', 'pickup_city', 'return_city'] as $field) {
        if ($formData[$field] === 'other' && trim((string) ($formData[$field . '_other'] ?? '')) === '') {
            $_SESSION['auto_rental_flash'] = [
                'success' => false,
                'error' => $translator->trans('rental_form.error_required'),
                'form_data' => $formData,
            ];

            return redirectTo($response, $redirectTarget);
        }
    }

    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auto_rental_flash'] = [
            'success' => false,
            'error' => $translator->trans('rental_form.error_invalid_email'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }

    $displayMaps = [
        'car_type' => [
            'sedan_4' => $mailTranslator->trans('rental_form.car_option_sedan_4'),
            'compact_suv_5' => $mailTranslator->trans('rental_form.car_option_compact_suv_5'),
            'compact_jeep_5' => $mailTranslator->trans('rental_form.car_option_compact_jeep_5'),
            'suv_7' => $mailTranslator->trans('rental_form.car_option_suv_7'),
            'suv_9' => $mailTranslator->trans('rental_form.car_option_suv_9'),
            'suv_9_driver' => $mailTranslator->trans('rental_form.car_option_suv_9_driver'),
            'other' => $formData['car_type_other'],
        ],
        'pickup_city' => [
            'playa_del_carmen' => $mailTranslator->trans('rental_form.city_playa_del_carmen'),
            'cancun' => $mailTranslator->trans('rental_form.city_cancun'),
            'tulum' => $mailTranslator->trans('rental_form.city_tulum'),
            'other' => $formData['pickup_city_other'],
        ],
        'return_city' => [
            'playa_del_carmen' => $mailTranslator->trans('rental_form.city_playa_del_carmen'),
            'cancun' => $mailTranslator->trans('rental_form.city_cancun'),
            'tulum' => $mailTranslator->trans('rental_form.city_tulum'),
            'other' => $formData['return_city_other'],
        ],
        'preferred_contact' => [
            'email' => $mailTranslator->trans('rental_form.contact_email'),
            'whatsapp' => $mailTranslator->trans('rental_form.contact_whatsapp'),
        ],
    ];

    $displayValue = function (string $field) use ($formData, $displayMaps, $mailTranslator) {
        $value = $formData[$field] ?? '';

        if ($value === '') {
            return $mailTranslator->trans('rental_form.not_provided');
        }

        return $displayMaps[$field][$value] ?? $value;
    };

    $mailRows = [
        $mailTranslator->trans('rental_form.full_name') => $formData['full_name'],
        $mailTranslator->trans('rental_form.which_car') => $displayValue('car_type'),
        $mailTranslator->trans('rental_form.from_what_date') => $formData['rental_from'],
        $mailTranslator->trans('rental_form.until_when') => $formData['rental_until'],
        $mailTranslator->trans('rental_form.pickup_city') => $displayValue('pickup_city'),
        $mailTranslator->trans('rental_form.return_city') => $displayValue('return_city'),
        $mailTranslator->trans('rental_form.email') => $formData['email'],
        $mailTranslator->trans('rental_form.phone') => $formData['phone'],
        $mailTranslator->trans('rental_form.preferred_contact') => $displayValue('preferred_contact'),
        $mailTranslator->trans('contact.mail_language') => $formatPreferredLanguageForMail($formData['preferred_language']),
    ];

    $htmlRows = '';
    $textRows = '';

    foreach ($mailRows as $label => $value) {
        $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;background:#f6f9ff;font-weight:700;color:#17338f;width:34%;">' . $safeLabel . '</td>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;color:#16304d;">' . $safeValue . '</td>'
            . '</tr>';
        $textRows .= $label . ': ' . $value . "\n";
    }

    $subject = $mailTranslator->trans('rental_form.mail_subject');
    $htmlBody = '<div style="font-family:Arial,sans-serif;color:#16304d;line-height:1.5;">'
        . '<h2 style="margin:0 0 18px;color:#17338f;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<table style="width:100%;border-collapse:collapse;">' . $htmlRows . '</table>'
        . '</div>';

    try {
        $mail = $configureMailer();

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'audreane20@gmail.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? 'audreane20@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($formData['email'], $formData['full_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $subject . "\n\n" . $textRows;
        $mail->send();

        $_SESSION['auto_rental_flash'] = [
            'success' => true,
        ];

        return redirectTo($response, $redirectTarget);
    } catch (Exception $e) {
        $_SESSION['auto_rental_flash'] = [
            'success' => false,
            'error' => $translator->trans('rental_form.error_send'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }
});

$app->post('/gestion-immobiliere/formulaire', function ($request, $response) use ($translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail, $buildLocalizedPath) {
    $data = (array) $request->getParsedBody();

    $formData = [
        'preferred_language' => trim((string) ($data['preferred_language'] ?? 'fr')),
        'account_email' => trim((string) ($data['account_email'] ?? '')),
        'full_name' => trim((string) ($data['full_name'] ?? '')),
        'property_city' => trim((string) ($data['property_city'] ?? '')),
        'property_city_other' => trim((string) ($data['property_city_other'] ?? '')),
        'possession_date' => trim((string) ($data['possession_date'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
        'preferred_contact' => trim((string) ($data['preferred_contact'] ?? '')),
    ];

    $redirectTarget = $buildLocalizedPath('/gestion-immobiliere/formulaire', $formData['preferred_language']);

    $requiredFields = [
        'account_email',
        'full_name',
        'property_city',
        'possession_date',
        'phone',
        'preferred_contact',
    ];

    foreach ($requiredFields as $field) {
        if ($formData[$field] === '') {
            $_SESSION['management_form_flash'] = [
                'success' => false,
                'error' => $translator->trans('management_form.error_required'),
                'form_data' => $formData,
            ];

            return redirectTo($response, $redirectTarget);
        }
    }

    if ($formData['property_city'] === 'other' && $formData['property_city_other'] === '') {
        $_SESSION['management_form_flash'] = [
            'success' => false,
            'error' => $translator->trans('management_form.error_required'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }

    foreach (['account_email'] as $emailField) {
        if (!filter_var($formData[$emailField], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['management_form_flash'] = [
                'success' => false,
                'error' => $translator->trans('management_form.error_invalid_email'),
                'form_data' => $formData,
            ];

            return redirectTo($response, $redirectTarget);
        }
    }

    $displayMaps = [
        'property_city' => [
            'playa_del_carmen' => $mailTranslator->trans('management_form.city_playa_del_carmen'),
            'cancun' => $mailTranslator->trans('management_form.city_cancun'),
            'tulum' => $mailTranslator->trans('management_form.city_tulum'),
            'puerto_morelos' => $mailTranslator->trans('management_form.city_puerto_morelos'),
            'akumal' => $mailTranslator->trans('management_form.city_akumal'),
            'other' => $formData['property_city_other'],
        ],
        'preferred_contact' => [
            'email' => $mailTranslator->trans('management_form.contact_option_email'),
            'whatsapp' => $mailTranslator->trans('management_form.contact_option_whatsapp'),
        ],
    ];

    $displayValue = function (string $field) use ($formData, $displayMaps, $mailTranslator) {
        $value = $formData[$field] ?? '';

        if ($value === '') {
            return $mailTranslator->trans('management_form.not_provided');
        }

        return $displayMaps[$field][$value] ?? $value;
    };

    $mailRows = [
        $mailTranslator->trans('management_form.account_email') => $formData['account_email'],
        $mailTranslator->trans('management_form.full_name') => $formData['full_name'],
        $mailTranslator->trans('management_form.property_city') => $displayValue('property_city'),
        $mailTranslator->trans('management_form.possession_date') => $formData['possession_date'],
        $mailTranslator->trans('management_form.phone') => $formData['phone'],
        $mailTranslator->trans('management_form.preferred_contact') => $displayValue('preferred_contact'),
        $mailTranslator->trans('contact.mail_language') => $formatPreferredLanguageForMail($formData['preferred_language']),
    ];

    $htmlRows = '';
    $textRows = '';

    foreach ($mailRows as $label => $value) {
        $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;background:#f6f9ff;font-weight:700;color:#17338f;width:34%;">' . $safeLabel . '</td>'
            . '<td style="padding:10px 12px;border:1px solid #dfe7f3;color:#16304d;">' . $safeValue . '</td>'
            . '</tr>';
        $textRows .= $label . ': ' . $value . "\n";
    }

    $subject = $mailTranslator->trans('management_form.mail_subject');
    $htmlBody = '<div style="font-family:Arial,sans-serif;color:#16304d;line-height:1.5;">'
        . '<h2 style="margin:0 0 18px;color:#17338f;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<table style="width:100%;border-collapse:collapse;">' . $htmlRows . '</table>'
        . '</div>';

    try {
        $mail = $configureMailer();

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'audreane20@gmail.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? 'audreane20@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($formData['account_email'], $formData['full_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $subject . "\n\n" . $textRows;
        $mail->send();

        $_SESSION['management_form_flash'] = [
            'success' => true,
        ];

        return redirectTo($response, $redirectTarget);
    } catch (Exception $e) {
        $_SESSION['management_form_flash'] = [
            'success' => false,
            'error' => $translator->trans('management_form.error_send'),
            'form_data' => $formData,
        ];

        return redirectTo($response, $redirectTarget);
    }
});

$app->get('/login', function ($request, $response) use ($twig, $translator, $basePath) {
    if (isUserAuthenticated()) {
        return redirectTo($response, $basePath . '/');
    }

    $error = consumeUserAuthError();
    $success = consumeUserAuthSuccess();
    $lastEmail = getLastUserEmail();
    unset($_SESSION['user_auth_last_email']);

    return $twig->render($response, 'login.html.twig', [
        'page_title' => $translator->trans('auth.login_title'),
        'error' => $error,
        'success' => $success,
        'last_email' => $lastEmail,
    ]);
});

$app->post('/login', function ($request, $response) use ($userModel, $basePath) {
    if (!attemptUserLogin((array) $request->getParsedBody(), $userModel)) {
        return redirectTo($response, $basePath . '/login');
    }

    return redirectTo($response, $basePath . '/');
});

$app->get('/register', function ($request, $response) use ($twig, $translator, $basePath) {
    if (isUserAuthenticated()) {
        return redirectTo($response, $basePath . '/');
    }

    $error = consumeUserAuthError();
    $lastName = getLastUserName();
    $lastEmail = getLastUserEmail();
    unset($_SESSION['user_auth_last_name'], $_SESSION['user_auth_last_email']);

    return $twig->render($response, 'register.html.twig', [
        'page_title' => $translator->trans('auth.register_title'),
        'error' => $error,
        'last_name' => $lastName,
        'last_email' => $lastEmail,
    ]);
});

$app->post('/register', function ($request, $response) use ($userModel, $basePath) {
    if (!attemptUserRegistration((array) $request->getParsedBody(), $userModel)) {
        return redirectTo($response, $basePath . '/register');
    }

    return redirectTo($response, $basePath . '/profile');
});

$app->get('/profile', function ($request, $response) use ($twig, $translator, $userModel, $basePath) {
    $user = $userModel->findById((int) getUserId());

    if ($user === null) {
        logoutUser();

        return redirectTo($response, $basePath . '/login');
    }

    return $twig->render($response, 'profile.html.twig', [
        'page_title' => $translator->trans('profile.title'),
        'success' => consumeUserProfileSuccess(),
        'error' => consumeUserProfileError(),
        'profile_name' => $user['name'],
        'profile_email' => $user['email'],
    ]);
})->add($userAuthMiddleware);

$app->post('/profile', function ($request, $response) use ($userModel, $basePath, $translator) {
    $name = trim((string) (((array) $request->getParsedBody())['name'] ?? ''));
    $userId = (int) getUserId();

    if ($name === '') {
        $_SESSION['user_profile_error'] = $translator->trans('profile.error_name_required');

        return redirectTo($response, $basePath . '/profile');
    }

    $userModel->updateName($userId, $name);
    $_SESSION['user_name'] = $name;
    $_SESSION['user_profile_success'] = $translator->trans('profile.success_updated');

    return redirectTo($response, $basePath . '/profile');
})->add($userAuthMiddleware);

$app->post('/logout', function ($request, $response) use ($basePath) {
    logoutUser();

    return redirectTo($response, $basePath . '/');
});

$app->post('/account/logout', function ($request, $response) use ($basePath) {
    logoutCurrentAccount();

    return redirectTo($response, $basePath . '/');
});

$app->get('/admin/login', function ($request, $response) use ($twig, $basePath, $translator) {
    if (isAdminAuthenticated()) {
        return redirectTo($response, $basePath . '/admin/properties');
    }

    $error = getAuthError();
    $lastUsername = getLastAuthUsername();
    clearAuthFlash();

    return $twig->render($response, 'admin/login.html.twig', [
        'page_title' => $translator->trans('admin.login_title'),
        'error' => $error,
        'last_username' => $lastUsername,
    ]);
});

$app->post('/admin/login', function ($request, $response) use ($basePath) {
    if (!attemptAdminLogin((array) $request->getParsedBody())) {
        return redirectTo($response, $basePath . '/admin/login');
    }

    return redirectTo($response, $basePath . '/admin/properties');
});

$app->post('/admin/logout', function ($request, $response) use ($basePath) {
    logoutAdmin();

    return redirectTo($response, $basePath . '/admin/login');
})->add($adminAuthMiddleware);

$app->get('/admin/profile', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'admin/profile.html.twig', [
        'page_title' => $translator->trans('profile.title'),
        'success' => consumeAdminProfileSuccess(),
        'error' => consumeAdminProfileError(),
        'profile_name' => getAdminDisplayName() ?? '',
        'profile_email' => getAdminUsername() ?? '',
    ]);
})->add($adminAuthMiddleware);

$app->post('/admin/profile', function ($request, $response) use ($basePath) {
    $name = trim((string) (((array) $request->getParsedBody())['name'] ?? ''));

    if ($name === '') {
        $_SESSION['admin_profile_error'] = authTrans('profile.error_name_required');

        return redirectTo($response, $basePath . '/admin/profile');
    }

    $_SESSION['admin_display_name'] = $name;
    $_SESSION['admin_profile_success'] = authTrans('profile.success_updated');

    return redirectTo($response, $basePath . '/admin/profile');
})->add($adminAuthMiddleware);

$app->group('/admin', function ($group) use ($propertyController) {
    $group->get('/properties', [$propertyController, 'index']);
    $group->post('/properties/upload-progress/init', [$propertyController, 'initUploadProgress']);
    $group->get('/properties/upload-progress/{token}', [$propertyController, 'uploadProgress']);
    $group->post('/properties/create', [$propertyController, 'create']);
    $group->post('/properties/{id}/update', [$propertyController, 'update']);
    $group->post('/properties/{id}/images/primary', [$propertyController, 'setPrimaryImage']);
    $group->post('/properties/{id}/images/delete', [$propertyController, 'deleteImages']);
    $group->post('/properties/{id}/delete', [$propertyController, 'delete']);
})->add($adminAuthMiddleware);

$app->run();
