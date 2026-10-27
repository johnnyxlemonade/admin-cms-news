<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Actions;

use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\Cms\Content\Action\CmsContentAction;
use Lemonade\Admin\Cms\Content\Action\CmsContentActionType;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Cms\Content\CmsContentDefinition;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje vlastnickou access policy samostatneho predani Aktuality
 */
final class NewsAssignOwnerActionTest extends TestCase
{
    /**
     * Povoluje predani osirele Aktuality pouze s globalnim view opravnenim
     */
    public function testOrphanAssignmentRequiresGlobalView(): void
    {
        self::assertTrue($this->action(null, ['cms.news.view', 'cms.news.assign_owner'])->accessDecision(7));
        self::assertFalse($this->action(null, ['cms.news.view_own', 'cms.news.assign_owner'])->accessDecision(7));
    }

    /**
     * Po obnoveni uzivatele rozhoduje pouze jeho aktualni vlastnictvi
     */
    public function testRestoredUserAccessDependsOnCurrentOwner(): void
    {
        $permissions = ['cms.news.view_own', 'cms.news.assign_owner'];

        self::assertTrue($this->action(42, $permissions)->accessDecision(7));
        self::assertFalse($this->action(9, $permissions)->accessDecision(7));
    }

    /**
     * Sestavi action nad zvolenym vlastnikem a efektivnimi permissions
     *
     * @param list<string> $permissions
     */
    private function action(?int $ownerId, array $permissions): CmsContentAction
    {
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('row_array')->willReturn(['owner_id' => $ownerId]);
        $result->method('result_array')->willReturn([['owner_id' => $ownerId]]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturn($result);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(
            static function (string $sql) use ($permissions): array {
                if (!str_contains($sql, 'system_role_permission')) {
                    return [];
                }

                return array_map(
                    static fn(string $code): array => ['code' => $code, 'name_key' => $code, 'module_code' => 'cms.news'],
                    $permissions,
                );
            },
        );
        $principal = $this->createMock(CurrentPrincipalProviderInterface::class);
        $principal->method('currentUser')->willReturn(new AuthenticatedUser(42, 'owner@example.test'));

        return new CmsContentAction(
            $this->instanceWithoutConstructor(CmsContentLifecycle::class),
            new CmsContentReadModel(new Database($connection, $driver), new CmsContentDefinition('cms.news', 'item', 'item', 'translation', 'item_id', 'tag', 'unique_key', 'news', \stdClass::class)),
            $this->instanceWithoutConstructor(LocalActorGuard::class),
            new AuthorizationService($principal, new Database($connection, $driver)),
            CmsContentActionType::AssignOwner,
            'cms.news',
            'news',
        );
    }

    /**
     * Vytvori dependency-only instanci bez spusteni nesouvisejiciho konstruktoru
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
