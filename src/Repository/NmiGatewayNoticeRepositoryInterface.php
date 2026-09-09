<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiGatewayNoticeInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends RepositoryInterface<NmiGatewayNoticeInterface>
 */
interface NmiGatewayNoticeRepositoryInterface extends RepositoryInterface
{
}
