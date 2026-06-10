<?php

namespace App\Controller;

use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\VideoCapsuleModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VideoCapsuleController
{
    private const FLASH_KEY = 'admin_content_flash';

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
            $this->videoCapsuleModel->createItem($language, $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('item_saved', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);

        try {
            $this->videoCapsuleModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('item_saved', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        $language = $this->postedLanguage($data);

        try {
            $this->videoCapsuleModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);
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

        try {
            $this->videoCapsuleModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
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
                [
                    'name' => 'flag',
                    'labels' => [
                        'en' => 'Flag / icon code',
                        'fr' => 'Code du drapeau / icône',
                        'es' => 'Código de bandera / icono',
                    ],
                    'placeholders' => [
                        'en' => 'Optional',
                        'fr' => 'Optionnel',
                        'es' => 'Opcional',
                    ],
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'labels' => ['en' => 'Video title', 'fr' => 'Titre de la vidéo', 'es' => 'Título del video'], 'placeholders' => ['en' => 'Video title', 'fr' => 'Titre de la vidéo', 'es' => 'Título del video']],
                ['name' => 'video_url', 'labels' => ['en' => 'Video link', 'fr' => 'Lien vidéo', 'es' => 'Enlace del video'], 'placeholders' => ['en' => 'https://youtube.com/...', 'fr' => 'https://youtube.com/...', 'es' => 'https://youtube.com/...']],
                ['name' => 'note', 'labels' => ['en' => 'Description', 'fr' => 'Description', 'es' => 'Descripción'], 'placeholders' => ['en' => 'Short description', 'fr' => 'Description courte', 'es' => 'Descripción breve']],
            ],
            'required_item_fields' => ['name', 'video_url'],
            'optional_item_fields' => ['note'],
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
}
