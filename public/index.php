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
        listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat',
        price_amount DECIMAL(12,2) NOT NULL,
        price_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        external_url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    ALTER TABLE property_cards
    ADD COLUMN IF NOT EXISTS listing_mode VARCHAR(20) NOT NULL DEFAULT 'achat'
    AFTER city
");

$propertyModel = new PropertyModel($pdo);
$userModel = new UserModel($pdo);
$propertyController = new PropertyController($propertyModel, $twig, $basePath);
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
        default => 'Francais',
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
    return $twig->render($response, 'contact.html.twig', [
        'page_title' => $translator->trans('nav.contact'),
        'success' => false,
        'error' => null,
        'name' => '',
        'email' => '',
        'phone' => '',
        'message' => '',
    ]);
});

$app->get('/demande-information', function ($request, $response) use ($renderInquiryForm) {
    return $renderInquiryForm($response);
});

$app->get('/properties', function ($request, $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    $target = $basePath . '/';

    if ($query !== '') {
        $target .= '?' . $query;
    }

    return redirectTo($response, $target);
});

$app->post('/contact', function ($request, $response) use ($twig, $translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail) {
    $data = $request->getParsedBody();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $message = trim($data['message'] ?? '');
    $preferredLanguage = trim((string) ($data['preferred_language'] ?? 'fr'));

    if ($name === '' || $email === '' || $message === '') {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => 'Veuillez remplir tous les champs obligatoires.',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => 'Veuillez entrer une adresse courriel valide.',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
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

        $mail->Subject = 'Nouveau message de contact - CLeMexique';
        $mail->Body =
            $mailTranslator->trans('contact.name') . ": {$name}\n" .
            $mailTranslator->trans('contact.email') . ": {$email}\n" .
            "Langue preferee: " . $formatPreferredLanguageForMail($preferredLanguage) . "\n" .
            $mailTranslator->trans('contact.phone') . ": {$phone}\n\n" .
            $mailTranslator->trans('contact.message') . ":\n{$message}";

        $mail->send();

        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => true,
            'error' => null,
            'name' => '',
            'email' => '',
            'phone' => '',
            'message' => '',
        ]);
    } catch (Exception $e) {
        return $twig->render($response, 'contact.html.twig', [
            'page_title' => $translator->trans('nav.contact'),
            'success' => false,
            'error' => 'Erreur lors de l envoi du message. Veuillez reessayer.',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
        ]);
    }
});

$app->post('/demande-information', function ($request, $response) use ($renderInquiryForm, $translator, $mailTranslator, $configureMailer, $formatPreferredLanguageForMail) {
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
            return $renderInquiryForm($response, [
                'error' => $translator->trans('info_request.error_required'),
                'form_data' => $formData,
            ]);
        }
    }

    if ($formData['city'] === 'other' && $formData['other_city'] === '') {
        return $renderInquiryForm($response, [
            'error' => $translator->trans('info_request.error_required'),
            'form_data' => $formData,
        ]);
    }

    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        return $renderInquiryForm($response, [
            'error' => $translator->trans('info_request.error_invalid_email'),
            'form_data' => $formData,
        ]);
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
        'Langue preferee' => $formatPreferredLanguageForMail($formData['preferred_language']),
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

        return $renderInquiryForm($response, [
            'success' => true,
        ]);
    } catch (Exception $e) {
        return $renderInquiryForm($response, [
            'error' => $translator->trans('info_request.error_send'),
            'form_data' => $formData,
        ]);
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

$app->post('/profile', function ($request, $response) use ($userModel, $basePath) {
    $name = trim((string) (((array) $request->getParsedBody())['name'] ?? ''));
    $userId = (int) getUserId();

    if ($name === '') {
        $_SESSION['user_profile_error'] = 'Please enter your name.';

        return redirectTo($response, $basePath . '/profile');
    }

    $userModel->updateName($userId, $name);
    $_SESSION['user_name'] = $name;
    $_SESSION['user_profile_success'] = 'Your profile has been updated.';

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

$app->get('/admin/login', function ($request, $response) use ($twig, $basePath) {
    if (isAdminAuthenticated()) {
        return redirectTo($response, $basePath . '/admin/properties');
    }

    $error = getAuthError();
    $lastUsername = getLastAuthUsername();
    clearAuthFlash();

    return $twig->render($response, 'admin/login.html.twig', [
        'page_title' => 'Admin Login',
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
        $_SESSION['admin_profile_error'] = 'Please enter your name.';

        return redirectTo($response, $basePath . '/admin/profile');
    }

    $_SESSION['admin_display_name'] = $name;
    $_SESSION['admin_profile_success'] = 'Your profile has been updated.';

    return redirectTo($response, $basePath . '/admin/profile');
})->add($adminAuthMiddleware);

$app->group('/admin', function ($group) use ($propertyController) {
    $group->get('/properties', [$propertyController, 'index']);
    $group->post('/properties/create', [$propertyController, 'create']);
    $group->post('/properties/{id}/update', [$propertyController, 'update']);
    $group->post('/properties/{id}/delete', [$propertyController, 'delete']);
})->add($adminAuthMiddleware);

$app->run();
