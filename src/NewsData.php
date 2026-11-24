<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Cms\Content\CmsContentValidation;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\Content\CmsRoot;

/**
 * Represents the author metadata of one News article
 */
final readonly class NewsData
{
    /**
     * Creates the author metadata stored on the article root
     */
    public function __construct(
        #[CmsRoot('author_name')]
        private ?string $authorName,
    ) {}

    /**
     * Returns the optional author name
     */
    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    /**
     * Validates and normalizes the author name submitted by the editor
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
