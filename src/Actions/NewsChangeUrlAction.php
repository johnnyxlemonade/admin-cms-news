<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Actions;

use DomainException;
use Lemonade\Admin\Action\Contract\ModuleActionAccessPolicyInterface;
use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\News\NewsData;
use Lemonade\Framework\Http\HttpStatus;

/**
 * Prevadi explicitni zmenu URL Aktuality na canonical Admin action
 */
final readonly class NewsChangeUrlAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Nastavuje News routy a lokalni auditni identitu
     *
     * @param CmsContentLifecycle<NewsData> $news
     */
    public function __construct(
        private CmsContentLifecycle $news,
        private LocalActorGuard $actors,
        private NewsModel $model,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Overi editaci URL globalnim nebo vlastnickym opravnenim
     */
    public function accessDecision(?int $id): bool
    {
        return $id !== null && $this->authorization->canForOwner(
            'cms.news.edit',
            'cms.news.edit_own',
            $this->model->ownerId($id),
        );
    }

    /**
     * Ulozi pouze URL segment a ponecha ostatni editorova data beze zmeny
     *
     * @param array<string,mixed> $payload
     */
    public function execute(?int $id, array $payload): ModuleActionResult
    {
        if ($id === null) {
            throw new ModuleActionException(
                HttpStatus::NOT_FOUND,
                AdminErrorCode::NOT_FOUND,
                'News item not found.',
            );
        }
        $locale = $payload['locale'] ?? null;
        $segment = $payload['url_segment'] ?? null;
        if (!is_string($locale) || !is_string($segment)) {
            return ModuleActionResult::invalid(['url_segment' => 'admin.url_segment.invalid']);
        }

        try {
            $urlSegment = $this->news->changeUrlSegment(
                $this->actors->requireLocalUser()->id(),
                $id,
                $locale,
                $segment,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatus::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (DomainException $exception) {
            return ModuleActionResult::invalid(['url_segment' => $exception->getMessage()]);
        }

        return ModuleActionResult::success(
            'admin.url_segment.updated',
            ['urlSegment' => $urlSegment],
        );
    }
}
