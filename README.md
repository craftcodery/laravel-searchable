# Laravel Searchable

This package makes it easy to search your Laravel models.

## Installation

You can install the package via composer:

```bash
composer require craftcodery/laravel-searchable
```

## Usage

### Preparing your models

In order to search through models you'll have to use the `Searchable` trait and add the `toSearchableArray` method.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use CraftCodery\Searchable;

class User extends Model
{
    use Searchable;
    
    /**
     * Get the searchable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return [
            'columns' => [
                'users.name'  => 60,
                'users.email' => 60,
                'locations.city' => 40,
            ],
            'joins'   => [
                'locations' => [
                    'users.location_id',
                    'locations.id'
                ],
            ],
            'groupBy' => 'users.id'
        ];
    }
}
```

### Searching models

To search your models, just use the `search` method.

```php
$users = User::search('john')->get();
```

### Configuring search matchers

You can configure the different search matchers and weights given to each used by the package.

```
php artisan vendor:publish --tag=searchable-config
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
