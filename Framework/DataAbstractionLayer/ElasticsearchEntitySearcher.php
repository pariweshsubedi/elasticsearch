<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Framework\DataAbstractionLayer;

use Elasticsearch\Client;
use ONGR\ElasticsearchDSL\Aggregation\AbstractAggregation;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\FilterAggregation;
use ONGR\ElasticsearchDSL\Aggregation\Metric\CardinalityAggregation;
use ONGR\ElasticsearchDSL\Search;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;

class ElasticsearchEntitySearcher implements EntitySearcherInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var EntitySearcherInterface
     */
    private $decorated;

    /**
     * @var ElasticsearchHelper
     */
    private $helper;

    /**
     * @var CriteriaParser
     */
    private $criteriaParser;

    public function __construct(
        Client $client,
        EntitySearcherInterface $searcher,
        ElasticsearchHelper $helper,
        CriteriaParser $criteriaParser
    ) {
        $this->client = $client;
        $this->decorated = $searcher;
        $this->helper = $helper;
        $this->criteriaParser = $criteriaParser;
    }

    public function search(EntityDefinition $definition, Criteria $criteria, Context $context): IdSearchResult
    {
        if (!$this->helper->allowSearch($definition, $context)) {
            return $this->decorated->search($definition, $criteria, $context);
        }

        $search = $this->createSearch($criteria, $definition, $context);

        $search = $this->convertSearch($criteria, $definition, $context, $search);

        try {
            $result = $this->client->search([
                'index' => $this->helper->getIndexName($definition, $context->getLanguageId()),
                'type' => $definition->getEntityName(),
                'track_total_hits' => true,
                'body' => $search,
            ]);
        } catch (\Throwable $e) {
            $this->helper->logOrThrowException($e);

            return $this->decorated->search($definition, $criteria, $context);
        }

        return $this->hydrate($criteria, $context, $result);
    }

    protected function createSearch(Criteria $criteria, EntityDefinition $definition, Context $context): Search
    {
        $search = new Search();

        $this->helper->handleIds($definition, $criteria, $search, $context);
        $this->helper->addFilters($definition, $criteria, $search, $context);
        $this->helper->addPostFilters($definition, $criteria, $search, $context);
        $this->helper->addQueries($definition, $criteria, $search, $context);
        $this->helper->addSortings($definition, $criteria, $search, $context);
        $this->helper->addTerm($criteria, $search, $context);

        $search->setSize($criteria->getLimit());
        $search->setFrom($criteria->getOffset());

        return $search;
    }

    private function hydrate(Criteria $criteria, Context $context, array $result): IdSearchResult
    {
        if (!isset($result['hits'])) {
            return new IdSearchResult(0, [], $criteria, $context);
        }

        $hits = $this->extractHits($result);

        $data = [];
        foreach ($hits as $hit) {
            $id = $hit['_id'];

            $data[$id] = [
                'primaryKey' => $id,
                'data' => [
                    'id' => $id,
                    '_score' => $hit['_score'],
                ],
            ];
        }

        $total = $this->getTotalValue($criteria, $result);

        if ($criteria->useIdSorting()) {
            $data = $this->sortByIdArray($criteria->getIds(), $data);
        }

        return new IdSearchResult($total, $data, $criteria, $context);
    }

    private function getTotalValue(Criteria $criteria, array $result): int
    {
        if (!$criteria->getGroupFields()) {
            return (int) $result['hits']['total']['value'];
        }

        if (!$criteria->getPostFilters()) {
            return (int) $result['aggregations']['total-count']['value'];
        }

        return (int) $result['aggregations']['total-filtered-count']['total-count']['value'];
    }

    private function extractHits(array $result): array
    {
        $records = [];
        $hits = $result['hits']['hits'];

        foreach ($hits as $hit) {
            if (!isset($hit['inner_hits'])) {
                $records[] = $hit;

                continue;
            }

            $nested = $this->extractHits($hit['inner_hits']['inner']);
            foreach ($nested as $inner) {
                $records[] = $inner;
            }
        }

        return $records;
    }

    private function convertSearch(Criteria $criteria, EntityDefinition $definition, Context $context, Search $search)
    {
        if (!$criteria->getGroupFields()) {
            return $search->toArray();
        }

        $aggregation = $this->buildTotalCountAggregation($criteria, $definition, $context);

        $search->addAggregation($aggregation);

        $array = $search->toArray();
        $array['collapse'] = $this->parseGrouping($criteria->getGroupFields(), $definition, $context);

        return $array;
    }

    /**
     * @param FieldGrouping[] $groupings
     */
    private function parseGrouping(array $groupings, EntityDefinition $definition, Context $context): array
    {
        $grouping = array_shift($groupings);

        $accessor = $this->criteriaParser->buildAccessor($definition, $grouping->getField(), $context);
        if (empty($groupings)) {
            return ['field' => $accessor];
        }

        return [
            'field' => $accessor,
            'inner_hits' => [
                'name' => 'inner',
                'collapse' => $this->parseGrouping($groupings, $definition, $context),
            ],
        ];
    }

    private function buildTotalCountAggregation(Criteria $criteria, EntityDefinition $definition, Context $context): AbstractAggregation
    {
        $groupings = $criteria->getGroupFields();

        if (count($groupings) === 1) {
            $first = array_shift($groupings);

            $accessor = $this->criteriaParser->buildAccessor($definition, $first->getField(), $context);

            $aggregation = new CardinalityAggregation('total-count');
            $aggregation->setField($accessor);

            return $this->addPostFilterAggregation($criteria, $definition, $context, $aggregation);
        }

        $fields = [];
        foreach ($groupings as $grouping) {
            $accessor = $this->criteriaParser->buildAccessor($definition, $grouping->getField(), $context);

            $fields[] = sprintf(
                "
                if (doc['%s'].size()==0) {
                    value = value + 'empty';
                } else {
                    value = value + doc['%s'].value;
                }",
                $accessor,
                $accessor
            );
        }

        $script = '
            def value = \'\';

            ' . implode(' ', $fields) . '

            return value;
        ';

        $aggregation = new CardinalityAggregation('total-count');
        $aggregation->setScript($script);

        return $this->addPostFilterAggregation($criteria, $definition, $context, $aggregation);
    }

    private function addPostFilterAggregation(Criteria $criteria, EntityDefinition $definition, Context $context, CardinalityAggregation $aggregation): AbstractAggregation
    {
        if (!$criteria->getPostFilters()) {
            return $aggregation;
        }

        $query = $this->criteriaParser->parseFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, $criteria->getPostFilters()),
            $definition,
            $definition->getEntityName(),
            $context
        );

        $filterAgg = new FilterAggregation('total-filtered-count', $query);
        $filterAgg->addAggregation($aggregation);

        return $filterAgg;
    }

    private function sortByIdArray(array $ids, array $data): array
    {
        $sorted = [];

        foreach ($ids as $id) {
            if (\is_array($id)) {
                $id = implode('-', $id);
            }

            if (\array_key_exists($id, $data)) {
                $sorted[$id] = $data[$id];
            }
        }

        return $sorted;
    }
}
