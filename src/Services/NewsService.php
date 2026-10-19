<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Services;

use DateTimeImmutable;
use DomainException;
use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\TransactionalEventCollector;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Cms\CmsRouteValidationException;
use Lemonade\Framework\Database\Exception\DatabaseException;
use Lemonade\Framework\Support\Slug\Slugger;
use RuntimeException;

/**
 * Orchestrace mutaci, publication lifecycle a auditu aggregate Aktualit
 */
final readonly class NewsService
{
    private const MAXIMUM_SLUG_LENGTH = 180;

    private const MAXIMUM_SAVE_ATTEMPTS = 3;

    /**
     * Nastavuje persistence, content locale, canonical routy a auditovane mutace
     */
    public function __construct(
        private NewsModel $news,
        private LanguageRegistry $languages,
        private CmsRouteReservationService $routes,
        private Slugger $slugger,
        private TransactionalEventProcessor $events,
    ) {}

    /**
     * Vytvori minimalni draft s null vychozi content mutaci vyzadovanou schematem
     */
    public function createDraft(AuditActor $actor, string $locale): int
    {
        return $this->events->execute($actor, function (TransactionalEventCollector $events) use ($locale): int {
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
                $locale,
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
     * Ulozi metadata, jednu lokalizaci, routu, tagy a publikaci v jedne transakci
     *
     * @param array<string,mixed> $data
     */
    public function save(AuditActor $actor, int $articleId, array $data): void
    {
        $metadata = $this->prepareMetadata($data);
        $locale = strtolower(trim((string) ($data['locale'] ?? '')));

        for ($attempt = 1; $attempt <= self::MAXIMUM_SAVE_ATTEMPTS; $attempt++) {
            try {
                $this->events->execute(
                    $actor,
                    function (TransactionalEventCollector $events) use ($articleId, $data, $metadata, $locale): void {
                        $existingMetadata = $this->news->metadataForUpdate($articleId);
                        if ($existingMetadata === null) {
                            throw new DomainException('news.validation.not_found');
                        }
                        $existingTranslation = $this->news->translationForUpdate($articleId, $locale);
                        $hasRoute = $this->routes->hasReservation('cms.news', $articleId, $locale);
                        $translation = $this->prepareTranslation($data, $existingTranslation, $hasRoute);
                        $metadataChanged = !$this->sameMetadata($existingMetadata, $metadata);
                        $translationChanged = !$hasRoute || !$this->sameTranslation($existingTranslation, $translation);
                        [$publicationState, $publishedAt, $publicationChanged] = $this->publicationChange(
                            $data,
                            $existingTranslation,
                        );

                        if (!$metadataChanged && !$translationChanged && !$publicationChanged) {
                            $events->noOp();

                            return;
                        }

                        $now = date('Y-m-d H:i:s');
                        if ($metadataChanged) {
                            $this->news->saveMetadata(
                                $articleId,
                                $metadata['authorName'],
                                $metadata['recommended'],
                                $metadata['displaySettings'],
                                $now,
                            );
                            $events->record(new DomainEvent(
                                'cms.news.updated',
                                'cms.news',
                                'cms_news_article',
                                (string) $articleId,
                            ));
                        }
                        if ($translationChanged) {
                            $this->savePreparedTranslation($articleId, $translation, $now);
                            $events->record(new DomainEvent(
                                $existingTranslation === null
                                    ? 'cms.news.translation_created'
                                    : 'cms.news.translation_updated',
                                'cms.news',
                                'cms_news_article',
                                (string) $articleId,
                                ['locale' => $locale],
                            ));
                        }
                        if ($publicationChanged) {
                            $this->news->savePublication(
                                $articleId,
                                $locale,
                                $publicationState,
                                $publishedAt,
                                $now,
                            );
                            $events->record(new DomainEvent(
                                $publicationState === CmsPublicationState::Published
                                    ? 'cms.news.published'
                                    : 'cms.news.unpublished',
                                'cms.news',
                                'cms_news_article',
                                (string) $articleId,
                                ['locale' => $locale],
                            ));
                        }
                    },
                );

                return;
            } catch (DatabaseException $exception) {
                if (!$this->isConcurrentSaveConflict($exception) || $attempt === self::MAXIMUM_SAVE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Normalizuje a explicitne zmeni canonical segment jedne jazykove mutace
     */
    public function changeUrlSegment(
        AuditActor $actor,
        int $articleId,
        string $locale,
        string $segment,
    ): string {
        $locale = strtolower(trim($locale));
        $segment = $this->slugger->slug($segment, self::MAXIMUM_SLUG_LENGTH, $locale);
        if ($segment === '') {
            throw new DomainException('admin.url_segment.invalid');
        }

        return $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($articleId, $locale, $segment): string {
                if ($this->news->metadataForUpdate($articleId) === null) {
                    throw new DomainException('news.validation.not_found');
                }
                $translation = $this->news->translationForUpdate($articleId, $locale);
                if ($translation === null && !$this->isEnabledLocale($locale)) {
                    throw new DomainException('news.validation.locale_invalid');
                }
                $currentPath = $this->routes->reservationPath('cms.news', $articleId, $locale);
                if ($currentPath !== null && basename($currentPath) === $segment) {
                    $events->noOp();

                    return $segment;
                }

                try {
                    $this->routes->reserveExplicit('cms.news', $articleId, $locale, $segment);
                } catch (CmsRouteValidationException $exception) {
                    throw $this->newsRouteValidation($exception);
                }
                $events->record(new DomainEvent(
                    'cms.news.updated',
                    'cms.news',
                    'cms_news_article',
                    (string) $articleId,
                    ['locale' => $locale],
                ));

                return $segment;
            },
        );
    }

    /**
     * Soft-deleteuje clanek bez odstraneni jeho lokalizaci a reservovanych rout
     */
    public function delete(AuditActor $actor, int $articleId): void
    {
        $article = $this->news->lifecycle($articleId);
        if ($article === null) {
            throw new DomainException('news.validation.not_found');
        }
        if ($article['deleted_at'] !== null) {
            return;
        }
        $this->events->execute($actor, function (TransactionalEventCollector $events) use ($articleId): void {
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
        $article = $this->news->lifecycle($articleId);
        if ($article === null) {
            throw new DomainException('news.validation.not_found');
        }
        if ($article['deleted_at'] === null) {
            return;
        }
        $this->events->execute($actor, function (TransactionalEventCollector $events) use ($articleId): void {
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
     * Pripravi mutation a base slug pouze pro prvni canonical reservation
     *
     * @param array<string,mixed> $translation
     * @param array<string,mixed>|null $existing
     * @return array{
     *     locale:string,
     *     translationId:int|null,
     *     title:string,
     *     pageTitle:string|null,
     *     baseSlug:string|null,
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
            throw new DomainException('news.validation.locale_invalid');
        }
        if ($title === '') {
            throw new DomainException('news.validation.title_required');
        }
        if (mb_strlen($title) > 180) {
            throw new DomainException('news.validation.title_max_length');
        }
        $baseSlug = $hasRoute
            ? null
            : $this->slugger->slug($title, self::MAXIMUM_SLUG_LENGTH, $locale);
        if ($baseSlug === '') {
            throw new DomainException('admin.url_segment.invalid');
        }

        return [
            'locale' => $locale,
            'translationId' => isset($existing['id']) ? (int) $existing['id'] : null,
            'title' => $title,
            'pageTitle' => $this->nullableText($translation['page_title'] ?? null),
            'baseSlug' => $baseSlug,
            'requiresRouteReservation' => !$hasRoute,
            'summary' => $this->nullableText($translation['summary'] ?? null),
            'content' => $this->nullableText($translation['content'] ?? null),
            'metaDescription' => $this->nullableText($translation['meta_description'] ?? null),
            'tags' => $this->prepareTags($translation['tags'] ?? null),
        ];
    }

    /**
     * Ulozi pripravenou mutation a pri prvnim save rezervuje jeji canonical route
     *
     * @param array{
     *     locale:string,
     *     translationId:int|null,
     *     title:string,
     *     pageTitle:string|null,
     *     baseSlug:string|null,
     *     requiresRouteReservation:bool,
     *     summary:string|null,
     *     content:string|null,
     *     metaDescription:string|null,
     *     tags:list<string>,
     * } $translation
     */
    private function savePreparedTranslation(int $articleId, array $translation, string $now): void
    {
        if ($translation['requiresRouteReservation']) {
            $baseSlug = $translation['baseSlug'];
            if ($baseSlug === null) {
                throw new \LogicException('Prepared News translation has no canonical slug.');
            }
            $this->routes->reserveAutomatic(
                moduleCode: 'cms.news',
                entityId: $articleId,
                locale: $translation['locale'],
                baseSlug: $baseSlug,
                maximumSlugLength: self::MAXIMUM_SLUG_LENGTH,
            );
        }
        $translationId = $this->news->saveTranslation(
            $articleId,
            $translation['locale'],
            $translation['title'],
            $translation['pageTitle'],
            $translation['summary'],
            $translation['content'],
            $translation['metaDescription'],
            $now,
            $translation['translationId'],
        );
        if ($this->news->translationTags($articleId, $translation['locale']) !== $translation['tags']) {
            $this->news->syncTranslationTags($translationId, $translation['tags'], $now);
        }
        $this->news->promoteTranslationToDraft($articleId, $translation['locale'], $now);
    }

    /**
     * Porovna business hodnoty mutace bez persistence timestampu
     *
     * @param array<string,mixed>|null $existing
     * @param array{
     *     locale:string,
     *     translationId:int|null,
     *     title:string,
     *     pageTitle:string|null,
     *     baseSlug:string|null,
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
            && $existing['summary'] === $prepared['summary']
            && $existing['content'] === $prepared['content']
            && $existing['meta_description'] === $prepared['metaDescription']
            && $this->news->translationTags(
                (int) $existing['article_id'],
                $prepared['locale'],
            ) === $prepared['tags'];
    }

    /**
     * Porovna root metadata bez persistence timestampu
     *
     * @param array<string,mixed> $existing
     * @param array{authorName:string|null,recommended:bool,displaySettings:array<string,bool>} $prepared
     */
    private function sameMetadata(array $existing, array $prepared): bool
    {
        return $existing['author_name'] === $prepared['authorName']
            && (int) $existing['recommended'] === ($prepared['recommended'] ? 1 : 0)
            && $this->sameDisplaySettings($existing, $prepared['displaySettings']);
    }

    /**
     * Vyhodnoti explicitne predanou zmenu publikace proti zamcene lokalizaci
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $existing
     * @return array{CmsPublicationState,string|null,bool}
     */
    private function publicationChange(array $data, ?array $existing): array
    {
        $currentState = CmsPublicationState::tryFrom(
            (int) ($existing['publication_state'] ?? CmsPublicationState::New->value),
        ) ?? CmsPublicationState::New;
        $currentPublishedAt = $this->nullableText($existing['published_at'] ?? null);
        if (!array_key_exists('publication_state', $data)) {
            return [$currentState, $currentPublishedAt, false];
        }

        $requestedState = CmsPublicationState::tryFrom((int) $data['publication_state']);
        if ($requestedState === null) {
            throw new DomainException('news.validation.publication_invalid');
        }
        if ($requestedState === CmsPublicationState::New) {
            return [$currentState, $currentPublishedAt, false];
        }

        $publishedAtInput = trim((string) ($data['published_at'] ?? ''));
        $publishedAt = null;
        if ($requestedState === CmsPublicationState::Published) {
            $publishedAt = $publishedAtInput === ''
                ? ($currentState === CmsPublicationState::Published
                    ? $currentPublishedAt ?? date('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s'))
                : $this->publicationDateTime($publishedAtInput);
        }

        return [
            $requestedState,
            $publishedAt,
            $requestedState !== $currentState || $publishedAt !== $currentPublishedAt,
        ];
    }

    /**
     * Rozpozna soubezne kolize canonical routy nebo identity prekladu
     */
    private function isConcurrentSaveConflict(DatabaseException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'uq_cms_route_locale_path')
            || str_contains($message, 'uq_cms_route_target')
            || str_contains($message, 'uq_cms_news_article_translation_locale');
    }

    /**
     * Prelozi shared route chybu na validacni klic Aktualit
     */
    private function newsRouteValidation(CmsRouteValidationException $exception): DomainException
    {
        return new DomainException(match ($exception->getMessage()) {
            'cms.route.path_reserved' => 'admin.url_segment.reserved',
            'cms.route.path_occupied' => 'admin.url_segment.occupied',
            default => 'admin.url_segment.invalid',
        }, previous: $exception);
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
     * Prevede native editorovy datetime na canonical databazovy format aplikace
     */
    private function publicationDateTime(string $value): string
    {
        $datetime = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', trim($value));
        if ($datetime === false || $datetime->format('Y-m-d\\TH:i') !== trim($value)) {
            throw new DomainException('news.validation.publication_invalid');
        }

        return $datetime->format('Y-m-d H:i:s');
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
            throw new DomainException('news.validation.author_max_length');
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
            'show_thumbnail' => true,
            'show_reading_time' => true,
            'show_tags' => true,
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
            'show_thumbnail' => $this->enabledDisplaySetting('show_thumbnail', $metadata),
            'show_reading_time' => $this->enabledDisplaySetting('show_reading_time', $metadata),
            'show_tags' => $this->enabledDisplaySetting('show_tags', $metadata),
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
        if (is_array($value)) {
            $source = $value;
        } else {
            $parsedTags = preg_split('/[\r\n,]+/', (string) $value);
            $source = $parsedTags === false ? [] : $parsedTags;
        }
        $tags = [];
        foreach ($source as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $name = trim($tag);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 255) {
                throw new DomainException('news.validation.tag_max_length');
            }
            if (!in_array($name, $tags, true)) {
                $tags[] = $name;
            }
        }

        return $tags;
    }
}
