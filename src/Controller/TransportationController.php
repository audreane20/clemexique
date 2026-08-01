<?php

namespace App\Controller;

use App\Helper\Locale;
use App\Helper\Translator;
use App\Model\TransportationModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TransportationController
{
    private const FLASH_KEY = 'admin_content_flash';

    public function __construct(
        private TransportationModel $transportationModel,
        private Twig $twig,
        private string $basePath,
        private Translator $translator
    ) {
    }

    public function publicIndex(Request $request, Response $response): Response
    {
        $language = $this->currentLanguage($request);
        $categories = [];

        try {
            $categories = $this->transportationModel->findAllByLanguage($language);
        } catch (\Throwable) {
            $categories = [];
        }

        return $this->twig->render($response, 'transportation.html.twig', [
            'page_title' => $this->translator->trans('transportation.page_title'),
            'title_text' => $this->translator->trans('transportation.title'),
            'hero_text' => $this->translator->trans('transportation.hero'),
            'intro_text' => $this->translator->trans('transportation.intro'),
            'section_title' => $this->translator->trans('transportation.section_title'),
            'section_copy' => $this->translator->trans('transportation.section_copy'),
            'transport_categories' => $categories,
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
        $sectionConfig = $this->sectionConfig();
        $categories = [];
        $editingCategory = null;
        $editingItem = null;

        try {
            $categories = $this->transportationModel->findAllByLanguage($language);
            $editingCategory = $editingCategoryOnlyIndex !== null
                ? $this->transportationModel->findCategoryByIndex($language, $editingCategoryOnlyIndex)
                : null;
            $editingItem = $editingCategoryIndex !== null && $editingItemIndex !== null
                ? $this->transportationModel->findItemByIndexes($language, $editingCategoryIndex, $editingItemIndex)
                : null;
        } catch (\Throwable $exception) {
            $flash['error'] = $flash['error'] ?? $exception->getMessage();
        }

        return $this->twig->render($response, 'admin/transportation.html.twig', [
            'page_title_key' => $sectionConfig['page_title'],
            'section_config' => $sectionConfig,
            'active_section' => 'transportation',
            'categories' => $categories,
            'editor_language' => $language,
            'editing_category' => $editingCategory,
            'editing_category_index' => $editingCategoryOnlyIndex,
            'editing_item' => $editingItem,
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
            $this->transportationModel->createItem($language, $data);
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
            $this->transportationModel->updateItem($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $data);
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
        $deleteScope = (string) ($data['delete_scope'] ?? 'all');

        try {
            $this->transportationModel->deleteItemByIndexes($language, (int) $args['categoryIndex'], (int) $args['itemIndex'], $deleteScope);
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
            $this->transportationModel->deleteCategoryByIndex($language, (int) $args['categoryIndex']);
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
            $this->transportationModel->updateCategoryByIndex($language, (int) $args['categoryIndex'], $data);
            $_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_saved', $language)];
        } catch (\InvalidArgumentException $exception) {
            $_SESSION[self::FLASH_KEY] = ['error' => $exception->getMessage()];
        }

        return $this->redirect($response, $this->buildAdminUrl($language));
    }

    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.transportation.page_title',
            'page_copy' => 'admin_content.sections.transportation.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.transportation.category_title_placeholder',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.name', 'placeholder_key' => 'admin_content.fields.name'],
                ['name' => 'url', 'label_key' => 'admin_content.fields.website', 'placeholder_key' => 'admin_content.fields.url_placeholder'],
                ['name' => 'description', 'label_key' => 'admin_content.fields.description', 'placeholder_key' => 'admin_content.fields.optional_description_placeholder', 'type' => 'textarea'],
            ],
            'required_item_fields' => ['name', 'url'],
            'optional_item_fields' => ['description'],
        ];
    }

    private function categoryIconChoices(): array
    {
        return [];
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

        return $this->basePath . '/admin/content/transportation?' . http_build_query($query);
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
}
