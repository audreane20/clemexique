<?php

namespace App\Controller;

use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\ActivityModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

class ActivityController
{
    private const FLASH_KEY = 'admin_content_flash';
    private const UPLOAD_DIRECTORY = '/public/uploads/activities';
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    private const ALLOWED_VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm'];

    public function __construct(
        private ActivityModel $activityModel,
        private Twig $twig,
        private string $basePath,
        private Translator $translator
    ) {
    }

    public function adminIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);
        $query = $request->getQueryParams();
        $editingCategoryOnlyIndex = isset($query['edit_category']) ? (int) $query['edit_category'] : null;
        $editingCategoryIndex = isset($query['edit_item_category']) ? (int) $query['edit_item_category'] : null;
        $editingItemIndex = isset($query['edit_item']) ? (int) $query['edit_item'] : null;
        $flash = $this->consumeFlash();
        $sectionConfig = $this->sectionConfig();

        return $this->twig->render($response, 'admin/todo.html.twig', [
            'page_title_key' => $sectionConfig['page_title'],
            'section_config' => $sectionConfig,
            'active_section' => 'activities',
            'categories' => $this->activityModel->findAllByLanguage($language),
            'editor_language' => $language,
            'editing_category' => $editingCategoryOnlyIndex !== null
                ? $this->activityModel->findCategoryByIndex($language, $editingCategoryOnlyIndex)
                : null,
            'editing_category_index' => $editingCategoryOnlyIndex,
            'editing_item' => $editingCategoryIndex !== null && $editingItemIndex !== null
                ? $this->activityModel->findItemByIndexes($language, $editingCategoryIndex, $editingItemIndex)
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
            $data = $this->prepareActivityData($request, $data, null);
            $this->activityModel->createItem($language, $data);
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
        $existingItem = $this->activityModel->findItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);

        try {
            $this->guardUploadSize($request, $language);
            $data = $this->prepareActivityData($request, $data, $existingItem);
            $this->activityModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
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
        $existingItem = $this->activityModel->findItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);

        try {
            $this->activityModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);
            $this->deleteManagedMediaFiles((array) ($existingItem['media'] ?? []));
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
        $categories = $this->activityModel->findAllByLanguage($language);
        $category = $categories[(int) $args['categoryIndex']] ?? null;

        try {
            $this->activityModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
            if (is_array($category)) {
                foreach (($category['items'] ?? []) as $item) {
                    $this->deleteManagedMediaFiles((array) ($item['media'] ?? []));
                }
            }
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_deleted', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);

        try {
            $this->activityModel->updateCategoryByIndex($language, (int) $args['categoryIndex'], $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_saved', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.activities.page_title',
            'page_copy' => 'admin_content.sections.activities.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.activities.category_title_placeholder',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.activity_name', 'placeholder_key' => 'admin_content.fields.activity_name'],
                ['name' => 'area', 'label_key' => 'admin_content.fields.location', 'placeholder_key' => 'admin_content.sections.common.playa_placeholder'],
                ['name' => 'description', 'type' => 'textarea', 'label_key' => 'admin_content.fields.description', 'placeholder_key' => 'admin_content.fields.optional_description_placeholder'],
                ['name' => 'url', 'label_key' => 'admin_content.fields.website', 'placeholder_key' => 'admin_content.fields.url_placeholder'],
            ],
            'required_item_fields' => ['name', 'area'],
            'optional_item_fields' => ['description', 'url', 'media_files'],
            'show_media_upload' => true,
            'native_submit' => true,
        ];
    }

    private function categoryIconChoices(): array
    {
        return [
            ['code' => 'BEACH', 'name' => 'Plage'],
            ['code' => 'SEA', 'name' => 'Mer'],
            ['code' => 'MUSIC', 'name' => 'Musique'],
            ['code' => 'ROOFTOP', 'name' => 'Rooftop'],
            ['code' => 'MX', 'name' => 'Mexique'],
            ['code' => 'COCKTAIL', 'name' => 'Cocktail'],
            ['code' => 'TACO', 'name' => 'Tacos'],
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

        return $this->basePath . '/admin/content/activities?' . http_build_query($query);
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
        return (new Translator($language))->trans('admin_content.flash.' . $key);
    }

    private function prepareActivityData(Request $request, array $data, ?array $existingItem): array
    {
        $existingMedia = is_array($existingItem['media'] ?? null) ? $existingItem['media'] : [];
        $uploadedMedia = $this->storeUploadedMedia($request->getUploadedFiles()['media_files'] ?? null);
        $data['media'] = array_values(array_merge($existingMedia, $uploadedMedia));

        return $data;
    }

    private function storeUploadedMedia(mixed $uploaded): array
    {
        $files = $this->flattenUploadedFiles($uploaded);

        if ($files === []) {
            return [];
        }

        $uploadDirectory = dirname(__DIR__, 2) . self::UPLOAD_DIRECTORY;

        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new \InvalidArgumentException('Unable to create the activity upload folder.');
        }

        $stored = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('One of the uploaded files could not be saved. Please try again.');
            }

            $clientFilename = (string) $file->getClientFilename();
            $extension = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));

            if (in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
                $type = 'image';
            } elseif (in_array($extension, self::ALLOWED_VIDEO_EXTENSIONS, true)) {
                $type = 'video';
            } else {
                throw new \InvalidArgumentException('Unsupported file format. Use JPG, PNG, WEBP, GIF, AVIF, MP4, MOV, M4V, or WEBM.');
            }

            $filename = 'activity-' . bin2hex(random_bytes(8)) . '.' . $extension;
            $file->moveTo($uploadDirectory . DIRECTORY_SEPARATOR . $filename);
            $stored[] = [
                'type' => $type,
                'url' => '/uploads/activities/' . $filename,
            ];
        }

        return $stored;
    }

    private function flattenUploadedFiles(mixed $uploaded): array
    {
        if ($uploaded instanceof UploadedFileInterface) {
            return [$uploaded];
        }

        if (!is_array($uploaded)) {
            return [];
        }

        $files = [];

        foreach ($uploaded as $item) {
            if ($item instanceof UploadedFileInterface) {
                $files[] = $item;
            }
        }

        return $files;
    }

    private function deleteManagedMediaFiles(array $media): void
    {
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));

            if (!str_starts_with($url, '/uploads/activities/')) {
                continue;
            }

            $path = dirname(__DIR__, 2) . '/public' . $url;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function guardUploadSize(Request $request, string $language): void
    {
        $contentLength = (int) ($request->getServerParams()['CONTENT_LENGTH'] ?? 0);
        $maxPostSize = $this->toBytes((string) ini_get('post_max_size'));

        if ($contentLength > 0 && $maxPostSize > 0 && $contentLength > $maxPostSize) {
            $messages = [
                'en' => 'The uploaded files are too large for the current server limit.',
                'fr' => 'Les fichiers televerses depassent la limite actuelle du serveur.',
                'es' => 'Los archivos subidos superan el limite actual del servidor.',
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
        $bytes = (float) $value;

        return match ($unit) {
            'g' => (int) ($bytes * 1024 * 1024 * 1024),
            'm' => (int) ($bytes * 1024 * 1024),
            'k' => (int) ($bytes * 1024),
            default => (int) $bytes,
        };
    }
}
