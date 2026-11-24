<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public;

use DateTimeImmutable;
use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\PublishedMediaInterface;

/**
 * Represents one publicly visible News translation with its system media
 */
final readonly class PublishedNews implements PublishedMediaInterface
{
    /**
     * Creates a complete published News projection
     *
     * @param list<string> $tags
     * @param list<FileMetadata> $gallery
     * @param list<FileMetadata> $attachments
     */
    public function __construct(
        private int $id,
        private string $locale,
        private string $title,
        private ?string $summary,
        private ?string $content,
        private ?string $pageTitle,
        private ?string $metaDescription,
        private DateTimeImmutable $publishedAt,
        private DateTimeImmutable $updatedAt,
        private string $publicUrl,
        private ?string $authorName,
        private array $tags,
        private bool $recommended,
        private bool $showAuthor,
        private bool $sharingEnabled,
        private bool $showPublishedAt,
        private bool $showThumbnail,
        private bool $showReadingTime,
        private bool $showTags,
        private ?FileMetadata $thumbnail,
        private array $gallery,
        private array $attachments,
    ) {}

    /**
     * Returns the stable News identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the published content locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the localized title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the optional system summary
     */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Returns the optional system rich-text content
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Returns the optional SEO page title
     */
    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }

    /**
     * Returns the optional SEO meta description
     */
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    /**
     * Returns the start of public visibility
     */
    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Returns the last translation update time
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the author name when author display is enabled
     */
    public function getAuthorName(): ?string
    {
        return $this->showAuthor ? $this->authorName : null;
    }

    /**
     * Returns localized tags when tag display is enabled
     *
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->showTags ? $this->tags : [];
    }

    /**
     * Reports whether the article is recommended
     */
    public function isRecommended(): bool
    {
        return $this->recommended;
    }

    /**
     * Reports whether the author is shown
     */
    public function isAuthorShown(): bool
    {
        return $this->showAuthor;
    }

    /**
     * Reports whether sharing is enabled
     */
    public function isSharingEnabled(): bool
    {
        return $this->sharingEnabled;
    }

    /**
     * Reports whether the publication date is shown
     */
    public function isPublishedAtShown(): bool
    {
        return $this->showPublishedAt;
    }

    /**
     * Reports whether the thumbnail is shown
     */
    public function isThumbnailShown(): bool
    {
        return $this->showThumbnail;
    }

    /**
     * Reports whether reading time is shown
     */
    public function isReadingTimeShown(): bool
    {
        return $this->showReadingTime;
    }

    /**
     * Reports whether tags are shown
     */
    public function isTagsShown(): bool
    {
        return $this->showTags;
    }

    /**
     * Returns the thumbnail image when display is enabled and the file is readable
     */
    public function getThumbnail(): ?FileMetadata
    {
        return $this->showThumbnail ? $this->thumbnail : null;
    }

    /**
     * Returns gallery images in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getGallery(): array
    {
        return $this->gallery;
    }

    /**
     * Returns attachments in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }
}
