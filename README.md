# Editora Astro Resource

## Installation
Require the package in your composer.json

```
composer require omatech/editora-astro-resource
```

## Setup configuration
Set the configuration option in ***config/editora.php***

```config/editora.php
'astroToken' => 'hash'
```

## Using
### Get uris
The endpoint is predefined to obtain all published routes. Use the token to authenticate.

```
Headers: { astro-token: hash }
POST: /api/astro/routes
```

### Get resources
Use AstroResource in controllers to extract data and return it as a response.

```php
return AstroResource::resources(
    HomeExtraction::find($this->inst_id, $this->preview),
    GlobalExtraction::find(2, $this->preview),
    [
        'breadcrumbs' => PageExtraction::getBreadcrumbs($this->inst_id, $this->preview)
    ]
);
```
