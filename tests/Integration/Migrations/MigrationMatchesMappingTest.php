<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNotice;
use JpmMartin\SyliusNmiPlugin\Entity\NmiReceivedEvent;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransaction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The mapping is the truth; the migration is held to it here.
 *
 * For each of the plugin's four tables, the table the ORM expects from the mapping is compared with
 * the table that actually exists, and any statement the platform would need to bring the second in
 * line with the first is a failure — a column, a default, an index, a foreign-key rule. The
 * comparison is the same one `doctrine:schema:validate` and `doctrine:migrations:diff` perform,
 * narrowed to this plugin's tables so that whatever Sylius's own schema does is not this test's
 * verdict.
 *
 * It means something only on a database the plugin's migration built. Continuous integration
 * builds the test database with `doctrine:migrations:migrate` before PHPUnit runs, on PostgreSQL
 * and on MySQL, and `composer database-reset` does the same locally. On a database made by
 * `doctrine:schema:create` this passes without proving anything about the migration.
 */
final class MigrationMatchesMappingTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;
    }

    /**
     * @dataProvider entities
     *
     * @param class-string $entity
     */
    public function testTheTableTheMigrationBuiltIsTheOneTheMappingDescribes(string $entity): void
    {
        $metadata = $this->manager->getClassMetadata($entity);
        $expected = (new SchemaTool($this->manager))->getSchemaFromMetadata([$metadata])->getTable($metadata->getTableName());

        $connection = $this->manager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        self::assertTrue(
            $schemaManager->tablesExist([$metadata->getTableName()]),
            sprintf('The table %s does not exist. Was the database built by the plugin\'s migration?', $metadata->getTableName()),
        );
        $actual = $schemaManager->introspectTable($metadata->getTableName());

        $difference = $schemaManager->createComparator()->compareTables($actual, $expected);

        self::assertSame(
            [],
            $connection->getDatabasePlatform()->getAlterTableSQL($difference),
            sprintf('%s differs from what the mapping of %s describes; the statements above are what it would take to match.', $metadata->getTableName(), $entity),
        );
    }

    /** @return iterable<string, array{class-string}> */
    public static function entities(): iterable
    {
        yield 'the transaction log' => [NmiTransaction::class];
        yield 'the stored cards' => [NmiStoredCard::class];
        yield 'the received events' => [NmiReceivedEvent::class];
        yield 'the gateway notices' => [NmiGatewayNotice::class];
    }
}
