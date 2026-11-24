<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public;

use DateTimeImmutable;

/**
 * Provides a visible canonical News candidate for a host sitemap
 */
final readonly class PublishedNewsSitemapEntry
{
    /**
     * Creates a sitemap candidate with its last public update
     */
    public function __construct(
        private string $publicUrl,
        private DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the latest change to the article, translation or canonical route
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
