<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Cms\Content\CmsContentValidation;
use Lemonade\Cms\Content\CmsContentValidationException;
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
        $author = CmsContentValidation::texts($data, ['author_name'])['author_name'] ?? '';
        if (mb_strlen($author) > 255) {
            throw new CmsContentValidationException(['author_name' => 'news.validation.author_max_length']);
        }

        return new self(
            authorName: $author === '' ? null : $author,
        );
    }
}
