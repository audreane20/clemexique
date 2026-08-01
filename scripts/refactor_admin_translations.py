from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]


def update_todo_template() -> None:
    path = root / "templates/admin/todo.html.twig"
    text = path.read_text(encoding="utf-8")

    start = text.index("{% set item_label = active_section == 'restaurants'")
    end = text.index("{% set required_item_fields = section_config.required_item_fields is defined")
    new_block = """{% set item_label_key = active_section == 'restaurants'
    ? 'admin_content.item_label.restaurant'
    : active_section == 'excursions'
        ? 'admin_content.item_label.excursion'
        : active_section == 'video_capsules'
            ? 'admin_content.item_label.video_capsule'
        : active_section == 'activities'
            ? 'admin_content.item_label.sports_activity'
        : 'admin_content.item_label.activity' %}
{% set current_item_label = trans(item_label_key) %}
{% set add_title = trans('admin_content.sections.' ~ active_section ~ '.add_title') %}
{% set ui = {
    'eyebrow': trans('admin_content.ui.eyebrow'),
    'edit': trans('admin_content.ui.edit'),
    'editing_copy': trans('admin_content.ui.editing_copy'),
    'create_copy': trans('admin_content.ui.create_copy'),
    'cancel': trans('admin_content.ui.cancel'),
    'category': trans('admin_content.ui.category'),
    'select_category': trans('admin_content.ui.select_category'),
    'new_category': trans('admin_content.ui.new_category'),
    'new_category_copy': trans('admin_content.ui.new_category_copy'),
    'search_icon': trans('admin_content.ui.search_icon'),
    'no_matching_icons': trans('admin_content.ui.no_matching_icons'),
    'preview': trans('admin_content.ui.preview'),
    'no_icon': trans('admin_content.ui.no_icon'),
    'unknown_code': trans('admin_content.ui.unknown_code'),
    'optional': trans('admin_content.ui.optional'),
    'select_price': trans('admin_content.ui.select_price'),
    'favorite_pick': trans('admin_content.ui.favorite_pick'),
    'select_reference': trans('admin_content.ui.select_reference'),
    'no_reference': trans('admin_content.ui.no_reference'),
    'save': trans('admin_content.ui.save'),
    'current_sections': trans('admin_content.ui.current_sections'),
    'categories': trans('admin_content.ui.categories'),
    'no_categories': trans('admin_content.ui.no_categories'),
    'items': trans('admin_content.ui.items'),
    'delete_category_confirm': trans('admin_content.ui.delete_category_confirm'),
    'delete': trans('admin_content.ui.delete'),
    'empty_restaurants': trans('admin_content.ui.empty_restaurants'),
    'empty_items': trans('admin_content.ui.empty_items'),
    'delete_item_confirm': trans('admin_content.ui.delete_item_confirm'),
    'media_upload_label': trans('admin_content.ui.media_upload_label'),
    'media_upload_help': trans('admin_content.ui.media_upload_help'),
    'video_upload_label': trans('admin_content.ui.video_upload_label'),
    'video_upload_help': trans('admin_content.ui.video_upload_help'),
    'current_video': trans('admin_content.ui.current_video'),
    'open_video': trans('admin_content.ui.open_video'),
    'open_video_link': trans('admin_content.ui.open_video_link')
} %}
{% set submit_add_label = add_title %}
{% set submit_save_label = ui.save|format(current_item_label) %}
"""
    text = text[:start] + new_block + text[end:]

    replacements = {
        "ui[app_lang].": "ui.",
        "field.labels[app_lang]": "trans(field.label_key)",
        "field.placeholders[app_lang]": "trans(field.placeholder_key)",
        "section_config.page_title[app_lang]": "trans(section_config.page_title)",
        "section_config.page_copy[app_lang]": "trans(section_config.page_copy)",
        "{{ app_lang == 'fr' ? 'Photos et vidéos' : (app_lang == 'es' ? 'Fotos y videos' : 'Pictures and/or videos') }}": "{{ ui.media_upload_label }}",
        "{{ app_lang == 'fr' ? 'Fichier vidéo' : (app_lang == 'es' ? 'Archivo de video' : 'Video file') }}": "{{ ui.video_upload_label }}",
        "{{ app_lang == 'fr' ? 'Ouvrir la vidéo' : (app_lang == 'es' ? 'Abrir video' : 'Open video') }}": "{{ ui.open_video }}",
        "{{ app_lang == 'fr' ? 'Ouvrir le lien vidéo' : (app_lang == 'es' ? 'Abrir enlace del video' : 'Open video link') }}": "{{ ui.open_video_link }}",
    }
    for old, new in replacements.items():
        text = text.replace(old, new)

    text = text.replace(
        "{{ app_lang == 'fr'\n                                ? 'Ajoutez une ou plusieurs photos ou vidéos depuis votre appareil.'\n                                : (app_lang == 'es'\n                                    ? 'Agrega una o varias fotos o videos desde tu dispositivo.'\n                                    : 'Add one or more photos or videos from your device.') }}",
        "{{ ui.media_upload_help }}",
    )
    text = text.replace(
        "{{ app_lang == 'fr'\n                                ? 'Vous pouvez téléverser une vidéo depuis le téléphone, la caméra ou le dossier Downloads.'\n                                : (app_lang == 'es'\n                                    ? 'Puedes subir un video desde el teléfono, la cámara o la carpeta Downloads.'\n                                    : 'You can upload a video from a phone, camera roll, or the Downloads folder.') }}",
        "{{ ui.video_upload_help }}",
    )
    text = text.replace(
        "{{ app_lang == 'fr'\n                                    ? 'Vidéo actuelle :'\n                                    : (app_lang == 'es' ? 'Video actual:' : 'Current video:') }}",
        "{{ ui.current_video }}",
    )

    path.write_text(text, encoding="utf-8", newline="")


def controller_section_configs():
    return {
        "src/Controller/RestaurantController.php": """    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.restaurants.page_title',
            'page_copy' => 'admin_content.sections.restaurants.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.restaurants.category_title_placeholder',
                ],
                [
                    'name' => 'flag',
                    'label_key' => 'admin_content.fields.flag_code',
                    'placeholder_key' => 'admin_content.sections.restaurants.flag_placeholder',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.name', 'placeholder_key' => 'admin_content.sections.restaurants.name_placeholder'],
                ['name' => 'area', 'label_key' => 'admin_content.fields.address', 'placeholder_key' => 'admin_content.sections.restaurants.area_placeholder'],
                ['name' => 'price', 'label_key' => 'admin_content.fields.price', 'placeholder_key' => 'admin_content.fields.empty_placeholder'],
                ['name' => 'url', 'label_key' => 'admin_content.fields.website', 'placeholder_key' => 'admin_content.sections.restaurants.url_placeholder'],
                ['name' => 'reference', 'label_key' => 'admin_content.fields.reference', 'placeholder_key' => 'admin_content.fields.empty_placeholder'],
            ],
        ];
    }
""",
        "src/Controller/TodoController.php": """    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.playa_guide.page_title',
            'page_copy' => 'admin_content.sections.playa_guide.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.playa_guide.category_title_placeholder',
                ],
                [
                    'name' => 'flag',
                    'label_key' => 'admin_content.fields.flag_code',
                    'placeholder_key' => 'admin_content.ui.optional',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.name', 'placeholder_key' => 'admin_content.fields.name'],
                ['name' => 'area', 'label_key' => 'admin_content.fields.address', 'placeholder_key' => 'admin_content.sections.common.playa_placeholder'],
                ['name' => 'url', 'label_key' => 'admin_content.fields.website', 'placeholder_key' => 'admin_content.fields.url_placeholder'],
                ['name' => 'note', 'label_key' => 'admin_content.fields.note', 'placeholder_key' => 'admin_content.fields.short_note_placeholder'],
                ['name' => 'video_url', 'label_key' => 'admin_content.fields.video_link', 'placeholder_key' => 'admin_content.fields.video_url_placeholder'],
            ],
            'required_item_fields' => ['name', 'area'],
            'optional_item_fields' => ['url', 'note', 'video_url'],
        ];
    }
""",
        "src/Controller/ExcursionController.php": """    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.excursions.page_title',
            'page_copy' => 'admin_content.sections.excursions.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.excursions.category_title_placeholder',
                ],
                [
                    'name' => 'flag',
                    'label_key' => 'admin_content.fields.flag_code',
                    'placeholder_key' => 'admin_content.ui.optional',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.name', 'placeholder_key' => 'admin_content.fields.name'],
                ['name' => 'area', 'label_key' => 'admin_content.fields.address', 'placeholder_key' => 'admin_content.sections.common.playa_placeholder'],
                ['name' => 'url', 'label_key' => 'admin_content.fields.website', 'placeholder_key' => 'admin_content.fields.url_placeholder'],
                ['name' => 'note', 'label_key' => 'admin_content.fields.note', 'placeholder_key' => 'admin_content.fields.short_note_placeholder'],
                ['name' => 'video_url', 'label_key' => 'admin_content.fields.video_link', 'placeholder_key' => 'admin_content.fields.video_url_placeholder'],
            ],
        ];
    }
""",
        "src/Controller/ActivityController.php": """    private function sectionConfig(): array
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
""",
        "src/Controller/VideoCapsuleController.php": """    private function sectionConfig(): array
    {
        return [
            'page_title' => 'admin_content.sections.video_capsules.page_title',
            'page_copy' => 'admin_content.sections.video_capsules.page_copy',
            'category_fields' => [
                [
                    'name' => 'title',
                    'label_key' => 'admin_content.fields.category_title',
                    'placeholder_key' => 'admin_content.sections.video_capsules.category_title_placeholder',
                ],
            ],
            'item_fields' => [
                ['name' => 'name', 'label_key' => 'admin_content.fields.video_title', 'placeholder_key' => 'admin_content.fields.video_title'],
                ['name' => 'video_url', 'label_key' => 'admin_content.fields.video_link', 'placeholder_key' => 'admin_content.fields.video_url_placeholder'],
                ['name' => 'note', 'label_key' => 'admin_content.fields.description', 'placeholder_key' => 'admin_content.fields.short_description_placeholder'],
            ],
            'required_item_fields' => ['name'],
            'optional_item_fields' => ['video_url', 'note'],
            'show_video_upload' => true,
        ];
    }
""",
    }


def update_controllers() -> None:
    for rel, section_config in controller_section_configs().items():
        path = root / rel
        text = path.read_text(encoding="utf-8")
        text = re.sub(r"\$flash = \$this->consumeFlash\(\);", "$flash = $this->consumeFlash();\n        $sectionConfig = $this->sectionConfig();", text, count=1)
        text = text.replace("'page_title' => $this->sectionConfig()['page_title'][$language],", "'page_title_key' => $sectionConfig['page_title'],")
        text = text.replace("'section_config' => $this->sectionConfig(),", "'section_config' => $sectionConfig,")
        text = re.sub(
            r"    private function sectionConfig\(\): array\n    \{.*?\n    \}\n\n    private function categoryIconChoices",
            section_config + "\n    private function categoryIconChoices",
            text,
            flags=re.S,
        )
        text = re.sub(
            r"    private function successMessage\(string \$key, string \$language\): string\n    \{.*?\n    \}\n",
            "    private function successMessage(string $key, string $language): string\n    {\n        return (new Translator($language))->trans('admin_content.flash.' . $key);\n    }\n",
            text,
            flags=re.S,
        )
        if rel.endswith("VideoCapsuleController.php"):
            text = re.sub(
                r"\$_SESSION\[self::FLASH_KEY\] = \['success' => \[.*?\]\[Locale::normalize\(\$language\)\] \?\? 'Category saved\.'\];",
                "$_SESSION[self::FLASH_KEY] = ['success' => $this->successMessage('category_saved', $language)];",
                text,
                flags=re.S,
            )
        path.write_text(text, encoding="utf-8", newline="")


def append_translation_block(path_str: str, block: str) -> None:
    path = root / path_str
    text = path.read_text(encoding="utf-8")
    if "admin_content.ui.eyebrow" in text:
        return
    stripped = text.rstrip()
    if stripped.endswith("];"):
        text = stripped[:-2] + block + "\n];\n"
    elif stripped.endswith(");"):
        text = stripped[:-2] + block + "\n);\n"
    path.write_text(text, encoding="utf-8", newline="")


TRANSLATIONS = {
    "src/translations/messages.en.php": """
    'admin_content.ui.eyebrow' => 'Admin',
    'admin_content.ui.edit' => 'Edit',
    'admin_content.ui.editing_copy' => 'The selected %s is loaded here for editing.',
    'admin_content.ui.create_copy' => 'Choose an existing category or create a new one directly from this form.',
    'admin_content.ui.cancel' => 'Cancel',
    'admin_content.ui.category' => 'Category',
    'admin_content.ui.select_category' => 'Select a category',
    'admin_content.ui.new_category' => 'New category',
    'admin_content.ui.new_category_copy' => 'This category will be created when you save the %s.',
    'admin_content.ui.search_icon' => 'Search by name or code',
    'admin_content.ui.no_matching_icons' => 'No matching icons.',
    'admin_content.ui.preview' => 'Preview:',
    'admin_content.ui.no_icon' => 'No icon selected yet',
    'admin_content.ui.unknown_code' => 'Unknown code',
    'admin_content.ui.optional' => 'optional',
    'admin_content.ui.select_price' => 'Select a price range',
    'admin_content.ui.favorite_pick' => 'Favorite pick',
    'admin_content.ui.select_reference' => 'Select a reference',
    'admin_content.ui.no_reference' => 'No reference',
    'admin_content.ui.save' => 'Save %s',
    'admin_content.ui.current_sections' => 'Current sections',
    'admin_content.ui.categories' => 'categories',
    'admin_content.ui.no_categories' => 'No categories yet.',
    'admin_content.ui.items' => 'items',
    'admin_content.ui.delete_category_confirm' => 'Delete this category?',
    'admin_content.ui.delete_item_confirm' => 'Delete this item?',
    'admin_content.ui.delete' => 'Delete',
    'admin_content.ui.empty_restaurants' => 'No restaurants in this category yet.',
    'admin_content.ui.empty_items' => 'No items in this category yet.',
    'admin_content.ui.media_upload_label' => 'Pictures and/or videos',
    'admin_content.ui.media_upload_help' => 'Add one or more photos or videos from your device.',
    'admin_content.ui.video_upload_label' => 'Video file',
    'admin_content.ui.video_upload_help' => 'You can upload a video from a phone, camera roll, or the Downloads folder.',
    'admin_content.ui.current_video' => 'Current video:',
    'admin_content.ui.open_video' => 'Open video',
    'admin_content.ui.open_video_link' => 'Open video link',
    'admin_content.item_label.restaurant' => 'restaurant',
    'admin_content.item_label.excursion' => 'excursion',
    'admin_content.item_label.activity' => 'activity',
    'admin_content.item_label.sports_activity' => 'sports activity',
    'admin_content.item_label.video_capsule' => 'video capsule',
    'admin_content.fields.category_title' => 'Category title',
    'admin_content.fields.flag_code' => 'Flag / icon code',
    'admin_content.fields.name' => 'Name',
    'admin_content.fields.address' => 'Address',
    'admin_content.fields.price' => 'Price',
    'admin_content.fields.website' => 'Website',
    'admin_content.fields.reference' => 'Reference',
    'admin_content.fields.note' => 'Note',
    'admin_content.fields.video_link' => 'Video link',
    'admin_content.fields.video_title' => 'Video title',
    'admin_content.fields.activity_name' => 'Activity name',
    'admin_content.fields.location' => 'Location',
    'admin_content.fields.description' => 'Description',
    'admin_content.fields.empty_placeholder' => '',
    'admin_content.fields.url_placeholder' => 'https://...',
    'admin_content.fields.video_url_placeholder' => 'https://youtube.com/...',
    'admin_content.fields.short_note_placeholder' => 'Short note',
    'admin_content.fields.short_description_placeholder' => 'Short description',
    'admin_content.fields.optional_description_placeholder' => 'Optional description',
    'admin_content.sections.common.playa_placeholder' => 'Playa del Carmen',
    'admin_content.sections.restaurants.page_title' => 'Restaurant management',
    'admin_content.sections.restaurants.page_copy' => 'Add, edit, or remove the categories and restaurants shown on the public page.',
    'admin_content.sections.restaurants.add_title' => 'Add a restaurant',
    'admin_content.sections.restaurants.category_title_placeholder' => 'Ex. Italian',
    'admin_content.sections.restaurants.flag_placeholder' => 'IT, FR, MX, MUSIC...',
    'admin_content.sections.restaurants.name_placeholder' => 'Restaurant name',
    'admin_content.sections.restaurants.area_placeholder' => '5th Avenue',
    'admin_content.sections.restaurants.url_placeholder' => 'name.com',
    'admin_content.sections.excursions.page_title' => 'Excursion management',
    'admin_content.sections.excursions.page_copy' => 'Add, edit, or remove the categories and items on the excursions page.',
    'admin_content.sections.excursions.add_title' => 'Add an excursion',
    'admin_content.sections.excursions.category_title_placeholder' => 'Ex. Boat and sea excursions',
    'admin_content.sections.playa_guide.page_title' => 'Things to do management',
    'admin_content.sections.playa_guide.page_copy' => 'Add, edit, or remove the categories and items on the things-to-do page.',
    'admin_content.sections.playa_guide.add_title' => 'Add an activity',
    'admin_content.sections.playa_guide.category_title_placeholder' => 'Ex. Shows and signature experiences',
    'admin_content.sections.activities.page_title' => 'Sports activities management',
    'admin_content.sections.activities.page_copy' => 'Add, edit, or remove sports activity categories and activities.',
    'admin_content.sections.activities.add_title' => 'Add a sports activity',
    'admin_content.sections.activities.category_title_placeholder' => 'Ex. Water sports',
    'admin_content.sections.video_capsules.page_title' => 'Video capsules management',
    'admin_content.sections.video_capsules.page_copy' => 'Add, edit, or remove video categories and video capsules.',
    'admin_content.sections.video_capsules.add_title' => 'Add a video capsule',
    'admin_content.sections.video_capsules.category_title_placeholder' => 'Ex. Beaches and lifestyle',
    'admin_content.flash.item_saved' => 'Item saved.',
    'admin_content.flash.item_deleted' => 'Item deleted.',
    'admin_content.flash.category_saved' => 'Category saved.',
    'admin_content.flash.category_deleted' => 'Category deleted.',
""",
    "src/translations/messages.fr.php": """
    'admin_content.ui.eyebrow' => 'Admin',
    'admin_content.ui.edit' => 'Modifier',
    'admin_content.ui.editing_copy' => 'L’élément sélectionné est chargé ici pour modification.',
    'admin_content.ui.create_copy' => 'Choisissez une catégorie existante ou créez-en une nouvelle directement depuis ce formulaire.',
    'admin_content.ui.cancel' => 'Annuler',
    'admin_content.ui.category' => 'Catégorie',
    'admin_content.ui.select_category' => 'Sélectionnez une catégorie',
    'admin_content.ui.new_category' => 'Nouvelle catégorie',
    'admin_content.ui.new_category_copy' => 'Cette catégorie sera créée lorsque vous enregistrerez %s.',
    'admin_content.ui.search_icon' => 'Recherchez par nom ou code',
    'admin_content.ui.no_matching_icons' => 'Aucune icône correspondante.',
    'admin_content.ui.preview' => 'Aperçu :',
    'admin_content.ui.no_icon' => 'Aucune icône sélectionnée pour le moment',
    'admin_content.ui.unknown_code' => 'Code inconnu',
    'admin_content.ui.optional' => 'optionnel',
    'admin_content.ui.select_price' => 'Sélectionnez une gamme de prix',
    'admin_content.ui.favorite_pick' => 'Coup de coeur',
    'admin_content.ui.select_reference' => 'Sélectionnez une référence',
    'admin_content.ui.no_reference' => 'Aucune référence',
    'admin_content.ui.save' => 'Enregistrer %s',
    'admin_content.ui.current_sections' => 'Sections actuelles',
    'admin_content.ui.categories' => 'categories',
    'admin_content.ui.no_categories' => 'Aucune catégorie pour le moment.',
    'admin_content.ui.items' => 'éléments',
    'admin_content.ui.delete_category_confirm' => 'Supprimer cette catégorie ?',
    'admin_content.ui.delete_item_confirm' => 'Supprimer cet élément ?',
    'admin_content.ui.delete' => 'Supprimer',
    'admin_content.ui.empty_restaurants' => 'Aucun restaurant dans cette catégorie pour le moment.',
    'admin_content.ui.empty_items' => 'Aucun élément dans cette catégorie pour le moment.',
    'admin_content.ui.media_upload_label' => 'Photos et vidéos',
    'admin_content.ui.media_upload_help' => 'Ajoutez une ou plusieurs photos ou vidéos depuis votre appareil.',
    'admin_content.ui.video_upload_label' => 'Fichier vidéo',
    'admin_content.ui.video_upload_help' => 'Vous pouvez téléverser une vidéo depuis le téléphone, la caméra ou le dossier Downloads.',
    'admin_content.ui.current_video' => 'Vidéo actuelle :',
    'admin_content.ui.open_video' => 'Ouvrir la vidéo',
    'admin_content.ui.open_video_link' => 'Ouvrir le lien vidéo',
    'admin_content.item_label.restaurant' => 'restaurant',
    'admin_content.item_label.excursion' => 'excursion',
    'admin_content.item_label.activity' => 'activité',
    'admin_content.item_label.sports_activity' => 'activité sportive',
    'admin_content.item_label.video_capsule' => 'capsule vidéo',
    'admin_content.fields.category_title' => 'Titre de la catégorie',
    'admin_content.fields.flag_code' => 'Code du drapeau / icône',
    'admin_content.fields.name' => 'Nom',
    'admin_content.fields.address' => 'Adresse',
    'admin_content.fields.price' => 'Prix',
    'admin_content.fields.website' => 'Site web',
    'admin_content.fields.reference' => 'Référence',
    'admin_content.fields.note' => 'Note',
    'admin_content.fields.video_link' => 'Lien vidéo',
    'admin_content.fields.video_title' => 'Titre de la vidéo',
    'admin_content.fields.activity_name' => 'Nom de l’activité',
    'admin_content.fields.location' => 'Emplacement',
    'admin_content.fields.description' => 'Description',
    'admin_content.fields.empty_placeholder' => '',
    'admin_content.fields.url_placeholder' => 'https://...',
    'admin_content.fields.video_url_placeholder' => 'https://youtube.com/...',
    'admin_content.fields.short_note_placeholder' => 'Description courte',
    'admin_content.fields.short_description_placeholder' => 'Description courte',
    'admin_content.fields.optional_description_placeholder' => 'Description optionnelle',
    'admin_content.sections.common.playa_placeholder' => 'Playa del Carmen',
    'admin_content.sections.restaurants.page_title' => 'Gestion des restaurants',
    'admin_content.sections.restaurants.page_copy' => 'Ajoutez, modifiez ou supprimez les catégories et les restaurants affichés sur la page publique.',
    'admin_content.sections.restaurants.add_title' => 'Ajouter un restaurant',
    'admin_content.sections.restaurants.category_title_placeholder' => 'Ex. Italien',
    'admin_content.sections.restaurants.flag_placeholder' => 'IT, FR, MX, MUSIC...',
    'admin_content.sections.restaurants.name_placeholder' => 'Nom du restaurant',
    'admin_content.sections.restaurants.area_placeholder' => 'Avenue 5',
    'admin_content.sections.restaurants.url_placeholder' => 'nom.com',
    'admin_content.sections.excursions.page_title' => 'Gestion des excursions',
    'admin_content.sections.excursions.page_copy' => 'Ajoutez, modifiez ou supprimez les catégories et les éléments sur la page excursions.',
    'admin_content.sections.excursions.add_title' => 'Ajouter une excursion',
    'admin_content.sections.excursions.category_title_placeholder' => 'Ex. Excursions en mer',
    'admin_content.sections.playa_guide.page_title' => 'Gestion de Quoi faire à Playa',
    'admin_content.sections.playa_guide.page_copy' => 'Ajoutez, modifiez ou supprimez les catégories et les éléments sur la page Quoi faire à Playa.',
    'admin_content.sections.playa_guide.add_title' => 'Ajouter une activité',
    'admin_content.sections.playa_guide.category_title_placeholder' => 'Ex. Spectacles et expériences',
    'admin_content.sections.activities.page_title' => 'Gestion des activités sportive',
    'admin_content.sections.activities.page_copy' => 'Ajoutez, modifiez ou supprimez des catégories d’activités sportive et des activités.',
    'admin_content.sections.activities.add_title' => 'Ajouter une activité sportive',
    'admin_content.sections.activities.category_title_placeholder' => 'Ex. Sports nautiques',
    'admin_content.sections.video_capsules.page_title' => 'Gestion des capsules vidéo',
    'admin_content.sections.video_capsules.page_copy' => 'Ajoutez, modifiez ou supprimez des catégories et des capsules vidéo.',
    'admin_content.sections.video_capsules.add_title' => 'Ajouter une capsule vidéo',
    'admin_content.sections.video_capsules.category_title_placeholder' => 'Ex. Plages et art de vivre',
    'admin_content.flash.item_saved' => 'Élément enregistré.',
    'admin_content.flash.item_deleted' => 'Élément supprimé.',
    'admin_content.flash.category_saved' => 'Catégorie enregistrée.',
    'admin_content.flash.category_deleted' => 'Catégorie supprimée.',
""",
    "src/translations/messages.es.php": """
    'admin_content.ui.eyebrow' => 'Admin',
    'admin_content.ui.edit' => 'Editar',
    'admin_content.ui.editing_copy' => 'El elemento seleccionado está cargado aquí para editarlo.',
    'admin_content.ui.create_copy' => 'Elige una categoría existente o crea una nueva directamente desde este formulario.',
    'admin_content.ui.cancel' => 'Cancelar',
    'admin_content.ui.category' => 'Categoría',
    'admin_content.ui.select_category' => 'Selecciona una categoría',
    'admin_content.ui.new_category' => 'Nueva categoría',
    'admin_content.ui.new_category_copy' => 'Esta categoría se creará cuando guardes %s.',
    'admin_content.ui.search_icon' => 'Busca por nombre o código',
    'admin_content.ui.no_matching_icons' => 'No hay iconos coincidentes.',
    'admin_content.ui.preview' => 'Vista previa:',
    'admin_content.ui.no_icon' => 'Todavía no hay icono seleccionado',
    'admin_content.ui.unknown_code' => 'Código desconocido',
    'admin_content.ui.optional' => 'opcional',
    'admin_content.ui.select_price' => 'Selecciona un rango de precio',
    'admin_content.ui.favorite_pick' => 'Favorito',
    'admin_content.ui.select_reference' => 'Selecciona una referencia',
    'admin_content.ui.no_reference' => 'Sin referencia',
    'admin_content.ui.save' => 'Guardar %s',
    'admin_content.ui.current_sections' => 'Secciones actuales',
    'admin_content.ui.categories' => 'categorías',
    'admin_content.ui.no_categories' => 'Todavía no hay categorías.',
    'admin_content.ui.items' => 'elementos',
    'admin_content.ui.delete_category_confirm' => '¿Eliminar esta categoría?',
    'admin_content.ui.delete_item_confirm' => '¿Eliminar este elemento?',
    'admin_content.ui.delete' => 'Eliminar',
    'admin_content.ui.empty_restaurants' => 'Todavía no hay restaurantes en esta categoría.',
    'admin_content.ui.empty_items' => 'Todavía no hay elementos en esta categoría.',
    'admin_content.ui.media_upload_label' => 'Fotos y videos',
    'admin_content.ui.media_upload_help' => 'Agrega una o varias fotos o videos desde tu dispositivo.',
    'admin_content.ui.video_upload_label' => 'Archivo de video',
    'admin_content.ui.video_upload_help' => 'Puedes subir un video desde el teléfono, la cámara o la carpeta Downloads.',
    'admin_content.ui.current_video' => 'Video actual:',
    'admin_content.ui.open_video' => 'Abrir video',
    'admin_content.ui.open_video_link' => 'Abrir enlace del video',
    'admin_content.item_label.restaurant' => 'restaurante',
    'admin_content.item_label.excursion' => 'excursión',
    'admin_content.item_label.activity' => 'actividad',
    'admin_content.item_label.sports_activity' => 'actividad deportiva',
    'admin_content.item_label.video_capsule' => 'cápsula de video',
    'admin_content.fields.category_title' => 'Título de la categoría',
    'admin_content.fields.flag_code' => 'Código de bandera / icono',
    'admin_content.fields.name' => 'Nombre',
    'admin_content.fields.address' => 'Dirección',
    'admin_content.fields.price' => 'Precio',
    'admin_content.fields.website' => 'Sitio web',
    'admin_content.fields.reference' => 'Referencia',
    'admin_content.fields.note' => 'Nota',
    'admin_content.fields.video_link' => 'Enlace de video',
    'admin_content.fields.video_title' => 'Título del video',
    'admin_content.fields.activity_name' => 'Nombre de la actividad',
    'admin_content.fields.location' => 'Ubicación',
    'admin_content.fields.description' => 'Descripción',
    'admin_content.fields.empty_placeholder' => '',
    'admin_content.fields.url_placeholder' => 'https://...',
    'admin_content.fields.video_url_placeholder' => 'https://youtube.com/...',
    'admin_content.fields.short_note_placeholder' => 'Nota breve',
    'admin_content.fields.short_description_placeholder' => 'Descripción breve',
    'admin_content.fields.optional_description_placeholder' => 'Descripción opcional',
    'admin_content.sections.common.playa_placeholder' => 'Playa del Carmen',
    'admin_content.sections.restaurants.page_title' => 'Gestión de restaurantes',
    'admin_content.sections.restaurants.page_copy' => 'Agrega, edita o elimina las categorías y restaurantes que aparecen en la página pública.',
    'admin_content.sections.restaurants.add_title' => 'Agregar un restaurante',
    'admin_content.sections.restaurants.category_title_placeholder' => 'Ej. Italiano',
    'admin_content.sections.restaurants.flag_placeholder' => 'IT, FR, MX, MUSIC...',
    'admin_content.sections.restaurants.name_placeholder' => 'Nombre del restaurante',
    'admin_content.sections.restaurants.area_placeholder' => '5a Avenida',
    'admin_content.sections.restaurants.url_placeholder' => 'nombre.com',
    'admin_content.sections.excursions.page_title' => 'Gestión de excursiones',
    'admin_content.sections.excursions.page_copy' => 'Agrega, edita o elimina las categorías y elementos de la página de excursiones.',
    'admin_content.sections.excursions.add_title' => 'Agregar una excursión',
    'admin_content.sections.excursions.category_title_placeholder' => 'Ej. Excursiones en barco y mar',
    'admin_content.sections.playa_guide.page_title' => 'Gestión de Qué hacer en Playa',
    'admin_content.sections.playa_guide.page_copy' => 'Agrega, edita o elimina las categorías y elementos de la página Qué hacer en Playa.',
    'admin_content.sections.playa_guide.add_title' => 'Agregar una actividad',
    'admin_content.sections.playa_guide.category_title_placeholder' => 'Ej. Espectáculos y experiencias destacadas',
    'admin_content.sections.activities.page_title' => 'Gestión de actividades deportivas',
    'admin_content.sections.activities.page_copy' => 'Agrega, edita o elimina categorías de actividades deportivas y actividades.',
    'admin_content.sections.activities.add_title' => 'Agregar una actividad deportiva',
    'admin_content.sections.activities.category_title_placeholder' => 'Ej. Deportes acuáticos',
    'admin_content.sections.video_capsules.page_title' => 'Gestión de cápsulas de video',
    'admin_content.sections.video_capsules.page_copy' => 'Agrega, edita o elimina categorías y cápsulas de video.',
    'admin_content.sections.video_capsules.add_title' => 'Agregar una cápsula de video',
    'admin_content.sections.video_capsules.category_title_placeholder' => 'Ej. Playas y estilo de vida',
    'admin_content.flash.item_saved' => 'Elemento guardado.',
    'admin_content.flash.item_deleted' => 'Elemento eliminado.',
    'admin_content.flash.category_saved' => 'Categoría guardada.',
    'admin_content.flash.category_deleted' => 'Categoría eliminada.',
""",
}


def update_translations() -> None:
    for rel, block in TRANSLATIONS.items():
        append_translation_block(rel, block)


if __name__ == "__main__":
    update_todo_template()
    update_controllers()
    update_translations()
    print("done")
