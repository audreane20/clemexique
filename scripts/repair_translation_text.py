from pathlib import Path
import re


TRANSLATION_FILES = {
    'src/translations/messages.en.php': {
        'replacements': {},
        'append_keys': {
            'layout.manage_content': 'Manage content',
            'layout.admin_properties': 'Properties',
            'layout.admin_restaurants': 'Restaurants',
            'layout.admin_activities': 'Sports activities',
            'layout.admin_playa_guide': 'Getaways',
            'layout.admin_video_capsules': 'Mexico video capsules',
            'layout.menu_open': 'Open menu',
            'layout.menu_close': 'Close menu',
        },
    },
    'src/translations/messages.fr.php': {
        'replacements': {
            'Evasions': 'Évasions',
            'Activités sportive': 'Activités sportives',
            'activités sportive': 'activités sportives',
            'Quoi faire a Playa': 'Quoi faire à Playa',
            'Coup de coeur': 'Coup de cœur',
            "'categories'": "'catégories'",
        },
        'append_keys': {
            'layout.manage_content': 'Gérer le contenu',
            'layout.admin_properties': 'Propriétés',
            'layout.admin_restaurants': 'Restaurants',
            'layout.admin_activities': 'Activités sportives',
            'layout.admin_playa_guide': 'Évasions',
            'layout.admin_video_capsules': 'Capsules vidéo du Mexique',
            'layout.menu_open': 'Ouvrir le menu',
            'layout.menu_close': 'Fermer le menu',
        },
    },
    'src/translations/messages.es.php': {
        'replacements': {
            'acompa?amiento': 'acompañamiento',
            'sueno': 'sueño',
            'M?xico': 'México',
            'regi?n': 'región',
            'c?digo': 'código',
            'verificaci?n': 'verificación',
            'Codigo': 'Código',
            'administraci?n': 'administración',
            'a?n': 'aún',
            'est?': 'está',
            'Intentalo': 'Inténtalo',
            'sesion': 'sesión',
            'mas': 'más',
            'm?s': 'más',
            'secci?n': 'sección',
            'informaci?n': 'información',
            'aqu?': 'aquí',
            'r?pidas': 'rápidas',
            'trav?s': 'través',
            '?tiles': 'útiles',
            'Ning?no': 'Ninguno',
            'Informacion': 'Información',
            'renovac?on': 'renovación',
            'gesti?n': 'gestión',
            'p?gina': 'página',
            'f?cilmente': 'fácilmente',
            'galer?as': 'galerías',
            'im?genes': 'imágenes',
            'Descripci?n': 'Descripción',
            'descripci?n': 'descripción',
            'franc?s': 'francés',
            'ingl?s': 'inglés',
            'espa?ol': 'español',
            'D?jalo': 'Déjalo',
            'vac?o': 'vacío',
            '?ltima': 'última',
            'Convi?rtelas': 'Conviértelas',
            'v?lidas': 'válidas',
            'conversi?n': 'conversión',
            'ning?n': 'ningún',
            'l?mite': 'límite',
            'peque?os': 'pequeños',
            'est?n': 'están',
            'todavia': 'todavía',
            'Decidi': 'Decidí',
            'decidi': 'decidí',
            'paraiso': 'paraíso',
            'Pasion': 'Pasión',
            'Por que': 'Por qué',
            'Contrasena': 'Contraseña',
            'contrasena': 'contraseña',
            'Registrate': 'Regístrate',
            'valido': 'válido',
            'validos': 'válidos',
            'Telefono': 'Teléfono',
            'direccion': 'dirección',
            'utilizada': 'utilizada',
            'se envio': 'se envió',
            'ano': 'año',
            'dias': 'días',
            'nauticas': 'náuticas',
            'categoria': 'categoría',
            'categorias': 'categorías',
            'Guia': 'Guía',
            'guia': 'guía',
            'informacion': 'información',
            'informacion ': 'información ',
            'especifica': 'específica',
            'exito': 'éxito',
            'mas pequeños': 'más pequeños',
        },
        'append_keys': {
            'layout.manage_content': 'Gestionar contenido',
            'layout.admin_properties': 'Propiedades',
            'layout.admin_restaurants': 'Restaurantes',
            'layout.admin_activities': 'Actividades deportivas',
            'layout.admin_playa_guide': 'Escapadas',
            'layout.admin_video_capsules': 'Cápsulas de video de México',
            'layout.menu_open': 'Abrir menú',
            'layout.menu_close': 'Cerrar menú',
        },
    },
}


VALUE_LINE_PATTERN = re.compile(r"^(\s*'[^']+'\s*=>\s*)'(.*)'(\s*,?\s*)$")
SUSPICIOUS_MARKERS = ('Ã', 'Â', 'â', '�')


def score(text: str) -> int:
    return sum(text.count(marker) for marker in SUSPICIOUS_MARKERS)


def repair_mojibake(value: str) -> str:
    current = value
    for _ in range(3):
        try:
            candidate = current.encode('cp1252').decode('utf-8')
        except (UnicodeEncodeError, UnicodeDecodeError):
            break

        if score(candidate) < score(current):
            current = candidate
        else:
            break

    return current


def repair_value(value: str, replacements: dict[str, str]) -> str:
    value = repair_mojibake(value)

    for old, new in replacements.items():
        value = value.replace(old, new)

    value = value.replace('Â¿', '¿').replace('Â¡', '¡')
    value = value.replace('menu', 'menú') if 'Abrir menu' in value or 'Cerrar menu' in value else value

    return value


def repair_file(path_str: str, replacements: dict[str, str]) -> None:
    path = Path(path_str)
    lines = path.read_text(encoding='utf-8').splitlines()
    repaired_lines: list[str] = []

    for line in lines:
        match = VALUE_LINE_PATTERN.match(line)
        if not match:
            repaired_lines.append(line)
            continue

        prefix, value, suffix = match.groups()
        repaired_value = repair_value(value, replacements)
        repaired_lines.append(f"{prefix}'{repaired_value}'{suffix}")

    path.write_text('\n'.join(repaired_lines) + '\n', encoding='utf-8')


def ensure_translation_keys(path_str: str, append_keys: dict[str, str]) -> None:
    if not append_keys:
        return

    path = Path(path_str)
    text = path.read_text(encoding='utf-8')

    missing = [(key, value) for key, value in append_keys.items() if f"'{key}' => " not in text]
    if not missing:
        return

    if text.rstrip().endswith('];'):
        closing = '];'
    elif text.rstrip().endswith(');'):
        closing = ');'
    else:
        raise RuntimeError(f'Unexpected translation file ending in {path_str}')

    insertion = '\n' + '\n'.join(f"    '{key}' => '{value}'," for key, value in missing) + '\n\n' + closing
    text = text.rstrip()
    text = text[: -len(closing)] + insertion
    path.write_text(text + '\n', encoding='utf-8')


def repair_layout() -> None:
    path = Path('templates/layout.html.twig')
    text = path.read_text(encoding='utf-8')

    replacements = {
        "{{ app_lang == 'fr' ? 'GÃ©rer le contenu' : (app_lang == 'es' ? 'Gestionar contenido' : 'Manage content') }}": "{{ trans('layout.manage_content') }}",
        "{{ app_lang == 'fr' ? 'PropriÃ©tÃ©s' : (app_lang == 'es' ? 'Propiedades' : 'Properties') }}": "{{ trans('layout.admin_properties') }}",
        "{{ app_lang == 'es' ? 'Restaurantes' : 'Restaurants' }}": "{{ trans('layout.admin_restaurants') }}",
        "{{ app_lang == 'fr' ? 'ActivitÃ©s sportive' : (app_lang == 'es' ? 'Actividades deportivas' : 'Sports activities') }}": "{{ trans('layout.admin_activities') }}",
        "{{ app_lang == 'fr' ? 'Evasions' : (app_lang == 'es' ? 'Escapadas' : 'Getaways') }}": "{{ trans('layout.admin_playa_guide') }}",
        "{{ app_lang == 'fr' ? 'Capsules vidÃ©o du Mexique' : (app_lang == 'es' ? 'CÃ¡psulas de video de MÃ©xico' : 'Mexico video capsules') }}": "{{ trans('layout.admin_video_capsules') }}",
        "{{ app_lang == 'fr' ? 'Ouvrir le menu' : (app_lang == 'es' ? 'Abrir menu' : 'Open menu') }}": "{{ trans('layout.menu_open') }}",
        "{{ app_lang == 'fr' ? 'Fermer le menu' : (app_lang == 'es' ? 'Cerrar menu' : 'Close menu') }}": "{{ trans('layout.menu_close') }}",
    }

    for old, new in replacements.items():
        text = text.replace(old, new)

    path.write_text(text, encoding='utf-8')


def main() -> None:
    for path, config in TRANSLATION_FILES.items():
        repair_file(path, config['replacements'])
        ensure_translation_keys(path, config.get('append_keys', {}))

    repair_layout()


if __name__ == '__main__':
    main()
