<?php

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Helper\Translator;
use App\Model\PropertyModel;
use App\Controller\PropertyController;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/../.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

session_start();

$lang = $_GET['lang'] ?? $_SESSION['lang'] ?? 'fr';
$lang = in_array($lang, ['fr', 'en'], true) ? $lang : 'fr';
$_SESSION['lang'] = $lang;

$app = AppFactory::create();

$basePath = '/CLeMexique/public';
$app->setBasePath($basePath);

$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => false,
]);

$translator = new Translator($lang);

$twig->getEnvironment()->addGlobal('app_lang', $lang);
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$twig->getEnvironment()->addFunction(
    new \Twig\TwigFunction('trans', function (string $key) use ($translator) {
        return $translator->trans($key);
    })
);

$app->add(TwigMiddleware::create($app, $twig));

// Database connection
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

$propertyModel = new PropertyModel($pdo);
$propertyController = new PropertyController($propertyModel, $twig, $basePath);

$app->get('/', function ($request, $response) use ($twig, $translator) {
    return $twig->render($response, 'home.html.twig', [
        'page_title' => $translator->trans('nav.home'),
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

$app->post('/contact', function ($request, $response) use ($twig, $translator) {
    $data = $request->getParsedBody();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $message = trim($data['message'] ?? '');

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
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['MAIL_USERNAME'] ?? 'audreane20@gmail.com';
        $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)($_ENV['MAIL_PORT'] ?? 587);

        $mail->CharSet = 'UTF-8';

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? 'audreane20@gmail.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'CLeMexique';
        $toEmail = $_ENV['MAIL_TO_EMAIL'] ?? 'audreane20@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->addReplyTo($email, $name);

        $mail->Subject = 'Nouveau message de contact - CLeMexique';

        $mail->Body =
            "Nom: {$name}\n" .
            "Courriel: {$email}\n" .
            "Téléphone: {$phone}\n\n" .
            "Message:\n{$message}";

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
            'error' => 'Erreur lors de l’envoi du message. Veuillez réessayer.',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
        ]);
    }
});

$app->get('/admin/properties', [$propertyController, 'index']);
$app->post('/admin/properties/create', [$propertyController, 'create']);
$app->post('/admin/properties/{id}/update', [$propertyController, 'update']);
$app->post('/admin/properties/{id}/delete', [$propertyController, 'delete']);

$app->run();