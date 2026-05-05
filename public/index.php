<?php

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Helper\Translator;

require __DIR__ . '/../vendor/autoload.php';

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
    ]);
});

$app->post('/contact', function ($request, $response) use ($twig, $translator) {
    $data = $request->getParsedBody();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $message = trim($data['message'] ?? '');

    // For now: no email sent yet, just show success message.
    return $twig->render($response, 'contact.html.twig', [
        'page_title' => $translator->trans('nav.contact'),
        'success' => true,
    ]);
});

$app->run();