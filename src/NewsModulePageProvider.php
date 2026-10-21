<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
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
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada indexovou stranku News managementu nad shared DataGrid transportem
 */
final readonly class NewsModulePageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface
{
    /**
     * Nastavuje grid, authorization, preklady a URL pro News index
     */
    public function __construct(
        private NewsDataGrid $grid,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
        private NewsAdminEditorDefinitionFactory $adminEditorDefinitions,
        private AdminFileUploadComponent $files,
        private NewsModel $news,
        private LocalUserDirectory $users,
    ) {}

    /**
     * Vrati pravo potrebne pro otevreni News indexu
     */
    public function indexPermission(): string
    {
        return 'cms.news.view_own';
    }

    /**
     * Vytvori standardni News index s autorizovanou create akci
     */
    public function index(string $locale): ModulePage
    {
        $primaryAction = $this->authorization->hasPermission('cms.news.create')
            ? DataGridPrimaryAction::action(
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
            )
            : null;
        $viewModel = new DataGridIndexViewModel(
            moduleCode: 'news',
            title: $this->translator->get('news.list.title'),
            description: $this->translator->get('news.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => 'news'],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $primaryAction,
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
     * News nema full-page create presentation; draft se zaklada primary action
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
     * Vytvori full-page editor existujici News entity
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        return $this->editorPage(
            editor: $editor,
            errors: $errors,
            input: $input,
            locale: $locale,
            query: $query,
        );
    }

    /**
     * Pripravi package-owned editor view pro jeden content locale context
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    private function editorPage(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        $locales = $editor->data()['locales'];
        $selected = $this->resolveContentLocale($locales, $query);
        $article = $editor->data()['article'];
        $article['translations'] = array_values(array_filter(
            $article['translations'],
            static fn(array $translation): bool => $translation['locale'] === $selected['code'],
        ));
        $translation = $article['translations'][0] ?? [];
        $canonicalPath = trim((string) ($translation['canonical_path'] ?? ''), '/');
        $context = $this->localeContext(
            locales: $locales,
            selectedLocale: $selected['code'],
            articleId: $article['id'],
        );
        $ownerId = $article['owner_id'] === null ? null : (int) $article['owner_id'];
        $canAssignOwner = $this->authorization->hasPermission('cms.news.assign_owner')
            && $this->authorization->canForOwner('cms.news.view', 'cms.news.view_own', $ownerId);

        return new ModulePage(
            view: 'admin::editor',
            title: $this->translator->get('news.editor.title'),
            data: [
                'adminEditor' => $this->adminEditorDefinitions->create(
                    article: $article,
                    contentLocale: $selected['code'],
                    canPublish: $this->authorization->canForOwner(
                        'cms.news.publish',
                        'cms.news.publish_own',
                        $ownerId,
                    ),
                    canAssignOwner: $canAssignOwner,
                    ownerOptions: $canAssignOwner ? $this->ownerOptions() : [],
                    context: $context,
                    featuredImageUpload: $this->files->collection(
                        module: 'cms.news',
                        entityId: (int) $article['id'],
                        usage: 'thumbnail',
                        labelKey: 'news.fields.thumbnail',
                        helpKey: 'news.editor.thumbnail.help',
                        alt: (string) ($article['translations'][0]['title'] ?? ''),
                    ),
                    galleryUpload: $this->files->collection('cms.news', (int) $article['id'], 'gallery', 'admin.file_upload.gallery', 'admin.file_upload.gallery_help'),
                    attachmentUpload: $this->files->collection('cms.news', (int) $article['id'], 'attachment', 'admin.file_upload.attachments', 'admin.file_upload.attachments_help'),
                    tagOptions: $this->news->tagNamesForLocale($selected['code']),
                ),
                'adminEditorContext' => new AdminEditorRenderContext(
                    values: [
                        'title' => (string) ($translation['title'] ?? ''),
                        'author_name' => (string) ($article['author_name'] ?? ''),
                        'tags' => $translation['tags'] ?? [],
                        'summary' => (string) ($translation['summary'] ?? ''),
                        'content' => (string) ($translation['content'] ?? ''),
                        'page_title' => (string) ($translation['page_title'] ?? ''),
                        'meta_description' => (string) ($translation['meta_description'] ?? ''),
                        'url_segment' => $canonicalPath === '' ? '' : basename($canonicalPath),
                        'recommended' => (string) ((int) ($article['recommended'] ?? 0)),
                        'show_author' => (string) ((int) ($article['show_author'] ?? 0)),
                        'sharing_enabled' => (string) ((int) ($article['sharing_enabled'] ?? 0)),
                        'show_published_at' => (string) ((int) ($article['show_published_at'] ?? 0)),
                        'show_thumbnail' => (string) ((int) ($article['show_thumbnail'] ?? 0)),
                        'show_reading_time' => (string) ((int) ($article['show_reading_time'] ?? 0)),
                        'show_tags' => (string) ((int) ($article['show_tags'] ?? 0)),
                        'publication_state' => (string) ($translation['publication_state'] ?? 0),
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
                ),
                'locale' => $locale,
            ],
        );
    }

    /**
     * Sestavi options nesmazanych lokalnich uzivatelu pro ownership transfer
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
     * Vybere povoleny content locale podle explicitniho query contextu
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
     * Vytvori linkove prepinani dostupnych content locales editoru
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
