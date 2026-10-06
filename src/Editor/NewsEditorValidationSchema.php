<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Sklada frameworkovou validaci create a update vstupu editoru Aktualit
 */
final class NewsEditorValidationSchema
{
    /**
     * Nastavuje prekladovy zdroj chyb editorove validace
     */
    public function __construct(private readonly TranslatorInterface $translator) {}

    /**
     * Vymezuje minimum pro zalozeni pojmenovaneho draftu v create modalu
     */
    public function forCreate(): ValidationSchema
    {
        return ValidationSchema::create()
            ->field('locale', $this->translator->get('news.editor.content'))
                ->required($this->translator->get('news.validation.locale_required'))
            ->field('title', $this->translator->get('news.fields.title'))
                ->required($this->translator->get('news.validation.title_required'))
                ->maxLength(255, $this->translator->get('news.validation.title_max_length'))
            ->end();
    }

    /**
     * Vymezuje flat payload jedne lokalizovane mutation a globalnich metadat
     */
    public function forUpdate(): ValidationSchema
    {
        return ValidationSchema::create()
            ->field('locale', $this->translator->get('news.editor.content'))
                ->required($this->translator->get('news.validation.locale_required'))
            ->field('title', $this->translator->get('news.fields.title'))
                ->required($this->translator->get('news.validation.title_required'))
                ->maxLength(255, $this->translator->get('news.validation.title_max_length'))
            ->field('author_name', $this->translator->get('news.fields.author_name'))
                ->maxLength(255, $this->translator->get('news.validation.author_max_length'))
            ->field('page_title', $this->translator->get('news.fields.page_title'))
                ->maxLength(255, $this->translator->get('news.validation.page_title_max_length'))
            ->field('meta_description', $this->translator->get('news.fields.meta_description'))
                ->maxLength(255, $this->translator->get('news.validation.meta_description_max_length'))
            ->field('state', $this->translator->get('news.fields.state'))
                ->inList(['draft', 'published'], $this->translator->get('news.validation.publication_invalid'))
            ->field('published_at', $this->translator->get('news.fields.published_at'))
            ->field('recommended', $this->translator->get('news.fields.recommended'))
            ->field('show_author', $this->translator->get('news.fields.show_author'))
            ->field('sharing_enabled', $this->translator->get('news.fields.sharing_enabled'))
            ->field('show_published_at', $this->translator->get('news.fields.show_published_at'))
            ->field('show_featured_image', $this->translator->get('news.fields.show_featured_image'))
            ->field('show_reading_time', $this->translator->get('news.fields.show_reading_time'))
            ->field('summary', $this->translator->get('news.fields.summary'))
            ->field('content', $this->translator->get('news.fields.content'))
            ->field('tags', $this->translator->get('news.fields.tags'))
            ->end();
    }
}
