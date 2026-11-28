<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public\Internal;

use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Cms\News\Public\PublishedNews;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Database\Database;

/**
 * Hydrates selected News translations with their tags and system media
 *
 * @internal
 */
final readonly class PublishedNewsHydrator
{
    public function __construct(
        private Database $database,
        private FileReadModel $files,
        private PublicCmsUrlBuilder $urls,
    ) {}

    /**
     * @param list<PublishedNewsRow> $rows
     * @return list<PublishedNews>
     */
    public function hydrate(array $rows, PublicLocaleResolution $locale): array
    {
        if ($rows === []) {
            return [];
        }

        $tags = $this->tagsForTranslations($rows);
        $media = $this->mediaForArticles($rows);

        return array_map(
            fn(PublishedNewsRow $row): PublishedNews => $this->createArticle(
                row: $row,
                locale: $locale,
                tags: $tags[$row->getTranslationId()] ?? [],
                media: $media[$row->getId()] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param list<PublishedNewsRow> $rows
     * @return array<int,list<string>>
     */
    private function tagsForTranslations(array $rows): array
    {
        $translationIds = array_map(
            static fn(PublishedNewsRow $row): int => $row->getTranslationId(),
            $rows,
        );
        $tags = [];

        foreach ($this->database->table('cms_news_article_translation_tag tag')
            ->select(['tag.translation_id', 'tag.name'])
            ->whereIn('tag.translation_id', $translationIds)
            ->orderBy('tag.translation_id')
            ->orderBy('tag.sort_order')
            ->orderBy('tag.id')
            ->getArray() as $tag) {
            $tags[(int) $tag['translation_id']][] = (string) $tag['name'];
        }

        return $tags;
    }

    /**
     * @param list<PublishedNewsRow> $rows
     * @return array<int,array<string,list<FileMetadata>>>
     */
    private function mediaForArticles(array $rows): array
    {
        $articleIds = array_map(
            static fn(PublishedNewsRow $row): int => $row->getId(),
            $rows,
        );
        $media = [];

        foreach ($this->files->listForEntitiesUsages(
            moduleCode: ModuleManifest::CODE,
            entityIds: $articleIds,
            usages: ['thumbnail', 'gallery', 'attachment'],
        ) as $file) {
            if (!$this->isReadableFile($file)) {
                continue;
            }

            $media[$file->entityId()][$file->usage()][] = $file;
        }

        return $media;
    }

    /**
     * @param list<string> $tags
     * @param array<string,list<FileMetadata>> $media
     */
    private function createArticle(
        PublishedNewsRow $row,
        PublicLocaleResolution $locale,
        array $tags,
        array $media,
    ): PublishedNews {
        return new PublishedNews(
            id: $row->getId(),
            locale: $row->getLocale(),
            title: $row->getTitle(),
            summary: $row->getSummary(),
            content: $row->getContent(),
            pageTitle: $row->getPageTitle(),
            metaDescription: $row->getMetaDescription(),
            publishedAt: $row->getPublishedAt(),
            updatedAt: $row->getUpdatedAt(),
            publicUrl: $this->urls->build($locale, $row->getLocale(), $row->getCanonicalPath()),
            authorName: $row->getAuthorName(),
            tags: $tags,
            recommended: $row->isRecommended(),
            showAuthor: $row->isAuthorShown(),
            sharingEnabled: $row->isSharingEnabled(),
            showPublishedAt: $row->isPublishedAtShown(),
            showThumbnail: $row->isThumbnailShown(),
            showReadingTime: $row->isReadingTimeShown(),
            showTags: $row->areTagsShown(),
            thumbnail: $media['thumbnail'][0] ?? null,
            gallery: $media['gallery'] ?? [],
            attachments: $media['attachment'] ?? [],
        );
    }

    private function isReadableFile(FileMetadata $file): bool
    {
        if (in_array($file->usage(), ['thumbnail', 'gallery'], true) && $file->kind() !== 'image') {
            return false;
        }

        if ($file->kind() === 'image') {
            return $file->assetId() !== null && $file->sourceVersion() !== null;
        }

        return $file->kind() === 'file' && $file->storagePath() !== null;
    }
}
