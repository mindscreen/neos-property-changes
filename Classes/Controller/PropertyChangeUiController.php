<?php

namespace Mindscreen\PropertyChanges\Controller;


use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;

class PropertyChangeUiController extends ActionController
{
    const PropertyChangeStateProperty = 'propertyChangeState';

    protected $defaultViewImplementation = JsonView::class;

    /**
     * @param NodeInterface $node
     * @param string $propertyName
     */
    public function acceptPropertyAction(NodeInterface $node, $propertyName)
    {
        $acceptedPropertiesState = [];
        if ($node->hasProperty(self::PropertyChangeStateProperty)) {
            $acceptedPropertiesStateString = $node->getProperty(self::PropertyChangeStateProperty);
            $acceptedPropertiesState = json_decode($acceptedPropertiesStateString, true);
        }
        if ($node->hasProperty($propertyName)) {
            $acceptedPropertiesState[$propertyName] = $node->getProperty($propertyName);
        }
        $this->view->assign('value', $acceptedPropertiesState);
        if (!empty($acceptedPropertiesState)) {
            $node->setProperty(self::PropertyChangeStateProperty, json_encode($acceptedPropertiesState));
        }
    }

}
