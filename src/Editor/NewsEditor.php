<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDefinition;
use Lemonade\Admin\Editor\EditorFieldDefinition;
use Lemonade\Admin\Editor\EditorSaveResult;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Editor\Exception\EditorValidationException;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\News\Services\NewsService;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Propojuje News full-page editor s canonical lifecycle a mutacemi obsahu
 */
final class NewsEditor implements EditorProviderInterface
{
    /**
     * Nastavuje News persistence, mutation service, preklady a auditni identitu editoru
     */
    public function __construct(
        private readonly NewsModel $news,
        private readonly NewsService $service,
        private readonly LanguageRegistry $languages,
        private readonly LocalActorGuard $actors,
        private readonly NewsEditorValidationSchema $validation,
        private readonly AdminModuleRouteResolver $routes,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * Deklaruje transportni pole editoru vcetne dynamic translations payloadu
     */
    public function editorDefinition(): EditorDefinition
    {
        return new EditorDefinition(
            loadPermission: 'cms.news.view',
            savePermission: 'cms.news.edit',
            createPermission: 'cms.news.create',
            fields: [
                new EditorFieldDefinition(name: 'state', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'published_at', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'author_name', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'recommended', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_author', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'sharing_enabled', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_published_at', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_featured_image', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_reading_time', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'locale', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'summary', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'content', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'page_title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'meta_description', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'tags', type: 'text', readOnly: false, permission: null),
            ],
        );
    }

    /**
     * Nacte existujici News aggregate pro editaci
     */
    public function updateData(int $id): array
    {
        $article = $this->news->findForEditor($id);
        if ($article === null) {
            throw new EditorEntityNotFoundException('News article not found.');
        }

        return ['article' => $article, 'locales' => $this->news->editorLocales($id)];
    }

    /**
     * Vrati prazdny draft v canonical default content locale
     */
    public function createData(): array
    {
        $locales = $this->languages->enabledLocales();

        return [
            'article' => [
                'id' => null,
                'author_name' => null,
                'recommended' => 0,
                'show_author' => 1,
                'sharing_enabled' => 1,
                'show_published_at' => 1,
                'show_featured_image' => 1,
                'show_reading_time' => 0,
                'state' => 'draft',
                'published_at' => null,
                'translations' => [],
            ],
            'locales' => $locales,
        ];
    }

    /**
     * Vrati validaci pro zalozeni draftu s jednou aktualni content mutation
     */
    public function createValidationSchema(): ValidationSchema
    {
        return $this->validation->forCreate();
    }

    /**
     * Vrati validaci pro zmenu globalnich dat a aktualni content mutation
     */
    public function updateValidationSchema(int $id): ValidationSchema
    {
        return $this->validation->forUpdate();
    }

    /**
     * Ulozi predane neprazdne mutation a lifecycle clanku
     */
    public function update(int $id, array $data): EditorSaveResult
    {
        try {
            $actor = AuditActor::user($this->actors->requireLocalUser()->id());
            $this->service->saveMetadata($actor, $id, $data);
            foreach ($this->translations($data) as $translation) {
                $this->service->saveTranslation($actor, $id, $translation);
            }
            if (($data['state'] ?? '') !== '') {
                $publishedAt = $data['published_at'] ?? null;
                $this->service->changePublication(
                    $actor,
                    $id,
                    (string) $data['state'],
                    $publishedAt !== '' ? $publishedAt : null,
                );
            }
        } catch (\RuntimeException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: ['id' => $id, 'editUrl' => $this->editUrl($id, (string) $data['locale'])],
            messageKey: 'news.editor.updated',
        );
    }

    /**
     * Zalozi pojmenovany draft z prvni lokalizovane mutation
     */
    public function create(array $data): EditorSaveResult
    {
        $translations = $this->translations($data);
        try {
            $firstTranslation = $translations[0] ?? throw new \RuntimeException('news.validation.translation_required');
            $actor = AuditActor::user($this->actors->requireLocalUser()->id());
            $articleId = $this->service->create($actor, $firstTranslation);
        } catch (\RuntimeException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $articleId,
                'editUrl' => $this->editUrl($articleId, (string) $firstTranslation['locale']),
            ],
            messageKey: 'news.editor.created',
        );
    }

    /**
     * Normalizuje aktualni content mutation pro canonical save cestu
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function translations(array $data): array
    {
        if (trim((string) ($data['title'] ?? '')) !== '') {
            return [[
                'locale' => $data['locale'] ?? '',
                'title' => $data['title'] ?? '',
                'summary' => $data['summary'] ?? '',
                'content' => $data['content'] ?? '',
                'page_title' => $data['page_title'] ?? '',
                'meta_description' => $data['meta_description'] ?? '',
                'tags' => $data['tags'] ?? '',
            ]];
        }
        return [];
    }

    /**
     * Vytvori canonical edit URL se zachovanym content locale
     */
    private function editUrl(int $articleId, string $locale): string
    {
        return $this->urls->route(
            $this->routes->managementRouteName('news', 'edit'),
            ['module' => 'news', 'id' => $articleId, 'locale' => $locale],
        );
    }
}
