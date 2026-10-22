<?php

declare(strict_types=1);

return [
    'module' => ['name' => 'Aktuality', 'description' => 'Správa novinek a jejich publikace.'],
    'actions' => ['create' => 'Přidat aktualitu', 'confirm_create' => 'Vytvořit', 'edit' => 'Upravit', 'delete' => 'Smazat', 'restore' => 'Obnovit', 'assign_owner' => 'Předat vlastnictví', 'assign_owner_submit' => 'Předat', 'owner_assigned' => 'Vlastnictví aktuality bylo předáno', 'deleted' => 'Aktualita byla smazána', 'restored' => 'Aktualita byla obnovena jako koncept'],
    'fields' => ['title' => 'Název článku', 'author_name' => 'Autor', 'tags' => 'Štítky', 'tags_help' => 'Každý štítek zadejte na samostatný řádek.', 'page_title' => 'Název na stránce', 'page_title_help' => 'Doporučená délka je 50–60 znaků. Tento název se zobrazí ve výsledcích vyhledávání.', 'summary' => 'Perex', 'content' => 'Obsah', 'meta_description' => 'Meta description', 'meta_description_help' => 'Doporučená délka je 120–160 znaků. Tento popis se zobrazí ve výsledcích vyhledávání.', 'thumbnail' => 'Hlavní obrázek', 'recommended' => 'Doporučený příspěvek', 'recommended_help' => 'Aktualita se bude zobrazovat v sekci doporučených příspěvků.', 'show_author' => 'Zobrazit autora', 'show_author_help' => 'U článku se zobrazí jméno autora.', 'sharing_enabled' => 'Povolit sdílení', 'sharing_enabled_help' => 'Zobrazí tlačítka pro sdílení článku.', 'show_published_at' => 'Zobrazit datum publikace', 'show_published_at_help' => 'Datum bude uvedeno v hlavičce článku.', 'show_thumbnail' => 'Zobrazit hlavní obrázek', 'show_thumbnail_help' => 'Hlavní obrázek se zobrazí v detailu článku.', 'show_reading_time' => 'Zobrazit dobu čtení', 'show_reading_time_help' => 'Automaticky vypočítaná orientační doba čtení.', 'show_tags' => 'Zobrazit štítky', 'show_tags_help' => 'Zobrazí štítky aktuální jazykové verze článku.', 'publication_state' => 'Stav publikace', 'published_at' => 'Publikováno od', 'created_by' => 'Vytvořil', 'created_at' => 'Vytvořeno', 'updated_by' => 'Naposledy upravil', 'updated_at' => 'Aktualizováno', 'deleted_by' => 'Smazal', 'deleted_at' => 'Smazáno', 'owner' => 'Vlastník'],
    'list' => ['title' => 'Aktuality', 'description' => 'Správa publikovaných a rozpracovaných aktualit', 'search' => 'Hledat v aktualitách…', 'loading' => 'Načítání aktualit…', 'empty' => 'Zatím zde nejsou žádné aktuality', 'active' => 'Aktivní', 'deleted' => 'Smazané', 'untitled_locale' => 'Bez názvu ({locale})'],
    'filters' => ['state' => 'Stav', 'all_states' => 'Všechny stavy', 'locale' => 'Jazyk', 'all_locales' => 'Všechny jazyky'],
    'editor' => [
        'title' => 'Upravit aktualitu',
        'create_title' => 'Nová aktualita',
        'content' => 'Obsah',
        'publication' => 'Publikace',
        'ownership' => 'Autorství a vlastnictví',
        'locale' => ['label' => 'Jazykové verze', 'existing_translation' => 'Existující překlad', 'new_translation' => 'Nový překlad', 'disabled' => 'Neaktivní jazyk'],
        'tabs' => ['content' => 'Obsah', 'seo' => 'SEO', 'settings' => 'Nastavení'],
        'sections' => ['localized_content' => 'Obsah', 'localized_content_help' => 'Základní informace o aktualitě', 'content' => 'Obsah článku', 'content_help' => 'Text a hlavní obsah aktuality', 'seo' => 'SEO nastavení', 'seo_help' => 'Nastavení názvu, popisu a adresy pro vyhledávače', 'recommendation' => 'Doporučení', 'recommendation_help' => 'Zařazení aktuality mezi doporučený obsah', 'display' => 'Nastavení zobrazení', 'display_help' => 'Doplňující nastavení zobrazení aktuality', 'gallery_help' => 'Obrázky přiřazené k záznamu', 'attachments_help' => 'Soubory ke stažení a další dokumenty', 'publication_help' => 'Stav zveřejnění a datum publikace', 'ownership_help' => 'Historie záznamu a správa vlastníka', 'thumbnail_help' => 'Hlavní náhled aktuality v přehledech a odkazech'],
        'not_implemented' => 'Zatím neimplementováno',
        'thumbnail' => ['title' => 'Náhledový obrázek', 'help' => 'Obrázek se použije jako hlavní náhled aktuality.'],
        'created' => 'Aktualita byla vytvořena',
        'updated' => 'Aktualita byla uložena',
    ],
    'validation' => ['translation_required' => 'Vyplňte nadpis alespoň v jednom jazyce', 'locale_required' => 'Vyberte jazyk', 'title_required' => 'Nadpis je povinný', 'title_max_length' => 'Nadpis může mít nejvýše 180 znaků', 'author_max_length' => 'Autor může mít nejvýše 255 znaků', 'page_title_max_length' => 'Název na stránce může mít nejvýše 180 znaků', 'meta_description_max_length' => 'Meta description může mít nejvýše 255 znaků', 'tag_max_length' => 'Štítek může mít nejvýše 255 znaků', 'locale_invalid' => 'Zvolený jazyk není aktivní', 'publication_invalid' => 'Stav publikace není platný', 'owner_invalid' => 'Vybraný vlastník neexistuje nebo byl odstraněn', 'not_found' => 'Aktualita neexistuje', 'save_failed' => 'Aktualitu se nepodařilo uložit'],
    'confirm' => ['delete' => 'Opravdu chcete tuto aktualitu smazat?', 'restore' => 'Opravdu chcete tuto aktualitu obnovit jako koncept?'],
    'audit' => ['events' => ['created' => 'Vytvořena aktualita', 'updated' => 'Upravena aktualita', 'translation_created' => 'Přidána jazyková mutace aktuality', 'translation_updated' => 'Upravena jazyková mutace aktuality', 'published' => 'Publikována aktualita', 'unpublished' => 'Zrušeno publikování aktuality', 'deleted' => 'Smazána aktualita', 'restored' => 'Obnovena aktualita jako koncept', 'owner_transferred' => 'Předáno vlastnictví aktuality']],
    'permissions' => [
        'view' => 'Zobrazit aktuality',
        'view_own' => 'Zobrazit vlastní aktuality',
        'create' => 'Vytvářet aktuality',
        'edit' => 'Upravovat aktuality',
        'edit_own' => 'Upravovat vlastní aktuality',
        'publish' => 'Publikovat aktuality',
        'publish_own' => 'Publikovat vlastní aktuality',
        'delete' => 'Mazat aktuality',
        'delete_own' => 'Mazat vlastní aktuality',
        'restore' => 'Obnovovat aktuality',
        'restore_own' => 'Obnovovat vlastní aktuality',
        'assign_owner' => 'Předávat vlastnictví aktualit',
    ],
];
