<?php

use App\Controller\ExcursionController;
use App\Controller\PropertyController;
use App\Controller\RestaurantController;
use App\Controller\TodoController;
use App\Controller\VideoCapsuleController;
use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\ExcursionModel;
use App\Model\PropertyModel;
use App\Model\RestaurantModel;
use App\Model\TodoModel;
use App\Model\UserModel;
use App\Model\VideoCapsuleModel;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Helper/auth.php';
require_once __DIR__ . '/../src/Helper/site_content.php';

if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/../.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

function envFlag(string $key, bool $default = false): bool
{
    $value = $_ENV[$key] ?? null;

    if ($value === null) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function normalizeBasePath(?string $basePath): string
{
    $basePath = trim((string) $basePath);

    if ($basePath === '' || $basePath === '/') {
        return '';
    }

    return '/' . trim($basePath, '/');
}

function buildAbsoluteUrl(\Psr\Http\Message\ServerRequestInterface $request, string $basePath, string $path = '/', array $query = []): string
{
    $uri = $request->getUri();
    $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
    $host = $uri->getHost();
    $port = $uri->getPort();
    $normalizedPath = $path === '/' ? '/' : '/' . ltrim($path, '/');
    $url = $scheme . '://' . $host;

    if ($port !== null) {
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        if (!$isDefaultPort) {
            $url .= ':' . $port;
        }
    }

    $url .= $basePath . $normalizedPath;

    if ($query !== []) {
        $queryString = http_build_query(array_filter(
            $query,
            static fn ($value): bool => $value !== null && $value !== ''
        ));

        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
    }

    return $url;
}

function normalizeAppRequestPath(string $uriPath, string $basePath): string
{
    $path = '/' . ltrim($uriPath, '/');

    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    return $path === '' ? '/' : $path;
}

function shouldIndexRequest(string $path, array $queryParams): bool
{
    if ($path !== '/') {
        return false;
    }

    $meaningfulQueryParams = array_filter(
        $queryParams,
        static fn ($value): bool => $value !== null && $value !== ''
    );

    return $meaningfulQueryParams === [];
}

function defaultRobotsDirective(string $path, array $queryParams): ?string
{
    return shouldIndexRequest($path, $queryParams) ? null : 'noindex, follow, noarchive';
}

function escapeXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function verifyRecaptchaResponse(string $secretKey, string $responseToken, ?string $remoteIp = null): bool
{
    if ($secretKey === '' || $responseToken === '') {
        return false;
    }

    $payload = http_build_query(array_filter([
        'secret' => $secretKey,
        'response' => $responseToken,
        'remoteip' => $remoteIp,
    ], static fn ($value): bool => $value !== null && $value !== ''));

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ];

    $result = @file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify',
        false,
        stream_context_create($options)
    );

    if ($result === false) {
        return false;
    }

    $decoded = json_decode($result, true);

    return is_array($decoded) && ($decoded['success'] ?? false) === true;
}

function columnExists(PDO $pdo, string $databaseName, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :database_name
            AND TABLE_NAME = :table_name
            AND COLUMN_NAME = :column_name
    ");

    $statement->execute([
        'database_name' => $databaseName,
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

session_start();

$lang = Locale::normalize($_GET['lang'] ?? $_SESSION['lang'] ?? Locale::DEFAULT);
$_SESSION['lang'] = $lang;

$app = AppFactory::create();

$basePath = normalizeBasePath($_ENV['APP_BASE_PATH'] ?? '');
$app->setBasePath($basePath);
$currentRequestPath = normalizeAppRequestPath(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $basePath);
$defaultPageRobots = defaultRobotsDirective($currentRequestPath, $_GET);

$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => false,
]);

$translator = new Translator($lang);
$mailTranslator = new Translator('fr');
$headerMenuLinks = [
    [
        'path' => '/demande-information',
        'label' => $translator->trans('info_request.title'),
    ],
    [
        'href' => 'https://saleah.stays.net/',
        'label' => $translator->trans('info_request_rent.title'),
        'external' => true,
    ],
    [
        'path' => '/residence-immigration',
        'label' => $translator->trans('info_request.secondary_title'),
        'fragment' => 'home-residency',
    ],
    [
        'path' => '/location-automobiles',
        'label' => $translator->trans('auto_rental.title'),
        'fragment' => 'home-rental',
    ],
    [
        'path' => '/gestion-immobiliere',
        'label' => $translator->trans('property_management.title'),
        'fragment' => 'home-management',
    ],
    [
        'path' => '/restaurants-a-essayer',
        'label' => $translator->trans('restaurants.title'),
        'fragment' => 'home-restaurants',
    ],
    [
        'path' => '/excursions',
        'label' => $translator->trans('excursions.title'),
        'fragment' => 'home-excursions',
    ],
    [
        'path' => '/quoi-faire-a-playa',
        'label' => $translator->trans('playa_guide.title'),
        'fragment' => 'home-playa-guide',
    ],
    [
        'path' => '/activites-sportives',
        'label' => $translator->trans('sports_activities.title'),
        'fragment' => 'home-sports-activities',
    ],
    [
        'path' => '/capsules-video-mexique',
        'label' => $translator->trans('video_capsules.title'),
        'fragment' => 'home-video-capsules',
    ],
    [
        'path' => '/',
        'label' => $translator->trans('home.menu_map_title'),
        'fragment' => 'home-map-guide',
    ],
];

$twig->getEnvironment()->addGlobal('app_lang', $lang);
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$twig->getEnvironment()->addGlobal('default_page_robots', $defaultPageRobots);
$twig->getEnvironment()->addGlobal('is_admin_authenticated', isAdminAuthenticated());
$twig->getEnvironment()->addGlobal('admin_username', getAdminDisplayName());
$twig->getEnvironment()->addGlobal('is_user_authenticated', isUserAuthenticated());
$twig->getEnvironment()->addGlobal('user_name', getUserName());
$twig->getEnvironment()->addGlobal('header_menu_links', $headerMenuLinks);
$twig->getEnvironment()->addFunction(
    new \Twig\TwigFunction('trans', function (string $key, array $replacements = []) use ($translator) {
        return $translator->trans($key, $replacements);
    })
);
$twig->getEnvironment()->addFunction(
    new \Twig\TwigFunction('render_category_icon', function (?string $code) use ($basePath) {
        if ($code === null || trim($code) === '') {
            return '';
        }

        $code = strtoupper(trim($code));
        $imageMap = [
            'IT' => ['https://flagcdn.com/w40/it.png', 'Italie'],
            'FR' => ['https://flagcdn.com/w40/fr.png', 'France'],
            'IN' => ['https://flagcdn.com/w40/in.png', 'Inde'],
            'TH' => ['https://flagcdn.com/w40/th.png', 'Thaïlande'],
            'CA' => ['https://flagcdn.com/w40/ca.png', 'Canada'],
            'CA-QC' => ['https://upload.wikimedia.org/wikipedia/commons/5/5f/Flag_of_Quebec.svg', 'Québec'],
            'MX' => ['https://flagcdn.com/w40/mx.png', 'Mexique'],
            'JM' => ['https://flagcdn.com/w40/jm.png', 'Jamaïque'],
        ];
        $symbolMap = [
            'MUSIC' => '&#127925;',
            'SEA' => '&#128031;',
            'GRILL' => '&#128293;',
            'STEAK' => '&#129385;',
            'LUXE' => '&#128081;',
            'PASTA' => '&#127837;',
            'BAKERY' => '&#129360;',
            'COFFEE' => '&#9749;',
            'COCKTAIL' => '&#127864;',
            'TACO' => '&#127790;',
            'SPICE' => '&#127798;',
            'ROOFTOP' => '&#127749;',
            'BEACH' => '&#127958;',
        ];

        if (isset($imageMap[$code])) {
            [$src, $alt] = $imageMap[$code];

            return sprintf(
                '<img src="%s" alt="%s" class="restaurant-category-flag">',
                htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($alt, ENT_QUOTES, 'UTF-8')
            );
        }

        if (isset($symbolMap[$code])) {
            return sprintf(
                '<span class="restaurant-category-icon" aria-hidden="true">%s</span>',
                $symbolMap[$code]
            );
        }

        return sprintf(
            '<span class="restaurant-category-icon" aria-hidden="true">%s</span>',
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        );
    }, ['is_safe' => ['html']])
);

$app->add(TwigMiddleware::create($app, $twig));
$app->add(function ($request, $handler) use ($basePath) {
    $response = $handler->handle($request);
    $method = strtoupper($request->getMethod());

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return $response;
    }

    $path = normalizeAppRequestPath($request->getUri()->getPath(), $basePath);
    $robotsDirective = defaultRobotsDirective($path, $request->getQueryParams());

    if ($robotsDirective === null) {
        return $response;
    }

    return $response->withHeader('X-Robots-Tag', $robotsDirective);
});

$databaseHost = trim((string) ($_ENV['DB_HOST'] ?? '127.0.0.1'));
$databasePort = (int) ($_ENV['DB_PORT'] ?? 3306);
$databaseName = trim((string) ($_ENV['DB_NAME'] ?? 'clemexique'));
$databaseUser = trim((string) ($_ENV['DB_USER'] ?? 'root'));
$databasePassword = (string) ($_ENV['DB_PASSWORD'] ?? '');
$databaseCharset = trim((string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4'));
$shouldAutoCreateDatabase = envFlag('APP_AUTO_CREATE_DATABASE', true);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $databaseHost,
        $databasePort,
        $databaseCharset
    ),
    $databaseUser,
    $databasePassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$quotedDatabaseName = '`' . str_replace('`', '``', $databaseName) . '`';
$quotedDatabaseCharset = preg_replace('/[^A-Za-z0-9_]+/', '', $databaseCharset) ?: 'utf8mb4';

if ($shouldAutoCreateDatabase) {
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s_unicode_ci',
        $quotedDatabaseName,
        $quotedDatabaseCharset,
        $quotedDatabaseCharset
    ));
}

$pdo->exec(sprintf('USE %s', $quotedDatabaseName));
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
if (!columnExists($pdo, $databaseName, 'property_cards', 'listing_mode')) {
    $pdo->exec("
        ALTER TABLE property_cards
        ADD COLUMN listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat'
        AFTER city
    ");
}

if (!columnExists($pdo, $databaseName, 'property_cards', 'description')) {
    $pdo->exec("
        ALTER TABLE property_cards
        ADD COLUMN description TEXT NULL
        AFTER city
    ");
}

setSiteContentPdo($pdo);

$propertyModel = new PropertyModel($pdo);
$restaurantModel = new RestaurantModel($pdo);
$excursionModel = new ExcursionModel($pdo);
$todoModel = new TodoModel($pdo);
$videoCapsuleModel = new VideoCapsuleModel($pdo);
$userModel = new UserModel($pdo);
$propertyController = new PropertyController($propertyModel, $twig, $basePath, $translator);
$restaurantController = new RestaurantController($restaurantModel, $twig, $basePath, $translator);
$excursionController = new ExcursionController($excursionModel, $twig, $basePath, $translator);
$todoController = new TodoController($todoModel, $twig, $basePath, $translator);
$videoCapsuleController = new VideoCapsuleController($videoCapsuleModel, $twig, $basePath, $translator);
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
            'intent' => 'achat',
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
    $language = Locale::normalize($language);

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
    $mail->Host = $_ENV['MAIL_HOST'] ?? '';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->CharSet = 'UTF-8';

    return $mail;
};

$formatPreferredLanguageForMail = function (string $language): string {
    return Locale::preferredLanguageLabel($language);
};

$app->get('/sitemap.xml', function ($request, $response) use ($basePath) {
    $entries = [[
        'loc' => buildAbsoluteUrl($request, $basePath, '/'),
        'changefreq' => 'weekly',
        'priority' => '1.0',
    ]];

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

    foreach ($entries as $entry) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . escapeXml($entry['loc']) . "</loc>\n";
        $xml .= '    <changefreq>' . escapeXml($entry['changefreq']) . "</changefreq>\n";
        $xml .= '    <priority>' . escapeXml($entry['priority']) . "</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= "</urlset>\n";
    $response->getBody()->write($xml);

    return $response
        ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
});

$app->get('/', function ($request, $response) use ($twig, $translator, $propertyModel) {
    $queryParams = $request->getQueryParams();
    $mode = strtolower((string) ($queryParams['mode'] ?? 'future_projet'));

    if (!in_array($mode, ['revente', 'future_projet'], true)) {
        $mode = 'future_projet';
    }

    return $twig->render($response, 'home.html.twig', [
        'page_title' => 'CLeMexique - Home',
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
        'subject' => trim((string) ($queryParams['subject'] ?? '')),
        'project' => trim((string) ($queryParams['project'] ?? '')),
        'message' => '',
        'recaptcha_site_key' => trim((string) ($_ENV['RECAPTCHA_SITE_KEY'] ?? '')),
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

$app->get('/demande-information', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'purchase-guide.html.twig', [
        'page_title' => $translator->trans('purchase_guide.page_title'),
    ]);
});

$app->get('/gestion-immobiliere', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'property-management.html.twig', [
        'page_title' => $translator->trans('property_management.page_title'),
    ]);
});

$app->get('/activites-sportives', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'sports-activities.html.twig', [
        'page_title' => $translator->trans('sports_activities.page_title'),
    ]);
});

$app->get('/capsules-video-mexique', function ($request, $response) use ($videoCapsuleController) {
    return $videoCapsuleController->publicIndex($request, $response);
});

$app->get('/excursions', [$excursionController, 'publicIndex']);
$app->get('/quoi-faire-a-playa', [$todoController, 'publicIndex']);
$app->get('/restaurants-a-essayer', [$restaurantController, 'publicIndex']);
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

$app->get('/demande-information/formulaire', function ($request, $response) use ($renderInquiryForm, $consumeInquiryFlash) {
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

    $target .= '#home-properties';

    return redirectTo($response, $target);
});

$app->get('/properties/{id}', [$propertyController, 'show']);

$app->post('/contact', function ($request, $response) use ($twig, $translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail) {
    $data = $request->getParsedBody();
    $serverParams = $request->getServerParams();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $subject = trim($data['subject'] ?? '');
    $project = trim($data['project'] ?? '');
    $message = trim($data['message'] ?? '');
    $preferredLanguage = trim((string) ($data['preferred_language'] ?? 'fr'));
    $recaptchaResponse = trim((string) ($data['g-recaptcha-response'] ?? ''));
    $recaptchaSiteKey = trim((string) ($_ENV['RECAPTCHA_SITE_KEY'] ?? ''));
    $recaptchaSecretKey = trim((string) ($_ENV['RECAPTCHA_SECRET_KEY'] ?? ''));
    $remoteIp = isset($serverParams['REMOTE_ADDR']) ? trim((string) $serverParams['REMOTE_ADDR']) : null;

    $renderContactForm = static function (string $errorMessage) use ($twig, $response, $translator, $name, $email, $phone, $subject, $project, $message, $recaptchaSiteKey) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => $errorMessage,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'project' => $project,
            'message' => $message,
            'recaptcha_site_key' => $recaptchaSiteKey,
        ]);
    };

    if ($name === '' || $email === '' || $message === '') {
        return $renderContactForm($translator->trans('contact.error_required'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $renderContactForm($translator->trans('contact.error_invalid_email'));
    }

    if ($recaptchaSiteKey === '' || $recaptchaSecretKey === '') {
        return $renderContactForm($translator->trans('contact.error_captcha_unavailable'));
    }

    if (!verifyRecaptchaResponse($recaptchaSecretKey, $recaptchaResponse, $remoteIp)) {
        return $renderContactForm($translator->trans('contact.error_captcha'));
    }

    try {
        $mail = $configureMailer();

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ($_ENV['MAIL_USERNAME'] ?? '');
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? '';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($email, $name);

        $mail->Subject = $mailTranslator->trans('contact.mail_subject');
        $mail->Body =
            $mailTranslator->trans('contact.name') . ": {$name}\n" .
            $mailTranslator->trans('contact.email') . ": {$email}\n" .
            $mailTranslator->trans('contact.mail_language') . ": " . $formatPreferredLanguageForMail($preferredLanguage) . "\n" .
            $mailTranslator->trans('contact.phone') . ": {$phone}\n" .
            $mailTranslator->trans('contact.subject') . ": {$subject}\n" .
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
            'subject' => '',
            'project' => '',
            'message' => '',
            'recaptcha_site_key' => $recaptchaSiteKey,
        ]);
    } catch (Exception $e) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => $translator->trans('contact.error_send'),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'project' => $project,
            'message' => $message,
            'recaptcha_site_key' => $recaptchaSiteKey,
        ]);
    }
});

$app->post('/demande-information/formulaire', function ($request, $response) use ($translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail, $buildLocalizedPath) {
    $data = $request->getParsedBody();

    $formData = [
        'preferred_language' => trim((string) ($data['preferred_language'] ?? 'fr')),
        'intent' => 'achat',
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

    $redirectTarget = $buildLocalizedPath('/demande-information/formulaire', $formData['preferred_language']);

    if ($formData['city'] === '' && $formData['other_city'] !== '') {
        $formData['city'] = 'other';
    }

    $requiredFields = [
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

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ($_ENV['MAIL_USERNAME'] ?? '');
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? '';

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

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ($_ENV['MAIL_USERNAME'] ?? '');
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? '';

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

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ($_ENV['MAIL_USERNAME'] ?? '');
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? '';

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

$app->get('/login', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->post('/login', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->get('/register', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->post('/register', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->get('/profile', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->post('/profile', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/');
});

$app->post('/logout', function ($request, $response) use ($basePath) {
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

    if (isAdminVerificationPending()) {
        return redirectTo($response, $basePath . '/admin/verify');
    }

    $error = getAuthError();
    clearAuthFlash();

    $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    return $twig->render($response, 'admin/login.html.twig', [
        'page_title' => $translator->trans('admin.login_title'),
        'page_robots' => 'noindex, nofollow, noarchive',
        'error' => $error,
    ]);
});

$app->get('/rmadmin', function ($request, $response) use ($basePath) {
    return redirectTo($response, $basePath . '/admin/login');
});

$app->post('/admin/login', function ($request, $response) use ($basePath) {
    if (!attemptAdminLogin((array) $request->getParsedBody())) {
        return redirectTo($response, $basePath . '/admin/login');
    }

    return redirectTo($response, $basePath . '/admin/verify');
});

$app->get('/admin/verify', function ($request, $response) use ($twig, $basePath, $translator) {
    if (isAdminAuthenticated()) {
        return redirectTo($response, $basePath . '/admin/properties');
    }

    if (!isAdminVerificationPending()) {
        return redirectTo($response, $basePath . '/admin/login');
    }

    $error = getAuthError();
    $success = getAuthSuccess();
    clearAuthFlash();

    $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    return $twig->render($response, 'admin/verify.html.twig', [
        'page_title' => $translator->trans('admin.verify_title'),
        'page_robots' => 'noindex, nofollow, noarchive',
        'error' => $error,
        'success' => $success,
        'admin_email' => getMaskedPrimaryAdminTwoFactorEmail(),
        'secondary_admin_email' => hasAdminBackupTwoFactorEmail() ? maskEmailAddress(getAdminBackupTwoFactorEmail()) : '',
    ]);
});

$app->post('/admin/verify', function ($request, $response) use ($basePath) {
    if (!attemptAdminTwoFactorVerification((array) $request->getParsedBody())) {
        return redirectTo($response, $basePath . '/admin/verify');
    }

    return redirectTo($response, $basePath . '/admin/properties');
});

$app->post('/admin/verify/use-backup', function ($request, $response) use ($basePath) {
    if (!resendPendingAdminVerificationCodeToBackup()) {
        return redirectTo($response, $basePath . '/admin/verify');
    }

    return redirectTo($response, $basePath . '/admin/verify');
});

$app->get('/admin/change-email', function ($request, $response) use ($twig, $basePath, $translator) {
    $error = getAuthError();
    $success = getAuthSuccess();
    clearAuthFlash();

    return $twig->render($response, 'admin/change-email.html.twig', [
        'page_title' => $translator->trans('admin.change_email_title'),
        'error' => $error,
        'success' => $success,
        'current_email' => getAdminTwoFactorEmail(),
        'backup_email' => getAdminBackupTwoFactorEmail(),
        'pending_new_email' => isAdminEmailChangePending() ? ($_SESSION['admin_email_change_new'] ?? '') : '',
        'is_change_verification_pending' => isAdminEmailChangePending(),
    ]);
})->add($adminAuthMiddleware);

$app->post('/admin/change-email/start', function ($request, $response) use ($basePath) {
    $newEmail = strtolower(trim((string) (((array) $request->getParsedBody())['email'] ?? '')));

    if (!startAdminEmailChangeVerification($newEmail)) {
        return redirectTo($response, $basePath . '/admin/change-email');
    }

    $_SESSION['auth_success'] = authTrans('admin.change_email_verify_success');

    return redirectTo($response, $basePath . '/admin/change-email');
})->add($adminAuthMiddleware);

$app->post('/admin/change-email/verify', function ($request, $response) use ($basePath) {
    if (!attemptAdminEmailChangeVerification((array) $request->getParsedBody())) {
        return redirectTo($response, $basePath . '/admin/change-email');
    }

    return redirectTo($response, $basePath . '/admin/profile');
})->add($adminAuthMiddleware);

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

$app->group('/admin/content', function ($group) use ($restaurantController, $excursionController, $todoController, $videoCapsuleController) {
    $group->get('/restaurants', [$restaurantController, 'adminIndex']);
    $group->post('/restaurants/items/create', [$restaurantController, 'create']);
    $group->post('/restaurants/items/{categoryIndex}/{itemIndex}/update', [$restaurantController, 'update']);
    $group->post('/restaurants/items/{categoryIndex}/{itemIndex}/delete', [$restaurantController, 'deleteItem']);
    $group->post('/restaurants/categories/{categoryIndex}/delete', [$restaurantController, 'deleteCategory']);

    $group->get('/excursions', [$excursionController, 'adminIndex']);
    $group->post('/excursions/items/create', [$excursionController, 'create']);
    $group->post('/excursions/items/{categoryIndex}/{itemIndex}/update', [$excursionController, 'update']);
    $group->post('/excursions/items/{categoryIndex}/{itemIndex}/delete', [$excursionController, 'deleteItem']);
    $group->post('/excursions/categories/{categoryIndex}/delete', [$excursionController, 'deleteCategory']);

    $group->get('/playa_guide', [$todoController, 'adminIndex']);
    $group->post('/playa_guide/items/create', [$todoController, 'create']);
    $group->post('/playa_guide/items/{categoryIndex}/{itemIndex}/update', [$todoController, 'update']);
    $group->post('/playa_guide/items/{categoryIndex}/{itemIndex}/delete', [$todoController, 'deleteItem']);
    $group->post('/playa_guide/categories/{categoryIndex}/delete', [$todoController, 'deleteCategory']);

    $group->get('/video_capsules', [$videoCapsuleController, 'adminIndex']);
    $group->post('/video_capsules/items/create', [$videoCapsuleController, 'create']);
    $group->post('/video_capsules/items/{categoryIndex}/{itemIndex}/update', [$videoCapsuleController, 'update']);
    $group->post('/video_capsules/items/{categoryIndex}/{itemIndex}/delete', [$videoCapsuleController, 'deleteItem']);
    $group->post('/video_capsules/categories/{categoryIndex}/delete', [$videoCapsuleController, 'deleteCategory']);
})->add($adminAuthMiddleware);

$app->run();

