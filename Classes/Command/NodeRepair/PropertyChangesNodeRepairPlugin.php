<?php

namespace Mindscreen\PropertyChanges\Command\NodeRepair;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mindscreen\PropertyChanges\Controller\PropertyChangeUiController;
use Neos\ContentRepository\Command\NodeCommandControllerPluginInterface;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Utility\Arrays;

class PropertyChangesNodeRepairPlugin implements NodeCommandControllerPluginInterface
{

    /**
     * @var ConsoleOutput
     */
    protected $output;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * Returns a short description for the specific task the plugin solves for the specified command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A brief description / summary for the task this plugin is going to do
     */
    public static function getSubCommandShortDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return 'Run integrity checks related to Neos features';
        }
        return '';
    }

    /**
     * Returns a piece of description for the specific task the plugin solves for the specified command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A piece of text to be included in the overall description of the node:xy command
     */
    public static function getSubCommandDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return <<<'HELPTEXT'

<u>Add initial state for PropertyChangesUi</u>
createPropertyChangeState

All nodes' text properties' values will be set as last known and accepted value.

HELPTEXT;
        }
        return '';
    }

    /**
     * A method which runs the task implemented by the plugin for the given command
     *
     * @param string $controllerCommandName
     * @param ConsoleOutput $output
     * @param NodeType|null $nodeType
     * @param string $workspaceName
     * @param bool $dryRun
     * @param bool $cleanup
     * @param string $skip
     * @param string $only
     */
    public function invokeSubCommand($controllerCommandName, ConsoleOutput $output, NodeType $nodeType = null, $workspaceName = 'live', $dryRun = false, $cleanup = true, $skip = null, $only = null)
    {
        if ($controllerCommandName !== 'repair') {
            return;
        }
        $this->output = $output;
        $skipCommands = Arrays::trimExplode(',', ($skip === null ? '' : $skip));
        $onlyCommands = Arrays::trimExplode(',', ($only === null ? '' : $only));
        $subCommandName = 'createPropertyChangeState';
        if (in_array($subCommandName, $skipCommands) || (!empty($onlyCommands) && !in_array($subCommandName, $onlyCommands))) {
            return;
        }
        $this->addMissingPropertyChangeState($nodeType, $workspaceName, $dryRun);
    }

    protected function addMissingPropertyChangeState(NodeType $nodeType = null, $workspace = 'live', $dryRun = false)
    {
        if ($nodeType !== null) {
            $this->output->outputLine('Checking nodes of type "%s" for missing property-change state ...', array($nodeType->getName()));
            $this->setPropertyChangeStateForNodeType($nodeType, $workspace, $dryRun);
        } else {
            $this->output->outputLine('Checking for missing property-change state ...');
            foreach ($this->nodeTypeManager->getNodeTypes() as $nodeType) {
                /** @var NodeType $nodeType */
                if ($nodeType->isAbstract()) {
                    continue;
                }
                $this->setPropertyChangeStateForNodeType($nodeType, $workspace, $dryRun);
            }
        }
    }

    protected function setPropertyChangeStateForNodeType(NodeType $nodeType, string $workspace, bool $dryRun)
    {
        $addedPropertyChangeStates = 0;

        $nodeTypes = $this->nodeTypeManager->getSubNodeTypes($nodeType->getName(), false);
        $nodeTypes[$nodeType->getName()] = $nodeType;

        if ($this->nodeTypeManager->hasNodeType((string)$nodeType)) {
            $nodeType = $this->nodeTypeManager->getNodeType((string)$nodeType);
            $nodeTypeNames[$nodeType->getName()] = $nodeType;
        } else {
            $this->output->outputLine('Node type "%s" does not exist', array((string)$nodeType));
            exit(1);
        }

        /** @var $nodeType NodeType */
        foreach ($nodeTypes as $nodeTypeName => $nodeType) {
            $nodeTypeOptions = $nodeType->getOptions();
            $propertyChangesOptionsKey = 'propertyChanges';
            if (array_key_exists($propertyChangesOptionsKey, $nodeTypeOptions)
                && array_key_exists('properties', $nodeTypeOptions[$propertyChangesOptionsKey])) {
                $properties = $nodeTypeOptions[$propertyChangesOptionsKey]['properties'];
            } else {
                $properties = array_keys($nodeType->getProperties());
                $properties = array_filter($properties, function ($propertyName) use ($nodeType) {
                    return $nodeType->getPropertyType($propertyName) === 'string';
                });
            }
            if (empty($properties)) {
                continue;
            }
            foreach ($this->getNodeDataByNodeTypeAndWorkspace($nodeTypeName, $workspace) as $nodeData) {
                /** @var NodeData $nodeData */
                $context = $this->nodeFactory->createContextMatchingNodeData($nodeData);
                $node = $this->nodeFactory->createFromNodeData($nodeData, $context);
                if (!$node instanceof NodeInterface) {
                    continue;
                }
                if ($node instanceof Node && !$node->dimensionsAreMatchingTargetDimensionValues()) {
                    if ($node->getNodeData()->getDimensionValues() === []) {
                        $this->output->outputLine('Skipping node %s because it has no dimension values set', [$node->getPath()]);
                    } else {
                        $this->output->outputLine('Skipping node %s because it has invalid dimension values: %s', [$node->getPath(), json_encode($node->getNodeData()->getDimensionValues())]);
                    }
                    continue;
                }

                try {
                    $acceptedPropertiesStateString = $node->getProperty(PropertyChangeUiController::PropertyChangeStateProperty);
                } catch (NodeException $e) {
                    $acceptedPropertiesStateString = '';
                }
                if (empty($acceptedPropertiesStateString)) {
                    $acceptedPropertiesState = [];
                } else {
                    $acceptedPropertiesState = json_decode($acceptedPropertiesStateString, true);
                }
                foreach ($properties as $propertyName) {
                    if (!$node->hasProperty($propertyName)) {
                        continue;
                    }
                    $value = $node->getProperty($propertyName);
                    if (is_object($value)) {
                        continue;
                    }
                    $acceptedPropertiesState[$propertyName] = $value;
                }
                if ($dryRun !== false) {
                    $node->setProperty(PropertyChangeUiController::PropertyChangeStateProperty, json_encode($acceptedPropertiesState));
                }
                $addedPropertyChangeStates++;
            }
        }

        if ($addedPropertyChangeStates !== 0) {
            if ($dryRun === false) {
                $this->persistenceManager->persistAll();
            }
            $this->output->outputLine('Updated property-change states on %s nodes', array($addedPropertyChangeStates));
        }
    }

    protected function getNodeDataByNodeTypeAndWorkspace($nodeTypeName, string $workspace)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder->select('n')
            ->distinct()
            ->from(NodeData::class, 'n')
            ->where('n.nodeType = :nodeType')
            ->andWhere('n.workspace = :workspace')
            ->andWhere('n.movedTo IS NULL OR n.removed = :removed')
            ->setParameter('nodeType', $nodeTypeName)
            ->setParameter('workspace', $workspace)
            ->setParameter('removed', false, \PDO::PARAM_BOOL);
        return $queryBuilder->getQuery()->getResult();
    }
}
