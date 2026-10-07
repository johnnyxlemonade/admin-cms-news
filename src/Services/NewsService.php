<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Services;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditOperation;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\TransactionalEventCollector;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\Routing\CmsRouteReservationService;
use Lemonade\Cms\Routing\PublicModuleRoutePrefixRepositoryInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use RuntimeException;

/**
 * Orchestrace mutaci, publication lifecycle a auditu aggregate Aktualit
 */
final class NewsService
{
    /**
     * Nastavuje persistence, content locale, URL prefix a auditovane mutace
     */
    public function __construct(
        private readonly NewsModel $news,
        private readonly LanguageRegistry $languages,
        private readonly PublicModuleRoutePrefixRepositoryInterface $prefixes,
        private readonly CmsRouteReservationService $routes,
        private readonly Slugger $slugger,
        private readonly AuthorizationService $authorization,
        private readonly TransactionalEventProcessor $events,
    ) {}

    /**
     * Vytvori pojmenovany draft s prvni lokalizovanou mutaci bez canonical URL
     *
     * @param array<string,mixed> $translation
     */
    public function create(AuditActor $actor, array $translation): int
    {
        $prepared = $this->prepareInitialTranslation($translation);
        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.create',
            actor: $actor,
        );

        return $this->events->execute($operation, function (TransactionalEventCollector $events) use ($prepared): int {
            $now = date('Y-m-d H:i:s');
            $articleId = $this->news->createDraft(
                null,
                false,
                $this->defaultDisplaySettings(),
                $now,
            );
            if ($articleId < 1) {
                throw new RuntimeException('news.validation.save_failed');
            }
            $this->news->saveTranslation(
                $articleId,
                $prepared['locale'],
                $prepared['title'],
                null,
                null,
                null,
                null,
                null,
                $now,
            );
            $events->record(new DomainEvent(
                code: 'cms.news.created',
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
            ));

            return $articleId;
        });
    }

    /**
     * Ulozi zmenena root metadata bez zapisu a auditu pri no-op
     *
     * @param array<string,mixed> $metadata
     */
    public function saveMetadata(AuditActor $actor, int $articleId, array $metadata): void
    {
        $this->assertArticle($articleId);
        $prepared = $this->prepareMetadata($metadata);
        $existing = $this->news->metadata($articleId);
        if ($existing !== null
            && $existing['author_name'] === $prepared['authorName']
            && (int) $existing['recommended'] === ($prepared['recommended'] ? 1 : 0)
            && $this->sameDisplaySettings($existing, $prepared['displaySettings'])
        ) {
            return;
        }

        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.metadata.save',
            actor: $actor,
        );
        $this->events->execute($operation, function (TransactionalEventCollector $events) use ($articleId, $prepared): void {
            $this->news->saveMetadata(
                $articleId,
                $prepared['authorName'],
                $prepared['recommended'],
                $prepared['displaySettings'],
                date('Y-m-d H:i:s'),
            );
            $events->record(new DomainEvent(
                code: 'cms.news.updated',
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
            ));
        });
    }

    /**
     * Ulozi mutation a pri stejnem obsahu nevytvori write ani audit
     *
     * @param array<string,mixed> $translation
     */
    public function saveTranslation(AuditActor $actor, int $articleId, array $translation): void
    {
        $this->assertArticle($articleId);
        $locale = strtolower(trim((string) ($translation['locale'] ?? '')));
        $existing = $this->news->translation($articleId, $locale);
        $hasRoute = $this->routes->hasReservation('cms.news', $articleId, $locale);
        $prepared = $this->prepareTranslation($translation, $existing, $hasRoute);
        if ($hasRoute && $this->sameTranslation($existing, $prepared)) {
            return;
        }
        $eventCode = $existing === null ? 'cms.news.translation_created' : 'cms.news.translation_updated';
        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.translation.save',
            actor: $actor,
        );
        $this->events->execute($operation, function (TransactionalEventCollector $events) use ($articleId, $prepared, $eventCode): void {
            $this->savePreparedTranslation($articleId, $prepared, date('Y-m-d H:i:s'));
            $events->record(new DomainEvent(
                code: $eventCode,
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
                payload: ['locale' => $prepared['locale']],
            ));
        });
    }

    /**
     * Zmeni publication state pouze pro actora s cms.news.publish
     */
    public function changePublication(AuditActor $actor, int $articleId, string $state, ?string $publishedAt): void
    {
        $this->authorization->requirePermission('cms.news.publish');
        if (!in_array($state, ['draft', 'published'], true)) {
            throw new RuntimeException('news.validation.publication_invalid');
        }
        $this->assertArticle($articleId);
        $publication = $this->news->publication($articleId);
        $resolvedPublishedAt = $state === 'published'
            ? ($publishedAt !== null && $publishedAt !== '' ? $publishedAt : date('Y-m-d H:i:s'))
            : null;
        if ($publication !== null
            && $publication['state'] === $state
            && $publication['published_at'] === $resolvedPublishedAt
        ) {
            return;
        }
        $eventCode = $state === 'published' ? 'cms.news.published' : 'cms.news.unpublished';
        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.publication.change',
            actor: $actor,
        );
        $this->events->execute($operation, function (TransactionalEventCollector $events) use ($articleId, $state, $resolvedPublishedAt, $eventCode): void {
            $this->news->savePublication($articleId, $state, $resolvedPublishedAt, date('Y-m-d H:i:s'));
            $events->record(new DomainEvent(
                code: $eventCode,
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
            ));
        });
    }

    /**
     * Soft-deleteuje clanek bez odstraneni jeho lokalizaci a reservovanych rout
     */
    public function delete(AuditActor $actor, int $articleId): void
    {
        $this->authorization->requirePermission('cms.news.delete');
        $article = $this->news->lifecycle($articleId);
        if ($article === null) {
            throw new RuntimeException('news.validation.not_found');
        }
        if ($article['deleted_at'] !== null) {
            return;
        }
        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.delete',
            actor: $actor,
        );
        $this->events->execute($operation, function (TransactionalEventCollector $events) use ($articleId): void {
            $now = date('Y-m-d H:i:s');
            $this->news->softDelete($articleId, $now);
            $this->routes->softDeleteForTarget('cms.news', $articleId, $now);
            $events->record(new DomainEvent(
                code: 'cms.news.deleted',
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
            ));
        });
    }

    /**
     * Obnovi soft-deleted clanek jako draft bez automatickeho publikovani
     */
    public function restore(AuditActor $actor, int $articleId): void
    {
        $this->authorization->requirePermission('cms.news.restore');
        $article = $this->news->lifecycle($articleId);
        if ($article === null) {
            throw new RuntimeException('news.validation.not_found');
        }
        if ($article['deleted_at'] === null) {
            return;
        }
        $operation = new AuditOperation(
            moduleCode: 'cms.news',
            code: 'news.restore',
            actor: $actor,
        );
        $this->events->execute($operation, function (TransactionalEventCollector $events) use ($articleId): void {
            $now = date('Y-m-d H:i:s');
            $this->news->restoreDraft($articleId, $now);
            $this->routes->restoreForTarget('cms.news', $articleId, $now);
            $events->record(new DomainEvent(
                code: 'cms.news.restored',
                moduleCode: 'cms.news',
                entityType: 'cms_news_article',
                entityKey: (string) $articleId,
            ));
        });
    }

    /**
     * Pripravi mutation se stabilnim slugem nebo base slugem pro prvni canonical reservation
     *
     * @param array<string,mixed> $translation
     * @param array<string,mixed>|null $existing
     * @return array{
     *     locale:string,
     *     title:string,
     *     pageTitle:string|null,
     *     slug:string,
     *     baseSlug:string,
     *     prefix:string|null,
     *     requiresRouteReservation:bool,
     *     summary:string|null,
     *     content:string|null,
     *     metaDescription:string|null,
     *     tags:list<string>,
     * }
     */
    private function prepareTranslation(array $translation, ?array $existing, bool $hasRoute): array
    {
        $locale = strtolower(trim($translation['locale'] ?? ''));
        $title = trim($translation['title'] ?? '');
        if ($existing === null && !$this->isEnabledLocale($locale)) {
            throw new RuntimeException('news.validation.locale_invalid');
        }
        if ($title === '') {
            throw new RuntimeException('news.validation.title_required');
        }
        if (mb_strlen($title) > 255) {
            throw new RuntimeException('news.validation.title_max_length');
        }
        $existingSlug = $existing['slug'] ?? null;
        $baseSlug = is_string($existingSlug) && $existingSlug !== ''
            ? $existingSlug
            : $this->slugger->slug($title, 255, $locale);
        if ($baseSlug === '') {
            throw new RuntimeException('news.validation.url_invalid');
        }
        $requiresRouteReservation = !$hasRoute;
        $prefix = $requiresRouteReservation ? $this->prefixes->prefixFor('cms.news', $locale) : null;
        if ($requiresRouteReservation && $prefix === null) {
            throw new RuntimeException('news.validation.locale_prefix_missing');
        }

        return [
            'locale' => $locale,
            'title' => $title,
            'pageTitle' => $this->nullableText($translation['page_title'] ?? null),
            'slug' => $baseSlug,
            'baseSlug' => $baseSlug,
            'prefix' => $prefix,
            'requiresRouteReservation' => $requiresRouteReservation,
            'summary' => $this->nullableText($translation['summary'] ?? null),
            'content' => $this->nullableText($translation['content'] ?? null),
            'metaDescription' => $this->nullableText($translation['meta_description'] ?? null),
            'tags' => $this->prepareTags($translation['tags'] ?? null),
        ];
    }

    /**
     * Overi minimum potrebne pro zalozeni draftu bez rezervace URL adresy
     *
     * @param array<string,mixed> $translation
     * @return array{locale:string,title:string}
     */
    private function prepareInitialTranslation(array $translation): array
    {
        $locale = strtolower(trim((string) ($translation['locale'] ?? '')));
        $title = trim((string) ($translation['title'] ?? ''));
        if (!$this->isEnabledLocale($locale)) {
            throw new RuntimeException('news.validation.locale_invalid');
        }
        if ($title === '') {
            throw new RuntimeException('news.validation.title_required');
        }
        if (mb_strlen($title) > 255) {
            throw new RuntimeException('news.validation.title_max_length');
        }

        return ['locale' => $locale, 'title' => $title];
    }

    /**
     * Ulozi pripravenou mutation a pri prvnim save rezervuje jeji canonical route
     *
     * @param array{
     *     locale:string,
     *     title:string,
     *     pageTitle:string|null,
     *     slug:string,
     *     baseSlug:string,
     *     prefix:string|null,
     *     requiresRouteReservation:bool,
     *     summary:string|null,
     *     content:string|null,
     *     metaDescription:string|null,
     *     tags:list<string>,
     * } $translation
     */
    private function savePreparedTranslation(int $articleId, array $translation, string $now): void
    {
        $slug = $translation['slug'];
        if ($translation['requiresRouteReservation']) {
            $prefix = $translation['prefix'];
            if ($prefix === null) {
                throw new RuntimeException('news.validation.locale_prefix_missing');
            }
            $slug = $this->routes->reserveAutomatic(
                moduleCode: 'cms.news',
                entityId: $articleId,
                locale: $translation['locale'],
                prefix: $prefix,
                baseSlug: $translation['baseSlug'],
            )->slug();
        }
        $translationId = $this->news->saveTranslation(
            $articleId,
            $translation['locale'],
            $translation['title'],
            $translation['pageTitle'],
            $slug,
            $translation['summary'],
            $translation['content'],
            $translation['metaDescription'],
            $now,
        );
        if ($this->news->translationTags($articleId, $translation['locale']) !== $translation['tags']) {
            $this->news->syncTranslationTags($translationId, $translation['tags'], $now);
        }
    }

    /**
     * Porovna business hodnoty mutace bez persistence timestampu
     *
     * @param array<string,mixed>|null $existing
     * @param array{
     *     locale:string,
     *     title:string,
     *     pageTitle:string|null,
     *     slug:string,
     *     baseSlug:string,
     *     prefix:string|null,
     *     requiresRouteReservation:bool,
     *     summary:string|null,
     *     content:string|null,
     *     metaDescription:string|null,
     *     tags:list<string>,
     * } $prepared
     */
    private function sameTranslation(?array $existing, array $prepared): bool
    {
        return $existing !== null
            && $existing['title'] === $prepared['title']
            && $existing['page_title'] === $prepared['pageTitle']
            && $existing['slug'] === $prepared['slug']
            && $existing['summary'] === $prepared['summary']
            && $existing['content'] === $prepared['content']
            && $existing['meta_description'] === $prepared['metaDescription']
            && $this->news->translationTags(
                (int) $existing['article_id'],
                $prepared['locale'],
            ) === $prepared['tags'];
    }

    /**
     * Rozhodne, zda locale smi vytvorit novou content mutation
     */
    private function isEnabledLocale(string $locale): bool
    {
        foreach ($this->languages->enabledLocales() as $language) {
            if ($language['code'] === $locale) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizuje prazdny volitelny text na databazove null
     */
    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Pripravi volitelne root metadata bez lokalizacni mutace
     *
     * @param array<string,mixed> $metadata
     * @return array{authorName:string|null,recommended:bool,displaySettings:array<string,bool>}
     */
    private function prepareMetadata(array $metadata): array
    {
        $authorName = $this->nullableText($metadata['author_name'] ?? null);
        if ($authorName !== null && mb_strlen($authorName) > 255) {
            throw new RuntimeException('news.validation.author_max_length');
        }

        return [
            'authorName' => $authorName,
            'recommended' => in_array((string) ($metadata['recommended'] ?? ''), ['1', 'true', 'on'], true),
            'displaySettings' => $this->displaySettings($metadata),
        ];
    }

    /**
     * Vrati vychozi display nastaveni noveho draftu
     *
     * @return array<string,bool>
     */
    private function defaultDisplaySettings(): array
    {
        return [
            'show_author' => true,
            'sharing_enabled' => true,
            'show_published_at' => true,
            'show_featured_image' => true,
            'show_reading_time' => false,
        ];
    }

    /**
     * Normalizuje display nastaveni pred root mutaci
     *
     * @param array<string,mixed> $metadata
     * @return array<string,bool>
     */
    private function displaySettings(array $metadata): array
    {
        return [
            'show_author' => $this->enabledDisplaySetting('show_author', $metadata),
            'sharing_enabled' => $this->enabledDisplaySetting('sharing_enabled', $metadata),
            'show_published_at' => $this->enabledDisplaySetting('show_published_at', $metadata),
            'show_featured_image' => $this->enabledDisplaySetting('show_featured_image', $metadata),
            'show_reading_time' => in_array((string) ($metadata['show_reading_time'] ?? ''), ['1', 'true', 'on'], true),
        ];
    }

    /**
     * Vyhodnoti display toggle, ktery je pri chybejicim payloadu vychozi aktivni
     *
     * @param array<string,mixed> $metadata
     */
    private function enabledDisplaySetting(string $name, array $metadata): bool
    {
        return !array_key_exists($name, $metadata)
            || in_array((string) $metadata[$name], ['1', 'true', 'on'], true);
    }

    /**
     * Porovna display settings bez zapisu pri stejnem globalnim stavu
     *
     * @param array<string,mixed> $existing
     * @param array<string,bool> $settings
     */
    private function sameDisplaySettings(array $existing, array $settings): bool
    {
        foreach ($settings as $key => $value) {
            if ((int) $existing[$key] !== ($value ? 1 : 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalizuje textovy vstup na jedine lokalizovane stitky v poradi editoru
     *
     * @return list<string>
     */
    private function prepareTags(mixed $value): array
    {
        $parsedTags = preg_split('/[\r\n,]+/', (string) $value);
        $source = is_array($value) ? $value : ($parsedTags === false ? [] : $parsedTags);
        $tags = [];
        foreach ($source as $tag) {
            $name = trim((string) $tag);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 255) {
                throw new RuntimeException('news.validation.tag_max_length');
            }
            if (!in_array($name, $tags, true)) {
                $tags[] = $name;
            }
        }

        return $tags;
    }

    /**
     * Overi existenci stable root identity pred mutaci
     */
    private function assertArticle(int $articleId): void
    {
        if (!$this->news->articleExists($articleId)) {
            throw new RuntimeException('news.validation.not_found');
        }
    }
}
