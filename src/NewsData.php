<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use DomainException;
use Lemonade\Cms\Content\CmsRoot;

/**
 * Predstavuje globalni nastaveni jedne aktuality
 */
final readonly class NewsData
{
    /**
     * Nastavuje autora, doporuceni a zobrazovaci volby
     */
    public function __construct(
        #[CmsRoot('author_name')]
        public ?string $authorName,
        #[CmsRoot('recommended')]
        public bool $recommended,
        #[CmsRoot('show_author')]
        public bool $showAuthor,
        #[CmsRoot('sharing_enabled')]
        public bool $sharingEnabled,
        #[CmsRoot('show_published_at')]
        public bool $showPublishedAt,
        #[CmsRoot('show_thumbnail')]
        public bool $showThumbnail,
        #[CmsRoot('show_reading_time')]
        public bool $showReadingTime,
        #[CmsRoot('show_tags')]
        public bool $showTags,
    ) {}

    /**
     * Validuje a normalizuje domenove hodnoty editoru
     *
     * @param array<string,mixed> $data
     */
    public static function fromEditor(array $data): self
    {
        $author = trim((string) ($data['author_name'] ?? ''));
        if (mb_strlen($author) > 255) {
            throw new DomainException('news.validation.author_max_length');
        }

        return new self(
            authorName: $author === '' ? null : $author,
            recommended: in_array((string) ($data['recommended'] ?? ''), ['1', 'true', 'on'], true),
            showAuthor: self::displaySetting($data, 'show_author'),
            sharingEnabled: self::displaySetting($data, 'sharing_enabled'),
            showPublishedAt: self::displaySetting($data, 'show_published_at'),
            showThumbnail: self::displaySetting($data, 'show_thumbnail'),
            showReadingTime: self::displaySetting($data, 'show_reading_time'),
            showTags: self::displaySetting($data, 'show_tags'),
        );
    }

    /**
     * Vyhodnoti vychozi hodnotu a zadany stav prepinace zobrazeni
     *
     * @param array<string,mixed> $data
     */
    private static function displaySetting(array $data, string $name): bool
    {
        return !array_key_exists($name, $data)
            || in_array((string) $data[$name], ['1', 'true', 'on'], true);
    }
}
