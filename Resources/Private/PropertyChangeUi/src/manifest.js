import manifest from '@neos-project/neos-ui-extensibility';
import { takeLatest } from 'redux-saga/effects';
import { actionTypes } from '@neos-project/neos-ui-redux-store';
import union from 'lodash.union';
import { $get } from 'plow-js';
import { fetchWithErrorHandling } from '@neos-project/neos-ui-extensibility/src/shims/neosProjectPackages/neos-ui-backend-connector';

const getGuestFrameDocument = () => document.getElementsByName('neos-content-main')[0].contentDocument;

let propertyChangeRegistry = {};
let nodeTypesRegistry = null;
let translationRegistry = null;

const createHint = (container, propertyName, propertyLabel) => {
    window.translator = translationRegistry;
    const element = document.createElement('DIV');
    const button = document.createElement('BUTTON');
    button.innerText = translationRegistry.translate('Mindscreen.PropertyChanges:Main:propertyChanged.accept');
    const label = document.createElement('SPAN');
    label.innerText = translationRegistry.translate('Mindscreen.PropertyChanges:Main:propertyChanged.hint').replace('{0}', propertyLabel);
    element.appendChild(label);
    element.appendChild(button);
    container.appendChild(element);
    button.addEventListener('click', () => {
        fetchWithErrorHandling.withCsrfToken(csrfToken => ({
            url: `${container.getAttribute('data-__property-change-baseuri')}`,
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-Flow-Csrftoken': csrfToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                node: container.getAttribute('data-__property-change-contextpath'),
                propertyName,
            }),
        })).then(() => {
            element.remove();
        });
    })
};

const createHints = (container, nodeType, properties) => {
    container.innerHTML ='';
    properties.forEach(propertyName => {
        createHint(container, propertyName, getPropertyLabel(nodeType, propertyName));
    });
};

const registerPropertyChangeSlots = () => {
    const doc = getGuestFrameDocument();
    propertyChangeRegistry = {};
    [...doc.querySelectorAll('[data-__property-change-observe]')].forEach(element => {
        const observedProperties = JSON.parse(element.getAttribute('data-__property-change-observe'))
            .filter(s => s.trim() !== '');
        const changedProperties = JSON.parse(element.getAttribute('data-__property-change-changedproperties'))
            .filter(s => s.trim() !== '');
        const contextPath = element.getAttribute('data-__property-change-contextpath');
        const nodeType = element.getAttribute('data-__property-change-nodetype');
        const elementData = {
            element,
            notify: element.hasAttribute('data-__property-change-notify')
                ? JSON.parse(element.getAttribute('data-__property-change-notify'))
                : {},
            focus: element.getAttribute('data-__property-change-focus') === 'true',
        };
        if (!(contextPath in propertyChangeRegistry)) {
            propertyChangeRegistry[contextPath] = {
                elements: [],
                observedProperties: [],
                nodeType,
            };
        }
        propertyChangeRegistry[contextPath].elements.push(elementData);
        propertyChangeRegistry[contextPath].observedProperties = union(
            propertyChangeRegistry[contextPath].observedProperties, observedProperties);
        createHints(element, nodeType, changedProperties);
    });
};

function* watchGuestFrameLoaded() {
    yield takeLatest(actionTypes.UI.ContentCanvas.STOP_LOADING, () => {
        registerPropertyChangeSlots();
    });
}

const getPropertyLabel = (nodeType, propertyName) => {
    const nodeTypeDefinition = nodeTypesRegistry.get(nodeType);
    if (!nodeTypeDefinition) {
        return propertyName;
    }
    const label = $get(`properties.${propertyName}.ui.label`, nodeTypeDefinition);
    if (!label) {
        return propertyName;
    }
    return translationRegistry.translate(label, propertyName);
};

const handlePropertyChanges = (contextPath, nodeData) => {
    const { elements, observedProperties } = propertyChangeRegistry[contextPath];
    const changedProperties = [];
    const changedPropertiesStateProperty = 'propertyChangeState';
    if (changedPropertiesStateProperty in nodeData.properties) {
        const changedPropertiesState = JSON.parse(nodeData.properties[changedPropertiesStateProperty] || '[]');
        for (let propertyName of observedProperties) {
            if (!(propertyName in changedPropertiesState)
                || nodeData.properties[propertyName] !== changedPropertiesState[propertyName]) {
                changedProperties.push(propertyName);
            }
        }
    } else {
        for (let propertyName of observedProperties) {
            if (propertyName in nodeData.properties) {
                changedProperties.push(propertyName);
            }
        }
    }
    let focusElement = null;
    for (let elementData of elements) {
        let notifyProperties = [];
        for (let propertyName of changedProperties) {
            if (propertyName in elementData.notify) {
                notifyProperties = union(notifyProperties, elementData.notify[propertyName]);
            }
        }
        if (notifyProperties.length < 1) {
            continue;
        }
        if (!focusElement && elementData.focus) {
            focusElement = elementData.element;
        }
        createHints(elementData.element, nodeData.nodeType, notifyProperties);
    }
};

manifest('Mindscreen.PropertyChanges:PropertyChangeUi', {}, globalRegistry => {
    /** @var {SynchronousRegistry} */
    const sagaRegistry = globalRegistry.get('sagas');
    sagaRegistry.set('Mindscreen.PropertyChanges:PropertyChangeUi/guestFrameLoaded', {saga: watchGuestFrameLoaded});

    nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository');
    translationRegistry = globalRegistry.get('i18n');


    /** @var {SynchronousRegistry} */
    const serverFeedbackHandlersRegistry = globalRegistry.get('serverFeedbackHandlers');
    const updateNodeInfoHandlerKey = 'Neos.Neos.Ui:UpdateNodeInfo/Main';
    const original = serverFeedbackHandlersRegistry.get(updateNodeInfoHandlerKey);
    const decorated = (feedback, store) => {
        const changesByContextPath = feedback.byContextPath;
        for (let contextPath of Object.keys(changesByContextPath)) {
            if (!(contextPath in propertyChangeRegistry)) {
                continue;
            }
            const nodeData = changesByContextPath[contextPath];
            handlePropertyChanges(contextPath, nodeData);
        }
        original(feedback, store);
    };
    serverFeedbackHandlersRegistry.set(updateNodeInfoHandlerKey, decorated);
});
