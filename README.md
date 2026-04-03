# Wp Clean Migrate

Wp Clean Migrate is a WordPress plugin for migrating WooCommerce data from an old store into a new WooCommerce site through WP-CLI.

It is intended for developers and operators handling controlled store migrations, staged cutovers, and repeatable batch imports. The plugin focuses on command-driven migration flows, progress tracking, and rerunnable batches rather than browser-based setup screens.

## Warning

This plugin modifies data in the destination WordPress and WooCommerce site.

- Use it on staging first.
- Back up both the source and destination stores before running any migration.
- Start with small batches.
- Review results between runs.
- Do not assume a dry run covers every command or every edge case identically.

## Features

- WP-CLI driven migration workflow
- Batch processing with `--page` and `--per-page`
- State tracking in the `muh_clean_migrator_state` option
- Category migration with parent mapping support
- Product migration for simple products and variable products where supported by the current codebase
- Customer migration into WordPress users with WooCommerce billing and shipping meta
- Order migration for paid orders
- Product and variation image import
- Menu migration for mappable targets
- Basic migration status output and reset support

## Requirements

- WordPress
- WooCommerce active on the destination site
- WP-CLI available on the destination site
- PHP 8.1 or newer is recommended
  - The included GitHub Actions workflow currently runs on PHP 8.1.
- API access to the source WooCommerce store
- Source store credentials:
  - URL
  - Consumer key
  - Consumer secret

## Installation

### Manual plugin install

1. Copy the plugin into your WordPress plugins directory:

```bash
wp-content/plugins/wp-clean-migrate
```

2. Activate it:

```bash
wp plugin activate wp-clean-migrate
```

3. Verify WooCommerce is active:

```bash
wp plugin is-active woocommerce
```

4. Verify WP-CLI is available:

```bash
wp --info
```

5. Verify the command is registered:

```bash
wp help muh-migrate
```

## Configuration

Define the source store credentials in `wp-config.php` on the destination site:

```php
define('MUH_OLD_STORE_URL', 'https://old-store.example.com');
define('MUH_OLD_CONSUMER_KEY', 'ck_xxxxxxxxxxxxxxxxxxxxx');
define('MUH_OLD_CONSUMER_SECRET', 'cs_xxxxxxxxxxxxxxxxxxxxx');
```

The plugin validates these constants when the command runs. If any are missing, the command will fail with a clear WP-CLI error.

## Usage

Command format:

```bash
wp muh-migrate <action> [--page=<n>] [--per-page=<n>] [--dry-run=1]
```

Supported actions:

- `categories`
- `products`
- `orders`
- `customers`
- `images`
- `menus`
- `status`
- `reset`

### Categories

Migrate WooCommerce product categories from the source store.

```bash
wp muh-migrate categories
wp muh-migrate categories --page=1 --per-page=50
wp muh-migrate categories --page=1 --per-page=50 --dry-run=1
```

### Products

Migrate products from the source WooCommerce API.

```bash
wp muh-migrate products
wp muh-migrate products --page=1 --per-page=20
wp muh-migrate products --page=1 --per-page=20 --dry-run=1
```

Notes:

- Simple products are supported.
- Variable product and variation support depends on the current implementation in the repository.
- Product matching is primarily based on migration state, stored meta, SKU, and slug/path matching.

### Orders

Migrate paid orders from the source store.

```bash
wp muh-migrate orders
wp muh-migrate orders --page=1 --per-page=10
wp muh-migrate orders --page=1 --per-page=10 --dry-run=1
```

Notes:

- The current implementation targets paid-style statuses such as `processing` and `completed`.
- Orders without resolvable migrated products are skipped for safety.

### Customers

Migrate WooCommerce customers into destination WordPress users.

```bash
wp muh-migrate customers
wp muh-migrate customers --page=1 --per-page=50
wp muh-migrate customers --page=1 --per-page=50 --dry-run=1
```

### Images

Import product and variation images after products exist on the destination site.

```bash
wp muh-migrate images
wp muh-migrate images --page=1 --per-page=10
wp muh-migrate images --page=1 --per-page=10 --dry-run=1
```

Notes:

- Run image imports after product migration.
- Imports depend on valid remote image URLs.
- Re-runs are expected to reuse already imported images where the current code can match them safely.

### Menus

Migrate WordPress menus and supported menu items from the source site.

```bash
wp muh-migrate menus
wp muh-migrate menus --dry-run=1
```

### Status

Print saved migration stats and map counts from the stored option state.

```bash
wp muh-migrate status
```

### Reset

Delete the saved migration state option.

```bash
wp muh-migrate reset
```

Use this carefully. Resetting state removes the stored ID maps and counters used for reruns.

## Batch Examples

Run the first page of categories:

```bash
wp muh-migrate categories --page=1 --per-page=50
```

Run multiple product pages manually:

```bash
wp muh-migrate products --page=1 --per-page=20
wp muh-migrate products --page=2 --per-page=20
wp muh-migrate products --page=3 --per-page=20
```

Loop through pages in a shell:

```bash
for page in 1 2 3 4 5; do
  wp muh-migrate products --page="$page" --per-page=20
done
```

Dry run a customer batch:

```bash
wp muh-migrate customers --page=1 --per-page=25 --dry-run=1
```

Check progress:

```bash
wp muh-migrate status
```

Reset stored state:

```bash
wp muh-migrate reset
```

## Recommended Migration Order

Recommended default order:

1. `categories`
2. `products`
3. `images`
4. `customers`
5. `orders`
6. `menus`
7. `status`

Reasoning:

- Categories should exist before products.
- Products should exist before product images and order line items.
- Customers should exist before orders when possible.
- Menus usually depend on categories or pages already being present.

## State Tracking

The plugin stores migration progress in the WordPress option:

```text
muh_clean_migrator_state
```

This state includes:

- ID maps such as category, product, variation, customer, and order mappings
- Cumulative stats for created, updated, skipped, and failed items

Reruns depend on this state and on persisted migrated meta. If you delete or reset the state, matching behavior on later runs may change.

## Repository Structure

```text
wp-clean-migrate/
├── .github/workflows/tests.yml
├── composer.json
├── includes/
│   ├── API/
│   ├── CLI/
│   ├── Core/
│   ├── Helpers/
│   ├── Migrators/
│   ├── Autoloader.php
│   └── Plugin.php
├── muh-clean-migrator.php
├── phpunit.xml.dist
└── tests/
    ├── Fixtures/
    ├── Integration/
    ├── Support/
    ├── Unit/
    ├── bootstrap.php
    └── TestCase.php
```

## Development

### Local development

Typical workflow:

1. Install the plugin into a local WordPress site.
2. Activate WooCommerce.
3. Configure the source API constants in `wp-config.php`.
4. Run WP-CLI migration commands against a staging database.
5. Review output and destination data after each batch.

### Tests

The repository includes a PHPUnit-based test suite with a lightweight fake WordPress/WooCommerce runtime for plugin-level testing.

Install dev dependencies:

```bash
composer install
```

Run tests:

```bash
vendor/bin/phpunit
```

Or:

```bash
composer test
```

### Syntax checks

Example local lint pass:

```bash
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Coding style

This repository currently follows plain WordPress and WooCommerce-style PHP with a lightweight modular structure:

- Keep changes straightforward.
- Prefer small, focused classes.
- Avoid framework-specific abstractions.
- Preserve CLI behavior unless a change is necessary for stability or testability.

### Running commands during development

Examples:

```bash
wp muh-migrate status
wp muh-migrate categories --page=1 --per-page=20
wp muh-migrate products --page=1 --per-page=10 --dry-run=1
wp muh-migrate images --page=1 --per-page=5
```

## Current Limitations and Caveats

- Migration behavior depends on the current plugin implementation in this repository.
- Variable product support may still require validation against your actual source data set.
- Idempotency depends on the matching strategy and the saved migration state.
- Image imports depend on valid remote URLs and reachable source assets.
- Menu migration only works when destination targets can be mapped safely.
- Source and destination schema differences can still require manual cleanup.
- Product, customer, and order data may include store-specific edge cases not covered by generic logic.
- Resetting state removes the stored maps used to make reruns safer.

## Troubleshooting

### Missing config constants

Symptom:

- WP-CLI reports missing configuration values.

Check:

- `MUH_OLD_STORE_URL`
- `MUH_OLD_CONSUMER_KEY`
- `MUH_OLD_CONSUMER_SECRET`

### WooCommerce inactive

Symptom:

- Command exits with a WooCommerce activation error.

Check:

```bash
wp plugin is-active woocommerce
```

### Remote API failures

Symptom:

- API request errors
- HTTP error responses
- empty batches when you expect data

Check:

- source URL correctness
- credentials
- source store REST API accessibility
- network/firewall restrictions

### Images skipped

Symptom:

- image migration reports skipped or failed imports

Check:

- the product or variation has already been migrated and mapped
- image URLs are valid and allowed
- source files are still reachable

### Products skipped

Symptom:

- logs mention unsupported product types or matching failures

Check:

- product type support in the current code
- SKU and slug consistency
- prior migration state

### PHP extension warnings

Some PHP CLI environments emit unrelated warnings from local extensions or `php.ini`. Review whether the warning is actually blocking migration logic before changing plugin code.

## Roadmap

- strengthen variable product and variation migration confidence
- improve rerun safety and idempotent matching
- expand test coverage around order and image edge cases
- refine repository and plugin architecture incrementally
- improve logging and post-run reporting

## Contributing

See [`CONTRIBUTING.md`](/home/muhdroid/Documents/wordpressSites/tests/wp-content/plugins/wp-clean-migrate/CONTRIBUTING.md).

## License

No license file is included yet.

If you plan to publish the repository publicly, choose and add a license before accepting outside contributions. For a pragmatic default, MIT is often suitable for utility plugins unless you need stronger copyleft requirements.

Until a `LICENSE` file is added, redistribution and contribution expectations are unclear.
