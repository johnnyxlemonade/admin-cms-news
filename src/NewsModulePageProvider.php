<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\EditorLoaded;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Admin\Page\ModulePage;
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Builds News index and editor pages from shared Admin components
 */
final readonly class NewsModulePageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface
{
    /**
     * Receives News grid, editor and shared Admin presentation services
     */
    public function __construct(
        private NewsDataGrid $grid,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
        private NewsAdminEditorDefinitionFactory $adminEditorDefinitions,
        private AdminFileUploadComponent $files,
        private CmsContentReadModel $news,
        private LocalUserDirectory $users,
    ) {}

    /**
     * Returns the permission required to open the News index
     */
    public function indexPermission(): string
    {
        return 'cms.news.view_own';
    }

    /**
     * Builds the News index with an authorized create action
     */
    public function index(string $locale): ModulePage
    {
        $viewModel = new DataGridIndexViewModel(
            moduleCode: 'news',
            title: $this->translator->get('news.list.title'),
            description: $this->translator->get('news.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => 'news'],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $this->createPrimaryAction(),
            loadingText: $this->translator->get('news.list.loading'),
            emptyText: $this->translator->get('news.list.empty'),
            errorText: $this->translator->get('admin.datagrid.errorDescription'),
        );

        return new ModulePage(
            view: 'admin::datagrid.index',
            title: $viewModel->title(),
            data: ['dataGrid' => $viewModel, 'locale' => $locale],
        );
    }

    /**
     * Rejects the unused full-page create route because drafts use the index action
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function create(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        throw new EditorEntityNotFoundException('News does not provide a full-page create editor.');
    }

    /**
     * Builds the full-page editor for an existing News article
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        $locales = $editor->data()['locales'];
        $selected = $this->resolveContentLocale($locales, $query);
        $article = $this->articleForLocale($editor->data()['article'], $selected['code']);
        $translation = $article['translations'][0] ?? [];
        $ownerId = $article['owner_id'] === null ? null : (int) $article['owner_id'];
        $canAssignOwner = $this->authorization->hasPermission('cms.news.assign_owner')
            && $this->authorization->canForOwner('cms.news.view', 'cms.news.view_own', $ownerId);
        $canEdit = $this->authorization->canForOwner('cms.news.edit', 'cms.news.edit_own', $ownerId);

        return new ModulePage(
            view: 'admin::editor',
            title: $this->translator->get('news.editor.title'),
            data: [
                'adminEditor' => $this->createAdminEditor(
                    article: $article,
                    contentLocale: $selected['code'],
                    locales: $locales,
                    ownerId: $ownerId,
                    canAssignOwner: $canAssignOwner,
                    canEdit: $canEdit,
                ),
                'adminEditorContext' => $this->createRenderContext(
                    article: $article,
                    translation: $translation,
                    ownerId: $ownerId,
                    input: $input,
                    errors: $errors,
                ),
                'locale' => $locale,
            ],
        );
    }

    private function createPrimaryAction(): ?DataGridPrimaryAction
    {
        if (!$this->authorization->hasPermission('cms.news.create')) {
            return null;
        }

        return DataGridPrimaryAction::action(
            translationKey: 'news.actions.create',
            icon: AdminIcon::PlusLg,
            form: new AdminActionFormDefinition(
                id: 'news-create-draft-form',
                action: $this->urls->route(
                    name: $this->routes->managementRouteName('news', 'store'),
                    params: ['module' => 'news'],
                ),
                actionUrl: $this->urls->route(
                    name: $this->routes->managementRouteName('news', 'ajax.create'),
                    params: ['module' => 'news'],
                ),
                actionKey: 'create',
                navigateToEditAfterCreate: true,
            ),
        );
    }

    /**
     * @param array{id:int,translations:list<array<string,mixed>>} $article
     * @param list<array<string,mixed>> $locales
     */
    private function createAdminEditor(
        array $article,
        string $contentLocale,
        array $locales,
        ?int $ownerId,
        bool $canAssignOwner,
        bool $canEdit,
    ): AdminEditorDefinition {
        $articleId = (int) $article['id'];

        return $this->adminEditorDefinitions->create(
            article: $article,
            contentLocale: $contentLocale,
            canPublish: $this->authorization->canForOwner(
                'cms.news.publish',
                'cms.news.publish_own',
                $ownerId,
            ),
            canAssignOwner: $canAssignOwner,
            ownerOptions: $canAssignOwner ? $this->ownerOptions() : [],
            context: $this->localeContext($locales, $contentLocale, $articleId),
            featuredImageUpload: $this->files->collection(
                module: 'cms.news',
                entityId: $articleId,
                usage: 'thumbnail',
                labelKey: 'news.fields.thumbnail',
                helpKey: 'news.editor.thumbnail.help',
                editable: $canEdit,
                alt: (string) ($article['translations'][0]['title'] ?? ''),
            ),
            galleryUpload: $this->files->collection(
                module: 'cms.news',
                entityId: $articleId,
                usage: 'gallery',
                labelKey: 'admin.file_upload.gallery',
                helpKey: 'admin.file_upload.gallery_help',
                editable: $canEdit,
            ),
            attachmentUpload: $this->files->collection(
                module: 'cms.news',
                entityId: $articleId,
                usage: 'attachment',
                labelKey: 'admin.file_upload.attachments',
                helpKey: 'admin.file_upload.attachments_help',
                editable: $canEdit,
            ),
            tagOptions: $this->news->tagNamesForLocale($contentLocale),
        );
    }

    /**
     * @param array<string,mixed> $article
     * @param array<string,mixed> $translation
     * @param array<string,mixed> $input
     * @param array<string,string> $errors
     */
    private function createRenderContext(
        array $article,
        array $translation,
        ?int $ownerId,
        array $input,
        array $errors,
    ): AdminEditorRenderContext {
        $canonicalPath = trim((string) ($translation['canonical_path'] ?? ''), '/');

        return new AdminEditorRenderContext(
            values: [
                'title' => (string) ($translation['title'] ?? ''),
                'author_name' => (string) ($article['author_name'] ?? ''),
                'tags' => $translation['tags'] ?? [],
                'summary' => (string) ($translation['summary'] ?? ''),
                'content' => (string) ($translation['content'] ?? ''),
                'page_title' => (string) ($translation['page_title'] ?? ''),
                'meta_description' => (string) ($translation['meta_description'] ?? ''),
                'url_segment' => $canonicalPath === '' ? '' : basename($canonicalPath),
                ...CmsDisplaySettings::values($article),
                'publication_state' => CmsPublicationState::from(
                    (int) ($translation['publication_state'] ?? CmsPublicationState::New->value),
                )->getEditorValue(),
                'published_at' => (string) ($translation['published_at'] ?? ''),
                'created_by_name' => (string) ($article['created_by_name'] ?? '—'),
                'created_at' => (string) ($article['created_at'] ?? ''),
                'updated_by_name' => (string) ($article['updated_by_name'] ?? '—'),
                'updated_at' => (string) ($article['updated_at'] ?? ''),
                'owner_name' => (string) ($article['owner_name'] ?? '—'),
                'owner_id' => $ownerId === null ? '' : (string) $ownerId,
                'deleted_by_name' => (string) ($article['deleted_by_name'] ?? '—'),
                'deleted_at' => (string) ($article['deleted_at'] ?? ''),
            ],
            oldInput: $input,
            errors: $errors,
            mode: 'edit',
            moduleCode: 'cms.news',
            entityId: (int) $article['id'],
        );
    }

    /**
     * @param array{id:int,owner_id:int|null,translations:list<array<string,mixed>>} $article
     * @return array{id:int,owner_id:int|null,translations:list<array<string,mixed>>}
     */
    private function articleForLocale(array $article, string $contentLocale): array
    {
        $article['translations'] = array_values(array_filter(
            $article['translations'],
            static fn(array $translation): bool => $translation['locale'] === $contentLocale,
        ));

        return $article;
    }

    /**
     * Returns assignable local users as owner options
     *
     * @return array<int,string>
     */
    private function ownerOptions(): array
    {
        $options = [];
        foreach ($this->users->assignableUsers() as $user) {
            $options[$user['id']] = $user['label'];
        }

        return $options;
    }

    /**
     * Selects an available content locale from the request context
     *
     * @param list<array<string,mixed>> $locales
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function resolveContentLocale(array $locales, array $query): array
    {
        $requested = strtolower(trim((string) ($query['locale'] ?? '')));
        if ($requested !== '') {
            foreach ($locales as $locale) {
                if ($locale['code'] === $requested) {
                    return $locale;
                }
            }

            throw new EditorEntityNotFoundException('News content locale is not available.');
        }

        foreach ($locales as $locale) {
            if ((int) ($locale['is_default'] ?? 0) === 1) {
                return $locale;
            }
        }

        return $locales[0] ?? throw new EditorEntityNotFoundException('News content locale is not available.');
    }

    /**
     * Builds editor links for available content locales
     *
     * @param list<array<string,mixed>> $locales
     */
    private function localeContext(array $locales, string $selectedLocale, ?int $articleId): AdminEditorHeaderContextDefinition
    {
        $items = [];
        foreach ($locales as $locale) {
            $code = (string) $locale['code'];
            $enabled = (int) ($locale['enabled'] ?? 1) === 1;
            $hasTranslation = (int) ($locale['has_translation'] ?? 0) === 1;
            $items[] = new AdminEditorHeaderContextItem(
                label: (string) $locale['name'],
                href: $this->urls->route(
                    name: $this->routes->managementRouteName('news', 'edit'),
                    params: ['module' => 'news', 'id' => $articleId, 'locale' => $code],
                ),
                active: $code === $selectedLocale,
                secondary: !$enabled
                    ? $this->translator->get('news.editor.locale.disabled')
                    : ($hasTranslation
                        ? $this->translator->get('news.editor.locale.existing_translation')
                        : $this->translator->get('news.editor.locale.new_translation')),
                code: $code,
                translationExists: $hasTranslation,
            );
        }

        return new AdminEditorHeaderContextDefinition(
            label: $this->translator->get('news.editor.locale.label'),
            items: $items,
        );
    }
}
