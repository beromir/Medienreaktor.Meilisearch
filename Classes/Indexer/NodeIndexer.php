<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraints;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraintFactory;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Service\LinkingService;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer
{
    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var NodeTypeConstraintFactory
     */
    protected $nodeTypeConstraintFactory;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ServerRequestFactoryInterface
     */
    protected $requestFactory;

    /**
     * @Flow\Inject
     * @var UriFactoryInterface
     */
    protected $uriFactory;

    /**
	 * @Flow\Inject
	 * @var LinkingService
	 */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    public function initializeObject($cause)
    {
        parent::initializeObject($cause);
        putenv('FLOW_REWRITEURLS=1');
    }

    /**
     * @return IndexInterface
     */
    public function getIndexClient(): IndexInterface
    {
        return $this->indexClient;
    }

    /**
     * Add or update a node in the index with all node variants.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspace
     * @return void
     */
    public function indexNode(NodeInterface $node, $targetWorkspace = null): void
    {
        // Make sure this is a fulltext root, e.g. Neos.Neos:Document or subtype
        $node = $this->findFulltextRoot($node);

        if ($node !== null) {
            // The node aggregate identifier is a shared node identifier across all variants
            $nodeIdentifier = (string) $node->getNodeAggregateIdentifier();

            $allIndexedVariants = $this->indexClient->findAllIdentifiersByIdentifier($nodeIdentifier);
            $this->indexClient->deleteDocuments($allIndexedVariants);

            $documents = [];

            // For each dimension combination, extract the node variant properties and fulltext
            $dimensionCombinations = $this->calculateDimensionCombinations();
            if ($dimensionCombinations !== []) {
                foreach ($dimensionCombinations as $combination) {
                    if ($nodeVariant = $this->extractNodeVariant($nodeIdentifier, $combination)) {
                        $documents[] = $nodeVariant;
                    }
                }
            } else {
                if ($nodeVariant = $this->extractNodeVariant($nodeIdentifier)) {
                    $documents[] = $nodeVariant;
                }
            }

            // Finally, send all node variant documents to the index
            $this->indexClient->addDocuments($documents);
        }
    }

    /**
     * Extract node variant properties and fulltext for a given dimension combination
     *
     * @param string $nodeIdentifier
     * @param array $dimensionCombination
     * @return array
     */
    protected function extractNodeVariant(string $nodeIdentifier, array $dimensionCombination = []): ?array
    {
        if ($dimensionCombination !== []) {
            $context = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => $dimensionCombination]);
        } else {
            $context = $this->contextFactory->create(['workspaceName' => 'live']);
        }

        $node = $context->getNodeByIdentifier($nodeIdentifier);

        if ($node !== null) {
            $identifier = $this->generateUniqueNodeIdentifier($node);
            $fulltext = [];

            $document = $this->extractPropertiesAndFulltext($node, $fulltext);
            $document['id'] = $identifier;
            $document['__fulltext'] = $fulltext;

            if ($uri = $this->getNodeUri($node, $context)) {
                $document['__uri'] = $uri;
            }

            if (array_key_exists('__geo', $document)) {
                $document['_geo'] = $document['__geo'];
                unset($document['__geo']);
            }

            return $document;
        }

        return null;
    }

    /**
     * Remove a node from the index.
     *
     * @param NodeInterface $node
     * @return void
     */
    public function removeNode(NodeInterface $node): void
    {
        $identifier = $this->generateUniqueNodeIdentifier($node);
        $this->indexClient->deleteDocuments([$identifier]);
    }

    /**
     * @return void
     */
    public function flush(): void
    {
        return;
    }

    /**
     * Get the node uri
     *
     * @param NodeInterface $node
     * @param Context $context
     * @return string
     */
    protected function getNodeUri(NodeInterface $node, Context $context): ?string
    {
        try {
            $nodePath = $node->getPath();
            $nodePathSegments = explode('/', $nodePath);

            // Seems hacky, but we need to get the site by the node name here
            // and extract the site name by the node's path
            if (count($nodePathSegments) >= 3) {
                $siteName = $nodePathSegments[2];
                $site = $this->siteRepository->findOneByNodeName($siteName);

                $controllerContext = $this->getControllerContext($site);
                if ($controllerContext) {
                    return $this->linkingService->createNodeUri($controllerContext, $node, $context->getCurrentSiteNode(), 'html', TRUE);
                }
            }
        } catch (\Exception $e) {

        }
        return null;
    }

    /**
     * Get the controller context
     *
     * @param Site $site
     * @return ControllerContext
     */
    protected function getControllerContext(Site $site): ?ControllerContext
    {
        if ($site && $site->isOnline()) {
            $domain = $site->getPrimaryDomain();

            if ($domain && $domain->getActive()) {
                $requestUri = $this->uriFactory->createUri($domain->__toString());
                $httpRequest = $this->requestFactory->createServerRequest('get', $requestUri);
                $parameters = $httpRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
                $httpRequest = $httpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $parameters->withParameter('requestUriHost', $requestUri->getHost()));

                $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
                $actionRequest->setFormat('html');

                $uriBuilder = new UriBuilder();
                $uriBuilder->setRequest($actionRequest);
                $uriBuilder->setCreateAbsoluteUri(true);

                $controllerContext = new ControllerContext($actionRequest, new Mvc\ActionResponse(), new Mvc\Controller\Arguments([]), $uriBuilder);
                return $controllerContext;
            }
        }

        return null;
    }

    /**
     * Find the node's fulltext root, e.g. Neos.Neos:Document, by recursively looking at the configuration.
     *
     * @param NodeInterface $node
     * @return NodeInterface
     */
    protected function findFulltextRoot(NodeInterface $node): ?NodeInterface
    {
        if (self::isFulltextRoot($node)) {
            return $node;
        }

        try {
            $currentNode = $node->findParentNode();
            while ($currentNode !== null) {
                if (self::isFulltextRoot($currentNode)) {
                    return $currentNode;
                }

                $currentNode = $currentNode->findParentNode();
            }
        } catch (NodeException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected static function isFulltextRoot(NodeInterface $node): bool
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $searchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($searchSettingsForNode['fulltext']['isRoot']) && $searchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate all dimension combinations from presets.
     *
     * @return array
     */
    protected function calculateDimensionCombinations(): array
    {
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();

        $dimensionValueCountByDimension = [];
        $possibleCombinationCount = 1;
        $combinations = [];

        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            if (isset($dimensionPreset['presets']) && !empty($dimensionPreset['presets'])) {
                $dimensionValueCountByDimension[$dimensionName] = count($dimensionPreset['presets']);
                $possibleCombinationCount *= $dimensionValueCountByDimension[$dimensionName];
            }
        }

        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            for ($i = 0; $i < $possibleCombinationCount; $i++) {
                if (!isset($combinations[$i]) || !is_array($combinations[$i])) {
                    $combinations[$i] = [];
                }

                $currentDimensionCurrentPreset = current($dimensionPresets[$dimensionName]['presets']);
                $combinations[$i][$dimensionName] = $currentDimensionCurrentPreset['values'];

                if (!next($dimensionPresets[$dimensionName]['presets'])) {
                    reset($dimensionPresets[$dimensionName]['presets']);
                }
            }
        }

        return $combinations;
    }

    protected function extractPropertiesAndFulltext(NodeInterface $node, array &$fulltextData, \Closure $nonIndexedPropertyErrorHandler = null): array
    {
        $result = parent::extractPropertiesAndFulltext($node, $fulltextData, $nonIndexedPropertyErrorHandler);

        $nodeTypeConstraints = $this->nodeTypeConstraintFactory->parseFilterString('Neos.Neos:Content,Neos.Neos:ContentCollection');

        foreach ($node->findChildNodes($nodeTypeConstraints) as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $nodeTypeConstraints);
        }

        return $result;
    }

    protected function enrichWithFulltextForContentNodes(NodeInterface $node, array &$fulltextData, NodeTypeConstraints $nodeTypeConstraints): void
    {
        if ($this->isFulltextEnabled($node)) {
            $nodeType = $node->getNodeType();

            foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                if (isset($propertyConfiguration['search']['fulltextExtractor'])) {
                    $this->extractFulltext($node, $propertyName, $propertyConfiguration['search']['fulltextExtractor'], $fulltextData);
                }
            }
        }

        foreach ($node->findChildNodes($nodeTypeConstraints) as $childNode) {
            $this->enrichWithFulltextForContentNodes($childNode, $fulltextData, $nodeTypeConstraints);
        }
    }

    /**
     * Generate identifier for index document based on node identifier and dimensions.
     *
     * @param NodeInterface $node
     * @return string
     */
    protected function generateUniqueNodeIdentifier(NodeInterface $node): string
    {
        $nodeIdentifier = (string) $node->getNodeAggregateIdentifier();

        $dimensions = $node->getContext()->getDimensions();
        $dimensionsHash = md5(json_encode($dimensions));

        return $nodeIdentifier.'_'.$dimensionsHash;
    }
}
