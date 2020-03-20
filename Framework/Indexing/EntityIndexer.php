<?php
declare(strict_types=1);

namespace Shopware\Elasticsearch\Framework\Indexing;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\IndexerInterface;

/**
 * @deprecated tag:v6.3.0 - Use \Shopware\Elasticsearch\Framework\Indexing\ElasticsearchIndexer instead
 */
class EntityIndexer implements IndexerInterface
{
    public function index(\DateTimeInterface $timestamp): void
    {
    }

    public function partial(?array $lastId, \DateTimeInterface $timestamp): ?array
    {
        return null;
    }

    public function refresh(EntityWrittenContainerEvent $event): void
    {
    }

    public static function getName(): string
    {
        return 'Swag.EntityIndexer';
    }
}
