<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use DomainException;
use Lemonade\Cms\Content\CmsRoot;

/**
 * Predstavuje autorske metadata jedne aktuality
 */
final readonly class NewsData
{
    /**
     * Nastavuje textove jmeno autora
     */
    public function __construct(
        #[CmsRoot('author_name')]
        public ?string $authorName,
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
        );
    }
}
