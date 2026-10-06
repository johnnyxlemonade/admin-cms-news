<?php

declare(strict_types=1);

return [
    'module' => ['name' => 'Aktuality'],
    'actions' => ['create' => 'Přidat aktualitu', 'confirm_create' => 'Vytvořit', 'edit' => 'Upravit', 'delete' => 'Smazat', 'restore' => 'Obnovit', 'deleted' => 'Aktualita byla smazána', 'restored' => 'Aktualita byla obnovena jako koncept'],
    'fields' => ['title' => 'Název článku', 'author_name' => 'Autor', 'tags' => 'Štítky', 'tags_help' => 'Každý štítek zadejte na samostatný řádek.', 'page_title' => 'Název na stránce', 'page_title_help' => 'Doporučená délka je 50–60 znaků. Tento název se zobrazí ve výsledcích vyhledávání.', 'summary' => 'Perex', 'content' => 'Obsah', 'meta_description' => 'Meta description', 'meta_description_help' => 'Doporučená délka je 120–160 znaků. Tento popis se zobrazí ve výsledcích vyhledávání.', 'featured_image' => 'Hlavní obrázek', 'recommended' => 'Doporučený příspěvek', 'recommended_help' => 'Aktualita se bude zobrazovat v sekci doporučených příspěvků.', 'show_author' => 'Zobrazit autora', 'show_author_help' => 'U článku se zobrazí jméno autora.', 'sharing_enabled' => 'Povolit sdílení', 'sharing_enabled_help' => 'Zobrazí tlačítka pro sdílení článku.', 'show_published_at' => 'Zobrazit datum publikace', 'show_published_at_help' => 'Datum bude uvedeno v hlavičce článku.', 'show_featured_image' => 'Zobrazit hlavní obrázek', 'show_featured_image_help' => 'Hlavní obrázek se zobrazí v detailu článku.', 'show_reading_time' => 'Zobrazit dobu čtení', 'show_reading_time_help' => 'Automaticky vypočítaná orientační doba čtení.', 'state' => 'Stav', 'published_at' => 'Publikováno od', 'updated_at' => 'Aktualizováno'],
    'list' => ['title' => 'Aktuality', 'description' => 'Správa publikovaných a rozpracovaných aktualit', 'search' => 'Hledat v aktualitách…', 'loading' => 'Načítání aktualit…', 'empty' => 'Zatím zde nejsou žádné aktuality', 'active' => 'Aktivní', 'deleted' => 'Smazané'],
    'filters' => ['state' => 'Stav', 'all_states' => 'Všechny stavy', 'locale' => 'Jazyk', 'all_locales' => 'Všechny jazyky'],
    'editor' => [
        'title' => 'Upravit aktualitu',
        'create_title' => 'Nová aktualita',
        'content' => 'Obsah',
        'publication' => 'Publikace',
        'locale' => ['label' => 'Jazyk obsahu', 'new_translation' => 'Nový překlad', 'disabled' => 'Neaktivní jazyk'],
        'tabs' => ['content' => 'Obsah', 'seo' => 'SEO', 'settings' => 'Nastavení'],
        'sections' => ['localized_content' => 'Obsah', 'content' => 'Obsah článku', 'seo' => 'SEO nastavení', 'seo_help' => 'Upravte, jak se aktualita zobrazí ve výsledcích vyhledávání.', 'recommendation' => 'Doporučení', 'display' => 'Nastavení zobrazení'],
        'not_implemented' => 'Zatím neimplementováno',
        'featured_image' => ['title' => 'Náhledový obrázek', 'help' => 'Obrázek se použije jako hlavní náhled aktuality.'],
        'created' => 'Aktualita byla vytvořena',
        'updated' => 'Aktualita byla uložena',
    ],
    'state' => ['draft' => 'Koncept', 'published' => 'Publikováno'],
    'validation' => ['translation_required' => 'Vyplňte nadpis alespoň v jednom jazyce', 'locale_required' => 'Vyberte jazyk', 'title_required' => 'Nadpis je povinný', 'title_max_length' => 'Nadpis může mít nejvýše 255 znaků', 'author_max_length' => 'Autor může mít nejvýše 255 znaků', 'page_title_max_length' => 'Název na stránce může mít nejvýše 255 znaků', 'meta_description_max_length' => 'Meta description může mít nejvýše 255 znaků', 'tag_max_length' => 'Štítek může mít nejvýše 255 znaků', 'locale_invalid' => 'Zvolený jazyk není aktivní', 'url_invalid' => 'URL adresa není platná', 'url_taken' => 'Tato URL adresa je v daném jazyce již obsazená', 'locale_prefix_missing' => 'Pro zvolený jazyk chybí veřejný prefix aktualit', 'publication_invalid' => 'Stav publikace není platný', 'not_found' => 'Aktualita neexistuje', 'save_failed' => 'Aktualitu se nepodařilo uložit'],
    'confirm' => ['delete' => 'Opravdu chcete tuto aktualitu smazat?', 'restore' => 'Opravdu chcete tuto aktualitu obnovit jako koncept?'],
    'audit' => ['events' => ['created' => 'Vytvořena aktualita', 'updated' => 'Upravena aktualita', 'translation_created' => 'Přidána jazyková mutace aktuality', 'translation_updated' => 'Upravena jazyková mutace aktuality', 'published' => 'Publikována aktualita', 'unpublished' => 'Zrušeno publikování aktuality', 'deleted' => 'Smazána aktualita', 'restored' => 'Obnovena aktualita jako koncept']],
    'permissions' => [
        'view' => 'Zobrazit aktuality',
        'create' => 'Vytvářet aktuality',
        'edit' => 'Upravovat aktuality',
        'publish' => 'Publikovat aktuality',
        'delete' => 'Mazat aktuality',
        'restore' => 'Obnovovat aktuality',
    ],
];
