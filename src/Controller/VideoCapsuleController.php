<?php

namespace App\Controller;

use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\VideoCapsuleModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

class VideoCapsuleController
{
    private const FLASH_KEY = 'admin_content_flash';
    private const UPLOAD_DIRECTORY = '/public/uploads/video-capsules';
    private const ALLOWED_VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm'];

    public function __construct(
        private VideoCapsuleModel $videoCapsuleModel,
        private Twig $twig,
        private string $basePath,
        private Translator $translator
    ) {
    }

    public function publicIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);

        return $this->twig->render($response, 'video-capsules.html.twig', [
            'page_title' => $this->translator->trans('video_capsules.page_title'),
            'categories' => $this->videoCapsuleModel->findAllByLanguage($language),
        ]);
    }

    public function adminIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);
        $query = $request->getQueryParams();
        $editingCategoryIndex = isset($query['edit_item_category']) ? (int) $query['edit_item_category'] : null;
        $editingItemIndex = isset($query['edit_item']) ? (int) $query['edit_item'] : null;
        $flash = $this->consumeFlash();

        return $this->twig->render($response, 'admin/todo.html.twig', [
            'page_title' => $this->sectionConfig()['page_title'][$language],
            'section_config' => $this->sectionConfig(),
            'active_section' => 'video_capsules',
            'categories' => $this->videoCapsuleModel->findAllByLanguage($language),
            'editor_language' => $language,
            'editing_item' => $editingCategoryIndex !== null && $editingItemIndex !== null
                ? $this->videoCapsuleModel->findItemByIndexes($language, $editingCategoryIndex, $editingItemIndex)
                : null,
            'editing_item_category_index' => $editingCategoryIndex,
            'editing_item_index' => $editingItemIndex,
            'category_icon_choices' => $this->categoryIconChoices(),
            'flash_success' => $flash['success'] ?? null,
            'flash_error' => $flash['error'] ?? null,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);

        try {
            $this->guardUploadSize($request, $language);
            $data = $this->prepareVideoCapsuleData($request, $data, $language, null);
            $this->videoCapsuleModel->createItem($language, $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('item_saved', $language)];
        } catch (\RuntimeException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);
        $existingItem = $this->videoCapsuleModel->findItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);

        try {
            $this->guardUploadSize($request, $language);
            $data = $this->prepareVideoCapsuleData($request, $data, $language, $existingItem);
            $this->videoCapsuleModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
            if ($existingItem !== null && ($data['video_url'] ?? '') !== ($existingItem['video_url'] ?? '')) {
                $this->deleteManagedVideoFile((string) ($existingItem['video_url'] ?? ''));
            }
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('item_saved', $language)];
        } catch (\RuntimeException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);
        $existingItem = $this->videoCapsuleModel->findItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);

        try {
            $this->videoCapsuleModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);
            if ($existingItem !== null) {
                $this->deleteManagedVideoFile((string) ($existingItem['video_url'] ?? ''));
            }
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('item_deleted', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);
        $categories = $this->videoCapsuleModel->findAllByLanguage($language);
        $category = $categories[(int) $args['categoryIndex']] ?? null;

        try {
            $this->videoCapsuleModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
            if (is_array($category)) {
                foreach (($category['items'] ?? []) as $item) {
                    $this->deleteManagedVideoFile((string) ($item['video_url'] ?? ''));
                }
            }
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_deleted', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    private function sectionConfig(): array
    {
        return [
            'page_title' => [
                'en' => 'Video capsules management',
                'fr' => 'Gestion des capsules vidéo',
                'es' => 'Gestión de cápsulas de video',
            ],
            'page_copy' => [
                'en' => 'Add, edit, or remove video categories and video capsules.',
                'fr' => 'Ajoutez, modifiez ou supprimez des catégories et des capsules vidéo.',
                'es' => 'Agrega, edita o elimina categorías y cápsulas de video.',
            ],
            'category_fields' => [
                [
                    'name' => 'title',
                    'labels' => [
                        'en' => 'Category title',
                        'fr' => 'Titre de la catégorie',
                        'es' => 'Título de la categoría',
                    ],
                    'placeholders' => [
                        'en' => 'Ex. Beaches and lifestyle',
                        'fr' => 'Ex. Plages et art de vivre',
                        'es' => 'Ej. Playas y estilo de vida',
                    ],
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'labels' => ['en' => 'Video title', 'fr' => 'Titre de la vidéo', 'es' => 'Título del video'], 'placeholders' => ['en' => 'Video title', 'fr' => 'Titre de la vidéo', 'es' => 'Título del video']],
                ['name' => 'video_url', 'labels' => ['en' => 'Video link', 'fr' => 'Lien vidéo', 'es' => 'Enlace del video'], 'placeholders' => ['en' => 'https://youtube.com/...', 'fr' => 'https://youtube.com/...', 'es' => 'https://youtube.com/...']],
                ['name' => 'note', 'labels' => ['en' => 'Description', 'fr' => 'Description', 'es' => 'Descripción'], 'placeholders' => ['en' => 'Short description', 'fr' => 'Description courte', 'es' => 'Descripción breve']],
            ],
            'required_item_fields' => ['name'],
            'optional_item_fields' => ['video_url', 'note'],
            'show_video_upload' => true,
        ];
    }

    private function categoryIconChoices(): array
    {
        return [
            ['code' => 'MX', 'name' => 'Mexique'],
            ['code' => 'BEACH', 'name' => 'Plage'],
            ['code' => 'ROOFTOP', 'name' => 'Rooftop'],
            ['code' => 'MUSIC', 'name' => 'Musique'],
            ['code' => 'COFFEE', 'name' => 'Café'],
            ['code' => 'TACO', 'name' => 'Tacos'],
            ['code' => 'SEA', 'name' => 'Mer'],
            ['code' => 'CA', 'name' => 'Canada'],
        ];
    }

    private function currentLanguage(Request $request): string
    {
        $query = $request->getQueryParams();

        return Locale::normalize($query['lang'] ?? $_SESSION['lang'] ?? Locale::DEFAULT);
    }

    private function postedLanguage(array $data): string
    {
        return Locale::normalize($data['editor_language'] ?? Locale::DEFAULT);
    }

    private function buildAdminUrl(string $language, array $query = []): string
    {
        $language = Locale::normalize($language);
        $query = array_filter(array_merge(['lang' => $language], $query), static fn ($value) => $value !== null && $value !== '');

        return $this->basePath . '/admin/content/video_capsules?' . http_build_query($query);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function consumeFlash(): array
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        return is_array($flash) ? $flash : [];
    }

    private function successMessage(string $key, string $language): string
    {
        $messages = [
            'item_saved' => [
                'en' => 'Video capsule saved.',
                'fr' => 'Capsule vidéo enregistrée.',
                'es' => 'Cápsula de video guardada.',
            ],
            'item_deleted' => [
                'en' => 'Video capsule deleted.',
                'fr' => 'Capsule vidéo supprimée.',
                'es' => 'Cápsula de video eliminada.',
            ],
            'category_deleted' => [
                'en' => 'Category deleted.',
                'fr' => 'Catégorie supprimée.',
                'es' => 'Categoría eliminada.',
            ],
        ];

        $language = Locale::normalize($language);

        return $messages[$key][$language] ?? $messages[$key]['en'] ?? $key;
    }

    private function prepareVideoCapsuleData(Request $request, array $data, string $language, ?array $existingItem): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        $videoFile = $this->resolveUploadedVideoFile($uploadedFiles['video_file'] ?? null);
        $videoUrl = trim((string) ($data['video_url'] ?? ''));

        if ($videoFile instanceof UploadedFileInterface && $videoFile->getError() === UPLOAD_ERR_OK) {
            $data['video_url'] = $this->storeUploadedVideo($videoFile);
        } elseif ($videoFile instanceof UploadedFileInterface && $videoFile->getError() !== UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($videoFile->getError(), $language));
        } elseif ($videoUrl !== '') {
            $data['video_url'] = $videoUrl;
        } elseif ($existingItem !== null && trim((string) ($existingItem['video_url'] ?? '')) !== '') {
            $data['video_url'] = trim((string) $existingItem['video_url']);
        } else {
            throw new \InvalidArgumentException($this->missingVideoMessage($language));
        }

        return $data;
    }

    private function storeUploadedVideo(UploadedFileInterface $uploadedFile): string
    {
        $clientFilename = (string) $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, self::ALLOWED_VIDEO_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported video format. Use MP4, MOV, M4V, or WEBM.');
        }

        $uploadDirectory = dirname(__DIR__, 2) . self::UPLOAD_DIRECTORY;

        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new \InvalidArgumentException('Unable to create the video upload folder.');
        }

        $filename = 'capsule-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $uploadedFile->moveTo($uploadDirectory . DIRECTORY_SEPARATOR . $filename);

        return '/uploads/video-capsules/' . $filename;
    }

    private function deleteManagedVideoFile(string $videoUrl): void
    {
        $videoUrl = trim($videoUrl);

        if (!str_starts_with($videoUrl, '/uploads/video-capsules/')) {
            return;
        }

        $path = dirname(__DIR__, 2) . '/public' . $videoUrl;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function missingVideoMessage(string $language): string
    {
        $messages = [
            'en' => 'Please provide a video link or upload a video file.',
            'fr' => 'Veuillez fournir un lien vidéo ou téléverser un fichier vidéo.',
            'es' => 'Proporciona un enlace de video o sube un archivo de video.',
        ];

        $language = Locale::normalize($language);

        return $messages[$language] ?? $messages['en'];
    }

    private function resolveUploadedVideoFile(mixed $uploaded): ?UploadedFileInterface
    {
        if ($uploaded instanceof UploadedFileInterface) {
            return $uploaded;
        }

        if (is_array($uploaded)) {
            foreach ($uploaded as $item) {
                if ($item instanceof UploadedFileInterface) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function uploadErrorMessage(int $errorCode, string $language): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => [
                'en' => 'The uploaded video is too large for the server limit.',
                'fr' => 'La vidéo téléversée dépasse la limite autorisée par le serveur.',
                'es' => 'El video subido supera el límite permitido del servidor.',
            ],
            UPLOAD_ERR_FORM_SIZE => [
                'en' => 'The uploaded video is too large.',
                'fr' => 'La vidéo téléversée est trop volumineuse.',
                'es' => 'El video subido es demasiado grande.',
            ],
            UPLOAD_ERR_PARTIAL => [
                'en' => 'The video upload was only partially completed. Please try again.',
                'fr' => 'Le téléversement de la vidéo est incomplet. Veuillez réessayer.',
                'es' => 'La carga del video quedó incompleta. Inténtalo de nuevo.',
            ],
            UPLOAD_ERR_NO_TMP_DIR => [
                'en' => 'The server is missing a temporary upload folder.',
                'fr' => 'Le serveur n’a pas de dossier temporaire pour les téléversements.',
                'es' => 'Al servidor le falta una carpeta temporal para las cargas.',
            ],
            UPLOAD_ERR_CANT_WRITE => [
                'en' => 'The server could not save the uploaded video.',
                'fr' => 'Le serveur n’a pas pu enregistrer la vidéo téléversée.',
                'es' => 'El servidor no pudo guardar el video subido.',
            ],
            UPLOAD_ERR_EXTENSION => [
                'en' => 'A server extension blocked the video upload.',
                'fr' => 'Une extension du serveur a bloqué le téléversement de la vidéo.',
                'es' => 'Una extensión del servidor bloqueó la carga del video.',
            ],
        ];

        $language = Locale::normalize($language);

        return $messages[$errorCode][$language] ?? $this->missingVideoMessage($language);
    }

    private function guardUploadSize(Request $request, string $language): void
    {
        $contentLength = (int) ($request->getServerParams()['CONTENT_LENGTH'] ?? 0);
        $maxPostSize = $this->toBytes((string) ini_get('post_max_size'));

        if ($contentLength > 0 && $maxPostSize > 0 && $contentLength > $maxPostSize) {
            $messages = [
                'en' => 'The uploaded video is too large for the current server limit. Please upload a smaller file or increase the PHP upload limits.',
                'fr' => 'La video televersee depasse la limite actuelle du serveur. Veuillez televerser un fichier plus petit ou augmenter les limites PHP.',
                'es' => 'El video subido supera el limite actual del servidor. Sube un archivo mas pequeno o aumenta los limites de PHP.',
            ];

            $language = Locale::normalize($language);
            throw new \InvalidArgumentException($messages[$language] ?? $messages['en']);
        }
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round($number),
        };
    }
}
