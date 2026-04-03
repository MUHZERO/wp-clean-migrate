<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Fixtures;

final class ApiFixtures
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function categories(): array
    {
        return [
            [
                'id'          => 10,
                'name'        => 'Supplements',
                'slug'        => 'supplements',
                'description' => '<p>Main category</p>',
                'parent'      => 0,
            ],
            [
                'id'          => 11,
                'name'        => 'Protein',
                'slug'        => 'protein',
                'description' => '<p>Child category</p>',
                'parent'      => 10,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function simpleProduct(): array
    {
        return [
            'id'                => 101,
            'name'              => 'Clean Whey',
            'slug'              => 'clean-whey',
            'sku'               => 'WHEY-001',
            'description'       => '<p>Simple product</p>',
            'short_description' => '<p>Short</p>',
            'status'            => 'publish',
            'type'              => 'simple',
            'catalog_visibility'=> 'visible',
            'regular_price'     => '49.99',
            'sale_price'        => '39.99',
            'featured'          => true,
            'manage_stock'      => true,
            'stock_quantity'    => 12,
            'stock_status'      => 'instock',
            'virtual'           => false,
            'downloadable'      => false,
            'categories'        => [
                ['id' => 10],
            ],
            'images'            => [
                ['src' => 'https://old.example.com/images/simple-main.jpg', 'name' => 'Main'],
                ['src' => 'https://old.example.com/images/simple-gallery.jpg', 'name' => 'Gallery'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variableProduct(): array
    {
        return [
            'id'                 => 201,
            'name'               => 'Clean Tee',
            'slug'               => 'clean-tee',
            'sku'                => 'TEE-PARENT',
            'description'        => '<p>Variable product</p>',
            'short_description'  => '<p>Choose a size</p>',
            'status'             => 'publish',
            'type'               => 'variable',
            'catalog_visibility' => 'visible',
            'featured'           => false,
            'manage_stock'       => false,
            'stock_status'       => 'instock',
            'virtual'            => false,
            'downloadable'       => false,
            'categories'         => [
                ['id' => 10],
            ],
            'attributes'         => [
                [
                    'name'      => 'Size',
                    'options'   => ['Small', 'Medium'],
                    'visible'   => true,
                    'variation' => true,
                ],
                [
                    'name'      => 'Color',
                    'options'   => ['Black'],
                    'visible'   => true,
                    'variation' => true,
                ],
            ],
            'default_attributes' => [
                ['name' => 'Size', 'option' => 'Small'],
            ],
            'images'             => [
                ['src' => 'https://old.example.com/images/variable-main.jpg', 'name' => 'Parent Main'],
                ['src' => 'https://old.example.com/images/variable-gallery.jpg', 'name' => 'Parent Gallery'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function variableProductVariations(): array
    {
        return [
            [
                'id'             => 301,
                'sku'            => 'TEE-S-BLK',
                'status'         => 'publish',
                'regular_price'  => '19.99',
                'sale_price'     => '17.99',
                'manage_stock'   => true,
                'stock_quantity' => 5,
                'stock_status'   => 'instock',
                'virtual'        => false,
                'downloadable'   => false,
                'attributes'     => [
                    ['name' => 'Size', 'option' => 'Small'],
                    ['name' => 'Color', 'option' => 'Black'],
                ],
                'image'          => [
                    'src'  => 'https://old.example.com/images/variation-small.jpg',
                    'name' => 'Variation Small',
                ],
            ],
            [
                'id'             => 302,
                'sku'            => 'TEE-M-BLK',
                'status'         => 'publish',
                'regular_price'  => '19.99',
                'sale_price'     => '',
                'manage_stock'   => true,
                'stock_quantity' => 3,
                'stock_status'   => 'instock',
                'virtual'        => false,
                'downloadable'   => false,
                'attributes'     => [
                    ['name' => 'Size', 'option' => 'Medium'],
                    ['name' => 'Color', 'option' => 'Black'],
                ],
                'image'          => [
                    'src'  => 'https://old.example.com/images/variation-medium.jpg',
                    'name' => 'Variation Medium',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(): array
    {
        return [
            'id'         => 401,
            'email'      => 'buyer@example.com',
            'username'   => 'buyer',
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
            'billing'    => [
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'company'    => 'Muh',
                'address_1'  => '123 Main',
                'address_2'  => '',
                'city'       => 'Casablanca',
                'state'      => 'CAS',
                'postcode'   => '20000',
                'country'    => 'MA',
                'email'      => 'buyer@example.com',
                'phone'      => '123456789',
            ],
            'shipping'   => [
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'company'    => '',
                'address_1'  => '123 Main',
                'address_2'  => '',
                'city'       => 'Casablanca',
                'state'      => 'CAS',
                'postcode'   => '20000',
                'country'    => 'MA',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(): array
    {
        return [
            'id'                   => 501,
            'number'               => '501',
            'status'               => 'processing',
            'customer_id'          => 401,
            'payment_method'       => 'cod',
            'payment_method_title' => 'Cash on Delivery',
            'currency'             => 'MAD',
            'date_created'         => '2024-01-01T10:00:00',
            'customer_note'        => 'Leave at door',
            'billing'              => [
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'company'    => '',
                'email'      => 'buyer@example.com',
                'phone'      => '123456789',
                'address_1'  => '123 Main',
                'address_2'  => '',
                'city'       => 'Casablanca',
                'state'      => 'CAS',
                'postcode'   => '20000',
                'country'    => 'MA',
            ],
            'shipping'             => [
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'company'    => '',
                'email'      => 'buyer@example.com',
                'phone'      => '123456789',
                'address_1'  => '123 Main',
                'address_2'  => '',
                'city'       => 'Casablanca',
                'state'      => 'CAS',
                'postcode'   => '20000',
                'country'    => 'MA',
            ],
            'line_items'           => [
                [
                    'product_id'   => 101,
                    'variation_id' => 0,
                    'quantity'     => 2,
                    'subtotal'     => '79.98',
                    'total'        => '79.98',
                    'meta_data'    => [
                        ['key' => '_transaction_id', 'value' => 'txn-123'],
                    ],
                ],
            ],
            'meta_data'            => [
                ['key' => '_transaction_id', 'value' => 'txn-123'],
                ['key' => '_muh_order_source', 'value' => 'legacy'],
                ['key' => '_wp_old_slug', 'value' => 'skip-me'],
            ],
        ];
    }
}
