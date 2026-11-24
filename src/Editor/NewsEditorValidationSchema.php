<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Defines validation for News editor create and update payloads
 */
final readonly class NewsEditorValidationSchema
{
    /**
     * Receives translations for editor validation messages
     */
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * Allows immediate draft creation without form values
     */
    public function forCreate(): ValidationSchema
    {
        return ValidationSchema::create();
    }

    /**
     * Validates one localized mutation and root display settings
     */
    public function forUpdate(): ValidationSchema
    {
        return CmsDisplaySettings::validation(ValidationSchema::create()
            ->field('locale', $this->translator->get('news.editor.content'))
                ->required($this->translator->get('news.validation.locale_required'))
            ->field('title', $this->translator->get('news.fields.title'))
                ->maxLength(180, $this->translator->get('news.validation.title_max_length'))
            ->field('author_name', $this->translator->get('news.fields.author_name'))
                ->maxLength(255, $this->translator->get('news.validation.author_max_length'))
            ->field('page_title', $this->translator->get('admin.seo.page_title'))
                ->maxLength(180, $this->translator->get('news.validation.page_title_max_length'))
            ->field('meta_description', $this->translator->get('admin.seo.meta_description'))
                ->maxLength(255, $this->translator->get('news.validation.meta_description_max_length'))
            ->field('publication_state', $this->translator->get('news.fields.publication_state'))
                ->inList(array_keys(CmsPublicationState::editorOptionLabelKeys()), $this->translator->get('news.validation.publication_invalid'))
            ->field('published_at', $this->translator->get('news.fields.published_at'))
            ->field('summary', $this->translator->get('news.fields.summary'))
            ->field('content', $this->translator->get('news.fields.content'))
            ->field('tags', $this->translator->get('news.fields.tags'))
            ->end(), $this->translator);
    }
}
