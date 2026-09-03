<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

class NmiTransactionRepository extends EntityRepository implements NmiTransactionRepositoryInterface
{
    public function findByTransactionId(string $transactionId): array
    {
        /** @var list<NmiTransactionInterface> $transactions */
        $transactions = $this->findBy(['transactionId' => $transactionId], ['id' => 'ASC']);

        return $transactions;
    }
}
