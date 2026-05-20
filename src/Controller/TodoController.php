<?php

namespace App\Controller;

use App\Helper\Translator;
use App\Model\TodoModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class TodoController
{
    private const FLASH_KEY = 'admin_content_flash';

    public function __construct(
        private TodoModel $todoModel,
        private Twig $twig,
        private string $basePath,
        private Translator $translator
    ) {
    }

    public function publicIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);

        return $this->twig->render($response, 'todo.html.twig', [
            'page_title' => $this->translator->trans('playa_guide.page_title'),
            'title_text' => $this->translator->trans('playa_guide.title'),
            'hero_text' => $this->translator->trans('playa_guide.hero'),
            'intro_text' => $this->translator->trans('playa_guide.intro'),
            'section_title' => $this->translator->trans('playa_guide.section_title'),
            'section_copy' => $this->translator->trans('playa_guide.section_copy'),
            'toc_eyebrow' => $this->translator->trans('playa_guide.toc_eyebrow'),
            'toc_title' => $this->translator->trans('playa_guide.toc_title'),
            'toc_copy' => $this->translator->trans('playa_guide.toc_copy'),
            'contact_cta' => $this->translator->trans('playa_guide.contact_cta'),
            'guide_categories' => $this->todoModel->findAllByLanguage($language),
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
            'active_section' => 'playa_guide',
            'categories' => $this->todoModel->findAllByLanguage($language),
            'editor_language' => $language,
            'editing_item' => $editingCategoryIndex !== null && $editingItemIndex !== null
                ? $this->todoModel->findItemByIndexes($language, $editingCategoryIndex, $editingItemIndex)
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
            $this->todoModel->createItem($language, $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $language === 'en' ? 'Item saved.' : 'Élément enregistré.'];
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
            $this->todoModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $language === 'en' ? 'Item saved.' : 'Élément enregistré.'];
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
            $this->todoModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex']);
            $_SESSION[self::FLASH_KEY] = ['success' => $language === 'en' ? 'Item deleted.' : 'Élément supprimé.'];
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
            $this->todoModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
            $_SESSION[self::FLASH_KEY] = ['success' => $language === 'en' ? 'Category deleted.' : 'Catégorie supprimée.'];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    private function sectionConfig(): array
    {
        return [
            'page_title' => ['en' => 'Things to do management', 'fr' => 'Gestion de Quoi faire à Playa'],
            'page_copy' => [
                'en' => 'Add, edit, or remove the categories and items on the things-to-do page.',
                'fr' => 'Ajoutez, modifiez ou supprimez les catégories et les éléments sur la page Quoi faire à Playa.',
            ],
            'category_fields' => [
                [
                    'name' => 'title',
                    'labels' => ['en' => 'Category title', 'fr' => 'Titre de la catégorie'],
                    'placeholders' => ['en' => 'Ex. Shows and signature experiences', 'fr' => 'Ex. Spectacles et expériences'],
                ],
                [
                    'name' => 'flag',
                    'labels' => ['en' => 'Flag / icon code', 'fr' => 'Code du drapeau / icône'],
                    'placeholders' => ['en' => 'Optional', 'fr' => 'Optionnel'],
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'labels' => ['en' => 'Name', 'fr' => 'Nom'], 'placeholders' => ['en' => 'Name', 'fr' => 'Nom']],
                ['name' => 'area', 'labels' => ['en' => 'Address', 'fr' => 'Adresse'], 'placeholders' => ['en' => 'Playa del Carmen', 'fr' => 'Playa del Carmen']],
                ['name' => 'url', 'labels' => ['en' => 'Website', 'fr' => 'Site web'], 'placeholders' => ['en' => 'https://...', 'fr' => 'https://...']],
                ['name' => 'note', 'labels' => ['en' => 'Note', 'fr' => 'Note'], 'placeholders' => ['en' => 'Short note', 'fr' => 'Description courte']],
                ['name' => 'video_url', 'labels' => ['en' => 'Video link', 'fr' => 'Lien vidéo'], 'placeholders' => ['en' => 'https://youtube.com/...', 'fr' => 'https://youtube.com/...']],
            ],
        ];
    }

    private function categoryIconChoices(): array
    {
        return [
            ['code' => 'IT', 'name' => 'Italie'],
            ['code' => 'FR', 'name' => 'France'],
            ['code' => 'IN', 'name' => 'Inde'],
            ['code' => 'TH', 'name' => 'Thaïlande'],
            ['code' => 'CA-QC', 'name' => 'Québec'],
            ['code' => 'CA', 'name' => 'Canada'],
            ['code' => 'MX', 'name' => 'Mexique'],
            ['code' => 'JM', 'name' => 'Jamaïque'],
            ['code' => 'MUSIC', 'name' => 'Musique / nightlife'],
            ['code' => 'SEA', 'name' => 'Fruits de mer'],
            ['code' => 'GRILL', 'name' => 'Rotisserie'],
            ['code' => 'STEAK', 'name' => 'Grill'],
            ['code' => 'LUXE', 'name' => 'Fine dining'],
            ['code' => 'PASTA', 'name' => 'Pâtes'],
            ['code' => 'BAKERY', 'name' => 'Boulangerie'],
            ['code' => 'COFFEE', 'name' => 'Café'],
            ['code' => 'COCKTAIL', 'name' => 'Cocktail'],
            ['code' => 'TACO', 'name' => 'Tacos'],
            ['code' => 'SPICE', 'name' => 'Épicé'],
            ['code' => 'ROOFTOP', 'name' => 'Rooftop'],
            ['code' => 'BEACH', 'name' => 'Plage'],
        ];
    }

    private function currentLanguage(Request $request): string
    {
        $query = $request->getQueryParams();

        return in_array(($query['lang'] ?? $_SESSION['lang'] ?? 'fr'), ['fr', 'en'], true)
            ? (string) ($query['lang'] ?? $_SESSION['lang'] ?? 'fr')
            : 'fr';
    }

    private function postedLanguage(array $data): string
    {
        return in_array(($data['editor_language'] ?? ''), ['fr', 'en'], true) ? (string) $data['editor_language'] : 'fr';
    }

    private function buildAdminUrl(string $language, array $query = []): string
    {
        $language = in_array($language, ['fr', 'en'], true) ? $language : 'fr';
        $query = array_filter(array_merge(['lang' => $language], $query), static fn ($value) => $value !== null && $value !== '');

        return $this->basePath . '/admin/content/playa_guide?' . http_build_query($query);
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
}
