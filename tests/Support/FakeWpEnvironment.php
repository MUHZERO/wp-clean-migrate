<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Support {

final class FakeWpEnvironment
{
    /**
     * @var array<string, mixed>
     */
    public static array $options = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $terms = [];

    /**
     * @var array<string, int>
     */
    public static array $term_slug_map = [];

    /**
     * @var array<int, object>
     */
    public static array $posts = [];

    /**
     * @var array<int, string>
     */
    public static array $post_types = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $post_meta = [];

    /**
     * @var array<int, int>
     */
    public static array $post_thumbnails = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $users = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $user_meta = [];

    /**
     * @var array<string, string>
     */
    public static array $filters = [];

    /**
     * @var array<int, string>
     */
    public static array $passwords = [];

    private static int $next_term_id = 1;
    private static int $next_post_id = 100;
    private static int $next_user_id = 10;
    private static int $next_attachment_id = 500;
    private static int $next_order_id = 1000;

    public static function reset(): void
    {
        self::$options         = [];
        self::$terms           = [];
        self::$term_slug_map   = [];
        self::$posts           = [];
        self::$post_types      = [];
        self::$post_meta       = [];
        self::$post_thumbnails = [];
        self::$users           = [];
        self::$user_meta       = [];
        self::$filters         = [];
        self::$passwords       = [];
        self::$next_term_id    = 1;
        self::$next_post_id    = 100;
        self::$next_user_id    = 10;
        self::$next_attachment_id = 500;
        self::$next_order_id   = 1000;

        \WP_CLI::reset();
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function parseArgs($value, array $args): array
    {
        $parsed = is_array($value) ? $value : [];

        return array_replace($args, $parsed);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function insertTerm(string $taxonomy, string $name, array $payload): array
    {
        $term_id = self::$next_term_id++;

        self::$terms[$term_id] = [
            'term_id'     => $term_id,
            'taxonomy'    => $taxonomy,
            'name'        => $name,
            'slug'        => $payload['slug'] ?? sanitize_title($name),
            'description' => $payload['description'] ?? '',
            'parent'      => (int) ($payload['parent'] ?? 0),
        ];

        self::$term_slug_map[$taxonomy . ':' . self::$terms[$term_id]['slug']] = $term_id;

        return ['term_id' => $term_id];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function updateTerm(int $term_id, string $taxonomy, array $payload): array
    {
        if (!isset(self::$terms[$term_id])) {
            return ['term_id' => $term_id];
        }

        self::$terms[$term_id] = array_replace(self::$terms[$term_id], [
            'taxonomy'    => $taxonomy,
            'name'        => $payload['name'] ?? self::$terms[$term_id]['name'],
            'slug'        => $payload['slug'] ?? self::$terms[$term_id]['slug'],
            'description' => $payload['description'] ?? self::$terms[$term_id]['description'],
            'parent'      => (int) ($payload['parent'] ?? self::$terms[$term_id]['parent']),
        ]);

        self::$term_slug_map[$taxonomy . ':' . self::$terms[$term_id]['slug']] = $term_id;

        return ['term_id' => $term_id];
    }

    public static function getTermBySlug(string $slug, string $taxonomy): ?object
    {
        $key = $taxonomy . ':' . $slug;
        if (!isset(self::$term_slug_map[$key])) {
            return null;
        }

        $term_id = self::$term_slug_map[$key];

        return (object) self::$terms[$term_id];
    }

    public static function savePost(object $post, string $type): int
    {
        $id = method_exists($post, 'get_id') ? $post->get_id() : 0;

        if ($id <= 0) {
            $id = self::$next_post_id++;
            if (method_exists($post, 'set_id')) {
                $post->set_id($id);
            }
        }

        self::$posts[$id]      = $post;
        self::$post_types[$id] = $type;

        if (method_exists($post, 'get_slug')) {
            $slug = $post->get_slug();
            if ($slug !== '') {
                self::updatePostMeta($id, '_fake_slug', $slug);
            }
        }

        if (method_exists($post, 'get_sku')) {
            $sku = $post->get_sku();
            if ($sku !== '') {
                self::updatePostMeta($id, '_fake_sku', $sku);
            }
        }

        return $id;
    }

    public static function getPost(int $post_id): ?object
    {
        return self::$posts[$post_id] ?? null;
    }

    public static function setPostType(int $post_id, string $type): void
    {
        self::$post_types[$post_id] = $type;
    }

    public static function getPostType(int $post_id): string
    {
        return self::$post_types[$post_id] ?? '';
    }

    public static function findProductIdBySku(string $sku, string $post_type): int
    {
        foreach (self::$post_meta as $post_id => $meta) {
            if ((self::$post_types[$post_id] ?? '') !== $post_type) {
                continue;
            }

            if (($meta['_fake_sku'] ?? '') === $sku) {
                return $post_id;
            }
        }

        return 0;
    }

    public static function findPostByPath(string $path, string $post_type): ?object
    {
        foreach (self::$post_meta as $post_id => $meta) {
            if ((self::$post_types[$post_id] ?? '') !== $post_type) {
                continue;
            }

            if (($meta['_fake_slug'] ?? '') === $path) {
                return (object) ['ID' => $post_id];
            }
        }

        return null;
    }

    public static function updatePostMeta(int $post_id, string $key, $value): void
    {
        if (!isset(self::$post_meta[$post_id])) {
            self::$post_meta[$post_id] = [];
        }

        self::$post_meta[$post_id][$key] = $value;
    }

    public static function getPostMeta(int $post_id, string $key, bool $single = true)
    {
        $value = self::$post_meta[$post_id][$key] ?? ($single ? '' : []);

        return $single ? $value : [$value];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, mixed>
     */
    public static function getPostsByQuery(array $query): array
    {
        $post_type = $query['post_type'] ?? '';
        $meta_key  = $query['meta_key'] ?? '';
        $meta_value = $query['meta_value'] ?? null;
        $fields    = $query['fields'] ?? '';
        $results   = [];

        foreach (self::$post_types as $post_id => $type) {
            if ($post_type !== '' && $type !== $post_type) {
                continue;
            }

            if ($meta_key !== '' && ((string) (self::$post_meta[$post_id][$meta_key] ?? '')) !== (string) $meta_value) {
                continue;
            }

            $results[] = $fields === 'ids' ? $post_id : (object) ['ID' => $post_id];
        }

        return $results;
    }

    public static function setPostThumbnail(int $post_id, int $attachment_id): void
    {
        self::$post_thumbnails[$post_id] = $attachment_id;
    }

    public static function getPostThumbnail(int $post_id): int
    {
        return self::$post_thumbnails[$post_id] ?? 0;
    }

    public static function createUser(string $username, string $email): int
    {
        $user_id = self::$next_user_id++;

        self::$users[$user_id] = [
            'ID'         => $user_id,
            'user_login' => $username,
            'user_email' => $email,
            'first_name' => '',
            'last_name'  => '',
            'display_name' => $username,
            'role'       => '',
        ];

        return $user_id;
    }

    public static function getUserBy(string $field, $value): ?object
    {
        foreach (self::$users as $user) {
            if ($field === 'email' && $user['user_email'] === $value) {
                return (object) $user;
            }

            if ($field === 'id' && (int) $user['ID'] === (int) $value) {
                return (object) $user;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function updateUser(array $payload): void
    {
        $user_id = (int) ($payload['ID'] ?? 0);
        if (!$user_id || !isset(self::$users[$user_id])) {
            return;
        }

        self::$users[$user_id] = array_replace(self::$users[$user_id], $payload);
    }

    public static function updateUserMeta(int $user_id, string $meta_key, $value): void
    {
        if (!isset(self::$user_meta[$user_id])) {
            self::$user_meta[$user_id] = [];
        }

        self::$user_meta[$user_id][$meta_key] = $value;
    }

    public static function usernameExists(string $username): bool
    {
        foreach (self::$users as $user) {
            if ($user['user_login'] === $username) {
                return true;
            }
        }

        return false;
    }

    public static function createAttachment(string $url, int $post_id): int
    {
        $attachment_id = self::$next_attachment_id++;

        self::$posts[$attachment_id]      = (object) ['ID' => $attachment_id, 'post_parent' => $post_id];
        self::$post_types[$attachment_id] = 'attachment';
        self::updatePostMeta($attachment_id, '_fake_source_url', $url);

        return $attachment_id;
    }

    public static function createOrder(array $args): \WC_Order
    {
        $order_id = self::$next_order_id++;
        $order    = new \WC_Order($order_id, $args);
        self::$posts[$order_id]      = $order;
        self::$post_types[$order_id] = 'shop_order';

        return $order;
    }
}

}

namespace {

    use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            private string $message;

            public function __construct(string $code = '', string $message = '')
            {
                $this->message = $message;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $commands = [];
            public static array $logs = [];
            public static array $warnings = [];
            public static array $errors = [];

            public static function add_command(string $name, $command): void
            {
                self::$commands[$name] = $command;
            }

            public static function log(string $message): void
            {
                self::$logs[] = $message;
            }

            public static function warning(string $message): void
            {
                self::$warnings[] = $message;
            }

            public static function error(string $message, bool $exit = false): void
            {
                self::$errors[] = $message;
            }

            public static function reset(): void
            {
                self::$commands = [];
                self::$logs     = [];
                self::$warnings = [];
                self::$errors   = [];
            }
        }
    }

    if (!class_exists('WooCommerce')) {
        class WooCommerce
        {
        }
    }

    if (!class_exists('WC_Product')) {
        class WC_Product
        {
            protected int $id = 0;
            protected string $type = 'simple';
            protected string $name = '';
            protected string $slug = '';
            protected string $status = 'publish';
            protected string $catalog_visibility = 'visible';
            protected string $description = '';
            protected string $short_description = '';
            protected string $sku = '';
            protected string $regular_price = '';
            protected string $sale_price = '';
            protected bool $featured = false;
            protected bool $manage_stock = false;
            protected $stock_quantity = null;
            protected string $stock_status = 'instock';
            protected bool $virtual = false;
            protected bool $downloadable = false;
            protected array $attributes = [];
            protected array $default_attributes = [];
            protected int $parent_id = 0;
            protected int $image_id = 0;

            public function __construct(int $id = 0)
            {
                $this->id = $id;
            }

            public function set_id(int $id): void { $this->id = $id; }
            public function get_id(): int { return $this->id; }
            public function set_name(string $value): void { $this->name = $value; }
            public function get_name(): string { return $this->name; }
            public function set_slug(string $value): void { $this->slug = $value; }
            public function get_slug(): string { return $this->slug; }
            public function set_status(string $value): void { $this->status = $value; }
            public function set_catalog_visibility(string $value): void { $this->catalog_visibility = $value; }
            public function set_description(string $value): void { $this->description = $value; }
            public function set_short_description(string $value): void { $this->short_description = $value; }
            public function set_sku(string $value): void { $this->sku = $value; }
            public function get_sku(): string { return $this->sku; }
            public function set_regular_price(string $value): void { $this->regular_price = $value; }
            public function get_regular_price(): string { return $this->regular_price; }
            public function set_sale_price(string $value): void { $this->sale_price = $value; }
            public function set_featured(bool $value): void { $this->featured = $value; }
            public function set_manage_stock(bool $value): void { $this->manage_stock = $value; }
            public function set_stock_quantity($value): void { $this->stock_quantity = $value; }
            public function set_stock_status(string $value): void { $this->stock_status = $value; }
            public function set_virtual(bool $value): void { $this->virtual = $value; }
            public function set_downloadable(bool $value): void { $this->downloadable = $value; }
            public function set_attributes(array $value): void { $this->attributes = $value; }
            public function get_attributes(): array { return $this->attributes; }
            public function set_default_attributes(array $value): void { $this->default_attributes = $value; }
            public function set_parent_id(int $value): void { $this->parent_id = $value; }
            public function get_parent_id(): int { return $this->parent_id; }
            public function set_image_id(int $value): void { $this->image_id = $value; }
            public function get_image_id(): int { return $this->image_id; }

            public function save(): int
            {
                return FakeWpEnvironment::savePost($this, $this->type === 'variation' ? 'product_variation' : 'product');
            }
        }
    }

    if (!class_exists('WC_Product_Simple')) {
        class WC_Product_Simple extends WC_Product
        {
            protected string $type = 'simple';
        }
    }

    if (!class_exists('WC_Product_Variable')) {
        class WC_Product_Variable extends WC_Product
        {
            protected string $type = 'variable';

            /**
             * @return array<int, int>
             */
            public function get_children(): array
            {
                $children = [];

                foreach (FakeWpEnvironment::$posts as $post_id => $post) {
                    if (($post instanceof WC_Product_Variation) && $post->get_parent_id() === $this->id) {
                        $children[] = $post_id;
                    }
                }

                return $children;
            }

            public static function sync(int $product_id): void
            {
            }
        }
    }

    if (!class_exists('WC_Product_Variation')) {
        class WC_Product_Variation extends WC_Product
        {
            protected string $type = 'variation';
        }
    }

    if (!class_exists('WC_Product_Attribute')) {
        class WC_Product_Attribute
        {
            private int $id = 0;
            private string $name = '';
            private array $options = [];
            private int $position = 0;
            private bool $visible = false;
            private bool $variation = false;

            public function set_id(int $id): void { $this->id = $id; }
            public function set_name(string $name): void { $this->name = $name; }
            public function set_options(array $options): void { $this->options = $options; }
            public function set_position(int $position): void { $this->position = $position; }
            public function set_visible(bool $visible): void { $this->visible = $visible; }
            public function set_variation(bool $variation): void { $this->variation = $variation; }
            public function get_name(): string { return $this->name; }
            public function get_options(): array { return $this->options; }
            public function get_variation(): bool { return $this->variation; }
        }
    }

    if (!class_exists('WC_Order_Item')) {
        class WC_Order_Item
        {
            public array $meta_data = [];

            public function add_meta_data(string $key, $value, bool $unique = true): void
            {
                $this->meta_data[$key] = $value;
            }

            public function save(): void
            {
            }
        }
    }

    if (!class_exists('WC_Order')) {
        class WC_Order
        {
            private int $id;
            private array $args;
            public array $items = [];
            public array $meta = [];
            public array $addresses = [];

            public function __construct(int $id, array $args = [])
            {
                $this->id   = $id;
                $this->args = $args;
            }

            public function add_product($product, int $quantity, array $totals): int
            {
                $item_id = count($this->items) + 1;
                $item = new WC_Order_Item();
                $item->meta_data = [
                    '_product_id' => $product->get_id(),
                    '_quantity'   => $quantity,
                    '_subtotal'   => $totals['subtotal'] ?? 0,
                    '_total'      => $totals['total'] ?? 0,
                ];
                $this->items[$item_id] = $item;

                return $item_id;
            }

            public function get_item(int $item_id): ?WC_Order_Item
            {
                return $this->items[$item_id] ?? null;
            }

            public function set_address(array $address, string $type): void
            {
                $this->addresses[$type] = $address;
            }

            public function set_payment_method(string $value): void { $this->meta['_payment_method'] = $value; }
            public function set_payment_method_title(string $value): void { $this->meta['_payment_method_title'] = $value; }
            public function set_currency(string $value): void { $this->meta['_order_currency'] = $value; }
            public function set_date_created(int $timestamp): void { $this->meta['_date_created'] = $timestamp; }
            public function set_customer_note(string $value): void { $this->meta['_customer_note'] = $value; }
            public function update_meta_data(string $key, $value): void { $this->meta[$key] = $value; }
            public function calculate_totals(bool $and_taxes = true): void {}
            public function save(): int
            {
                FakeWpEnvironment::$posts[$this->id] = $this;
                FakeWpEnvironment::$post_types[$this->id] = 'shop_order';
                foreach ($this->meta as $key => $value) {
                    FakeWpEnvironment::updatePostMeta($this->id, $key, $value);
                }

                return $this->id;
            }
            public function get_id(): int { return $this->id; }
        }
    }

    if (!class_exists('WC_Order_Query')) {
        class WC_Order_Query
        {
            private array $args;

            public function __construct(array $args)
            {
                $this->args = $args;
            }

            public function get_orders(): array
            {
                return FakeWpEnvironment::getPostsByQuery([
                    'post_type'  => 'shop_order',
                    'meta_key'   => $this->args['meta_key'] ?? '',
                    'meta_value' => $this->args['meta_value'] ?? '',
                    'fields'     => 'ids',
                ]);
            }
        }
    }

    function add_filter(string $hook, string $callback): void
    {
        FakeWpEnvironment::$filters[$hook] = $callback;
    }

    function get_option(string $key, $default = false)
    {
        return FakeWpEnvironment::$options[$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = false): void
    {
        FakeWpEnvironment::$options[$key] = $value;
    }

    function delete_option(string $key): void
    {
        unset(FakeWpEnvironment::$options[$key]);
    }

    function wp_parse_args($value, array $args): array
    {
        return FakeWpEnvironment::parseArgs($value, $args);
    }

    function sanitize_title(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim((string) $value, '-');
    }

    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }

    function sanitize_user(string $username, bool $strict = false): string
    {
        $username = strtolower($username);
        $username = preg_replace('/[^a-z0-9_\-]/', '', $username);

        return (string) $username;
    }

    function is_email(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        return strip_tags($text);
    }

    function wp_kses(string $html, array $allowed_tags): string
    {
        return strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li>');
    }

    function wp_json_encode($value, int $flags = 0): string
    {
        return (string) json_encode($value, $flags);
    }

    function trailingslashit(string $value): string
    {
        return rtrim($value, '/') . '/';
    }

    function add_query_arg(array $query, string $url): string
    {
        if (empty($query)) {
            return $url;
        }

        return $url . '?' . http_build_query($query);
    }

    function esc_url_raw(string $url): string
    {
        return $url;
    }

    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }

    function wc_clean(string $value): string
    {
        return trim(strip_tags($value));
    }

    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }

    function get_term_by(string $field, string $value, string $taxonomy)
    {
        if ($field !== 'slug') {
            return null;
        }

        return FakeWpEnvironment::getTermBySlug($value, $taxonomy);
    }

    function wp_insert_term(string $name, string $taxonomy, array $payload)
    {
        return FakeWpEnvironment::insertTerm($taxonomy, $name, $payload);
    }

    function wp_update_term(int $term_id, string $taxonomy, array $payload)
    {
        return FakeWpEnvironment::updateTerm($term_id, $taxonomy, $payload);
    }

    function wc_get_product_id_by_sku(string $sku): int
    {
        $product_id = FakeWpEnvironment::findProductIdBySku($sku, 'product');
        if ($product_id) {
            return $product_id;
        }

        return FakeWpEnvironment::findProductIdBySku($sku, 'product_variation');
    }

    function wc_get_product(int $product_id)
    {
        return FakeWpEnvironment::getPost($product_id);
    }

    function get_page_by_path(string $path, string $output = OBJECT, string $post_type = 'page')
    {
        return FakeWpEnvironment::findPostByPath($path, $post_type);
    }

    function wp_set_object_terms(int $object_id, $terms, string $taxonomy): void
    {
        if ($taxonomy === 'product_type') {
            FakeWpEnvironment::setPostType($object_id, (string) $terms === 'variation' ? 'product_variation' : 'product');
        }

        FakeWpEnvironment::updatePostMeta($object_id, '_terms_' . $taxonomy, (array) $terms);
    }

    function get_post_type(int $post_id): string
    {
        return FakeWpEnvironment::getPostType($post_id);
    }

    function wc_delete_product_transients(int $product_id): void
    {
    }

    function update_post_meta(int $post_id, string $meta_key, $value): void
    {
        FakeWpEnvironment::updatePostMeta($post_id, $meta_key, $value);
    }

    function get_post_meta(int $post_id, string $meta_key, bool $single = true)
    {
        return FakeWpEnvironment::getPostMeta($post_id, $meta_key, $single);
    }

    function get_posts(array $query): array
    {
        return FakeWpEnvironment::getPostsByQuery($query);
    }

    function get_post_thumbnail_id(int $post_id): int
    {
        return FakeWpEnvironment::getPostThumbnail($post_id);
    }

    function set_post_thumbnail(int $post_id, int $attachment_id): void
    {
        FakeWpEnvironment::setPostThumbnail($post_id, $attachment_id);
    }

    function get_user_by(string $field, $value)
    {
        return FakeWpEnvironment::getUserBy($field, $value);
    }

    function wp_create_user(string $username, string $password, string $email)
    {
        return FakeWpEnvironment::createUser($username, $email);
    }

    function wp_generate_password(int $length = 20, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return str_repeat('p', $length);
    }

    function wp_update_user(array $payload): void
    {
        FakeWpEnvironment::updateUser($payload);
    }

    function update_user_meta(int $user_id, string $meta_key, $value): void
    {
        FakeWpEnvironment::updateUserMeta($user_id, $meta_key, $value);
    }

    function username_exists(string $username): bool
    {
        return FakeWpEnvironment::usernameExists($username);
    }

    function maybe_serialize($value)
    {
        return is_array($value) || is_object($value) ? serialize($value) : $value;
    }

    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }

    function absint($value): int
    {
        return abs((int) $value);
    }

    function download_url(string $url, int $timeout = 60)
    {
        $path = tempnam(sys_get_temp_dir(), 'mcm');
        file_put_contents($path, 'image');

        return $path;
    }

    function media_handle_sideload(array $file_array, int $post_id, ?string $description = null)
    {
        return FakeWpEnvironment::createAttachment((string) ($file_array['name'] ?? 'attachment.jpg'), $post_id);
    }

    function wc_create_order(array $args = [])
    {
        return FakeWpEnvironment::createOrder($args);
    }

    function wp_remote_get(string $url, array $args = [])
    {
        return [
            'response' => ['code' => 200],
            'body'     => '[]',
        ];
    }

    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 200);
    }

    function wp_remote_retrieve_body(array $response): string
    {
        return (string) ($response['body'] ?? '');
    }
}
