<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use DomainException;
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
final readonly class NewsEditor implements EditorProviderInterface
{
    /**
     * Nastavuje News persistence, mutation service, preklady a auditni identitu editoru
     */
    public function __construct(
        private NewsModel $news,
        private NewsService $service,
        private LanguageRegistry $languages,
        private LocalActorGuard $actors,
        private NewsEditorValidationSchema $validation,
        private AdminModuleRouteResolver $routes,
        private UrlGenerator $urls,
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
                new EditorFieldDefinition(name: 'publication_state', type: 'text', readOnly: false, permission: 'cms.news.publish'),
                new EditorFieldDefinition(name: 'published_at', type: 'text', readOnly: false, permission: 'cms.news.publish'),
                new EditorFieldDefinition(name: 'author_name', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'recommended', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_author', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'sharing_enabled', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_published_at', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_thumbnail', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_reading_time', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'show_tags', type: 'text', readOnly: false, permission: null),
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
     * Vrati prazdny editorovy stav pro nepouzitou fallback create route
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
                'show_thumbnail' => 1,
                'show_reading_time' => 1,
                'show_tags' => 1,
                'translations' => [],
            ],
            'locales' => $locales,
        ];
    }

    /**
     * Vrati prazdnou validaci pro okamzite zalozeni draftu bez vstupu uzivatele
     */
    public function createValidationSchema(): ValidationSchema
    {
        return ValidationSchema::create();
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
            $actor = $this->actors->requireAuditActor();
            $this->service->save($actor, $id, $data);
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: ['id' => $id, 'editUrl' => $this->editUrl($id, (string) $data['locale'])],
            messageKey: 'news.editor.updated',
        );
    }

    /**
     * Zalozi minimalni draft v canonical vychozim content locale
     */
    public function create(array $data): EditorSaveResult
    {
        try {
            $actor = $this->actors->requireAuditActor();
            $locale = $this->languages->defaultLocale();
            $articleId = $this->service->createDraft($actor, $locale);
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $articleId,
                'editUrl' => $this->editUrl($articleId, $locale),
            ],
            messageKey: 'news.editor.created',
        );
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
