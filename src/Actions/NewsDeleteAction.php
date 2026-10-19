<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Actions;

use DomainException;
use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\News\Services\NewsService;
use Lemonade\Framework\Http\HttpStatus as HttpStatusCode;

/**
 * Prevadi soft delete Aktuality na canonical Admin action response
 */
final readonly class NewsDeleteAction implements ModuleActionHandlerInterface
{
    /**
     * Nastavuje News lifecycle a localni identitu auditovane mutace
     */
    public function __construct(
        private NewsService $news,
        private LocalActorGuard $actors,
    ) {}

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
                $this->actors->requireAuditActor(),
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
