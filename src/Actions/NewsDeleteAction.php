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
use Lemonade\Framework\Http\HttpStatus as HttpStatusCode;

/**
 * Prevadi soft delete Aktuality na canonical Admin action response
 */
final readonly class NewsDeleteAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Nastavuje News lifecycle a localni identitu auditovane mutace
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
     * Overi soft delete globalnim nebo vlastnickym opravnenim
     */
    public function accessDecision(?int $id): bool
    {
        return $id !== null && $this->authorization->canForOwner(
            'cms.news.delete',
            'cms.news.delete_own',
            $this->model->ownerId($id),
        );
    }

    /**
     * Provede soft delete a prevede authorization chybu do Admin transportu
     *
     * @param array<string,mixed> $payload
     */
    public function execute(?int $id, array $payload): ModuleActionResult
    {
        if ($id === null) {
            throw new ModuleActionException(
                HttpStatusCode::NOT_FOUND,
                AdminErrorCode::NOT_FOUND,
                'News item not found.',
            );
        }

        try {
            $this->news->delete(
                $this->actors->requireLocalUser()->id(),
                $id,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatusCode::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (DomainException $exception) {
            return ModuleActionResult::invalid(['_form' => $exception->getMessage()]);
        }

        return ModuleActionResult::success('news.actions.deleted');
    }
}
