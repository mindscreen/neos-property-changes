# Mindscreen.PropertyChangeUi
A package to notify the editor if a change to some node-property might require an update to another property, e.g. you could recommend an update of the URI path segment once the user updated the document title.

## Usage
Include the Fusion prototype `Mindscreen.PropertyChangeUi:PropertyChangeHint` at a convenient location in your rendering and configure the properties to watch and the related properties:
```
title = Vendor.Package:Title {
    text = Neos.Fusion:Editable {
        property = 'title'
        node = ${documentNode}
        block = false
    }
    @process.editable = Neos.Neos:ContentElementWrapping
}
propertyChangeHint = Mindscreen.PropertyChangeUi:PropertyChangeHint {
    node = ${documentNode}
    properties = ${['uriPathSegment']}
    notify {
        uriPathSegment = ${['title']}
    }
}
```

### Initialize data
The package provides a node:repair plugin to set all current property-values as accepted values to avoid a lot of notifications all across the site.
It's task `createPropertyChangeState` can thus be executed e.g. as `./flow node:repair --only createPropertyChangeState`.

### Configuration
`Neos.Neos:Node` disables all properties from initialization with the node-repair task, so you have to enable them on the nodes of your choice (e.g. Neos.NodeTypes:Page) yourself like this:
```yaml
Vendor.Package:Node:
  options:
    propertyChanges:
      properties: ['title']
```
