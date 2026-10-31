# norialabs/approvals

Approval workflows for Eloquent models: maker-checker, four-eyes and dual control.

In development. Not yet published.

## Installation

```
composer require norialabs/approvals
```

The service provider registers itself. To override the defaults, publish the config:

```
php artisan vendor:publish --tag=approvals-config
```

## Configuration

| key | env | default | |
|---|---|---|---|
| `approvals.enforced` | `APPROVALS_ENFORCED` | `true` | When false, writes skip approval entirely. |

## License

MIT
