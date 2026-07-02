<?php

namespace App\Controller;

use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\ExcursionModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class ExcursionController
{
    private const FLASH_KEY = 'admin_content_flash';

    public function __construct(
        private ExcursionModel $excursionModel,
        private Twig $twig,
        private string $basePath,
        private Translator $translator
    ) {
    }

    public function publicIndex(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'excursions.html.twig', [
            'page_title' => $this->translator->trans('excursions.page_title'),
            'title_text' => $this->translator->trans('excursions.title'),
            'hero_text' => $this->translator->trans('excursions.hero'),
            'intro_text' => $this->translator->trans('excursions.intro'),
            'section_title' => $this->translator->trans('excursions.section_title'),
            'section_copy' => $this->translator->trans('excursions.section_copy'),
            'discount_note' => $this->translator->trans('excursions.discount_note'),
            'redirect_cta' => $this->translator->trans('excursions.redirect_cta'),
        ]);
    }

    public function adminIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);
        $query = $request->getQueryParams();
        $editingCategoryOnlyIndex = isset($query['edit_category']) ? (int) $query['edit_category'] : null;
        $editingCategoryIndex = isset($query['edit_item_category']) ? (int) $query['edit_item_category'] : null;
        $editingItemIndex = isset($query['edit_item']) ? (int) $query['edit_item'] : null;
        $flash = $this->consumeFlash();
        $categories = $this->localizeCategoryTitles(
            $this->excursionModel->findAllByLanguage($language),
            $language
        );

        return $this->twig->render($response, 'admin/excursions.html.twig', [
            'page_title' => $this->sectionConfig()['page_title'][$language],
            'section_config' => $this->sectionConfig(),
            'active_section' => 'excursions',
            'categories' => $categories,
            'editor_language' => $language,
            'editing_category' => $editingCategoryOnlyIndex !== null
                ? $this->excursionModel->findCategoryByIndex($language, $editingCategoryOnlyIndex)
                : null,
            'editing_category_index' => $editingCategoryOnlyIndex,
            'editing_item' => $editingCategoryIndex !== null && $editingItemIndex !== null
                ? $this->excursionModel->findItemByIndexes($language, $editingCategoryIndex, $editingItemIndex)
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
            $this->excursionModel->createItem($language, $data);
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
            $this->excursionModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
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
            $this->excursionModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);
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
            $this->excursionModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
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
            $this->excursionModel->updateCategoryByIndex($language, (int) $args['categoryIndex'], $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_saved', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    private function sectionConfig(): array
    {
        return [
            'page_title' => [
                'en' => 'Excursion management',
                'fr' => 'Gestion des excursions',
                'es' => 'Gestion de excursiones',
            ],
            'page_copy' => [
                'en' => 'Add, edit, or remove the categories and items on the excursions page.',
                'fr' => 'Ajoutez, modifiez ou supprimez les categories et les elements sur la page excursions.',
                'es' => 'Agrega, edita o elimina las categorias y elementos de la pagina de excursiones.',
            ],
            'category_fields' => [
                [
                    'name' => 'title',
                    'labels' => [
                        'en' => 'Category title',
                        'fr' => 'Titre de la categorie',
                        'es' => 'Titulo de la categoria',
                    ],
                    'placeholders' => [
                        'en' => 'Ex. Boat and sea excursions',
                        'fr' => 'Ex. Excursions en mer',
                        'es' => 'Ej. Excursiones en barco y mar',
                    ],
                ],
                [
                    'name' => 'flag',
                    'labels' => [
                        'en' => 'Flag / icon code',
                        'fr' => 'Code du drapeau / icone',
                        'es' => 'Codigo de bandera / icono',
                    ],
                    'placeholders' => [
                        'en' => 'Optional',
                        'fr' => 'Optionnel',
                        'es' => 'Opcional',
                    ],
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'labels' => ['en' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'], 'placeholders' => ['en' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre']],
                ['name' => 'area', 'labels' => ['en' => 'Address', 'fr' => 'Adresse', 'es' => 'Direccion'], 'placeholders' => ['en' => 'Playa del Carmen', 'fr' => 'Playa del Carmen', 'es' => 'Playa del Carmen']],
                ['name' => 'url', 'labels' => ['en' => 'Website', 'fr' => 'Site web', 'es' => 'Sitio web'], 'placeholders' => ['en' => 'https://...', 'fr' => 'https://...', 'es' => 'https://...']],
                ['name' => 'note', 'labels' => ['en' => 'Note', 'fr' => 'Note', 'es' => 'Nota'], 'placeholders' => ['en' => 'Short note', 'fr' => 'Description courte', 'es' => 'Nota breve']],
                ['name' => 'video_url', 'labels' => ['en' => 'Video link', 'fr' => 'Lien video', 'es' => 'Enlace de video'], 'placeholders' => ['en' => 'https://youtube.com/...', 'fr' => 'https://youtube.com/...', 'es' => 'https://youtube.com/...']],
            ],
        ];
    }

    private function categoryIconChoices(): array
    {
        return [
            ['code' => 'IT', 'name' => 'Italie'],
            ['code' => 'FR', 'name' => 'France'],
            ['code' => 'IN', 'name' => 'Inde'],
            ['code' => 'TH', 'name' => 'Thailande'],
            ['code' => 'CA-QC', 'name' => 'Quebec'],
            ['code' => 'CA', 'name' => 'Canada'],
            ['code' => 'MX', 'name' => 'Mexique'],
            ['code' => 'JM', 'name' => 'Jamaique'],
            ['code' => 'MUSIC', 'name' => 'Musique / nightlife'],
            ['code' => 'SEA', 'name' => 'Fruits de mer'],
            ['code' => 'GRILL', 'name' => 'Rotisserie'],
            ['code' => 'STEAK', 'name' => 'Grill'],
            ['code' => 'LUXE', 'name' => 'Fine dining'],
            ['code' => 'PASTA', 'name' => 'Pates'],
            ['code' => 'BAKERY', 'name' => 'Boulangerie'],
            ['code' => 'COFFEE', 'name' => 'Cafe'],
            ['code' => 'COCKTAIL', 'name' => 'Cocktail'],
            ['code' => 'TACO', 'name' => 'Tacos'],
            ['code' => 'SPICE', 'name' => 'Epice'],
            ['code' => 'ROOFTOP', 'name' => 'Rooftop'],
            ['code' => 'BEACH', 'name' => 'Plage'],
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

        return $this->basePath . '/admin/content/excursions?' . http_build_query($query);
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

    public function notFound(): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write('Not found');

        return $response->withStatus(404);
    }

    private function successMessage(string $key, string $language): string
    {
        $messages = [
            'item_saved' => [
                'en' => 'Item saved.',
                'fr' => 'Element enregistre.',
                'es' => 'Elemento guardado.',
            ],
            'item_deleted' => [
                'en' => 'Item deleted.',
                'fr' => 'Element supprime.',
                'es' => 'Elemento eliminado.',
            ],
            'category_deleted' => [
                'en' => 'Category deleted.',
                'fr' => 'Categorie supprimee.',
                'es' => 'Categoria eliminada.',
            ],
            'category_saved' => [
                'en' => 'Category saved.',
                'fr' => 'Categorie enregistree.',
                'es' => 'Categoria guardada.',
            ],
        ];

        $language = Locale::normalize($language);

        return $messages[$key][$language] ?? $messages[$key]['en'] ?? $key;
    }

    private function localizeCategoryTitles(array $categories, string $language): array
    {
        if (Locale::normalize($language) !== 'es') {
            return $categories;
        }

        $translations = [
            'Boat and sea excursions' => 'Excursiones en barco y mar',
        ];

        foreach ($categories as &$category) {
            $title = trim((string) ($category['title'] ?? ''));

            if (isset($translations[$title])) {
                $category['title'] = $translations[$title];
            }
        }

        unset($category);

        return $categories;
    }
}
