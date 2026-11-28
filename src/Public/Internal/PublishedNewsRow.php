<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public\Internal;

use DateTimeImmutable;

/**
 * Carries one selected published News translation into hydration
 *
 * @internal
 */
final readonly class PublishedNewsRow
{
    private function __construct(
        private int $id,
        private int $translationId,
        private string $locale,
        private string $title,
        private ?string $summary,
        private ?string $content,
        private ?string $pageTitle,
        private ?string $metaDescription,
        private DateTimeImmutable $publishedAt,
        private DateTimeImmutable $updatedAt,
        private string $canonicalPath,
        private ?string $authorName,
        private bool $recommended,
        private bool $showAuthor,
        private bool $sharingEnabled,
        private bool $showPublishedAt,
        private bool $showThumbnail,
        private bool $showReadingTime,
        private bool $showTags,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTranslationId(): int
    {
        return $this->translationId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCanonicalPath(): string
    {
        return $this->canonicalPath;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function isRecommended(): bool
    {
        return $this->recommended;
    }

    public function isAuthorShown(): bool
    {
        return $this->showAuthor;
    }

    public function isSharingEnabled(): bool
    {
        return $this->sharingEnabled;
    }

    public function isPublishedAtShown(): bool
    {
        return $this->showPublishedAt;
    }

    public function isThumbnailShown(): bool
    {
        return $this->showThumbnail;
    }

    public function isReadingTimeShown(): bool
    {
        return $this->showReadingTime;
    }

    public function areTagsShown(): bool
    {
        return $this->showTags;
    }

    /**
     * Converts one raw database row at the selection boundary
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            translationId: (int) $row['translation_id'],
            locale: (string) $row['locale'],
            title: (string) $row['title'],
            summary: $row['summary'] === null ? null : (string) $row['summary'],
            content: $row['content'] === null ? null : (string) $row['content'],
            pageTitle: $row['page_title'] === null ? null : (string) $row['page_title'],
            metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
            publishedAt: new DateTimeImmutable((string) $row['published_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            canonicalPath: (string) $row['canonical_path'],
            authorName: $row['author_name'] === null ? null : (string) $row['author_name'],
            recommended: (bool) $row['recommended'],
            showAuthor: (bool) $row['show_author'],
            sharingEnabled: (bool) $row['sharing_enabled'],
            showPublishedAt: (bool) $row['show_published_at'],
            showThumbnail: (bool) $row['show_thumbnail'],
            showReadingTime: (bool) $row['show_reading_time'],
            showTags: (bool) $row['show_tags'],
        );
    }
}
