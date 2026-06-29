<?php

use App\Controller\PropertyController;
use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\PropertyModel;
use Dotenv\Dotenv;
use Slim\Views\Twig;

require __DIR__ . '/../vendor/autoload.php';

if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$jobId = trim((string) ($argv[1] ?? ''));

if ($jobId === '') {
    fwrite(STDERR, "Missing job id.\n");
    exit(1);
}

$databaseHost = trim((string) ($_ENV['DB_HOST'] ?? '127.0.0.1'));
$databasePort = (int) ($_ENV['DB_PORT'] ?? 3306);
$databaseName = trim((string) ($_ENV['DB_NAME'] ?? 'clemexique'));
$databaseUser = trim((string) ($_ENV['DB_USER'] ?? 'root'));
$databasePassword = (string) ($_ENV['DB_PASSWORD'] ?? '');
$databaseCharset = trim((string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4'));

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $databaseHost,
        $databasePort,
        $databaseName,
        $databaseCharset
    ),
    $databaseUser,
    $databasePassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$translator = new Translator(Locale::DEFAULT);
$twig = Twig::create(__DIR__ . '/../templates', ['cache' => false]);
$propertyModel = new PropertyModel($pdo);
$controller = new PropertyController($propertyModel, $twig, '', $translator);
$controller->processImportJobById($jobId);
