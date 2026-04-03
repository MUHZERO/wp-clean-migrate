<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Helpers;

use MuhCleanMigrator\Core\Logger;

/**
 * Reuses or imports product images in an idempotent way.
 */
final class ProductImageImporter
{
    private const SOURCE_URL_META_KEY = '_muh_source_image_url';

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Imports and assigns a featured image and gallery images for a product.
     *
     * @param array<int, array<string, mixed>> $images Remote image payloads.
     * @return array{downloaded:int,failed:int}
     */
    public function syncProductImages(int $post_id, array $images, string $label): array
    {
        $this->loadWordPressMediaDependencies();

        $result = [
            'downloaded' => 0,
            'failed'     => 0,
        ];

        if (empty($images)) {
            return $result;
        }

        $existing_gallery_ids = $this->getGalleryImageIds($post_id);
        $gallery_ids          = [];

        foreach ($images as $index => $image) {
            $src   = trim((string) ($image['src'] ?? ''));
            $title = trim((string) ($image['name'] ?? ''));

            if (!$this->isAllowedImageUrl($src)) {
                ++$result['failed'];
                $this->logger->warning("Rejected image for {$label}: {$src}");
                continue;
            }

            $resolved = $this->resolveAttachmentId($src, $post_id, $title);
            if (!$resolved['attachment_id']) {
                ++$result['failed'];
                $this->logger->warning("Failed importing image for {$label}: {$src}");
                continue;
            }

            if ($resolved['downloaded']) {
                ++$result['downloaded'];
                $this->logger->log("Imported image for {$label} -> attachment {$resolved['attachment_id']}");
            } else {
                $this->logger->log("Reused image for {$label} -> attachment {$resolved['attachment_id']}");
            }

            if ((int) $index === 0) {
                if ((int) get_post_thumbnail_id($post_id) !== $resolved['attachment_id']) {
                    set_post_thumbnail($post_id, $resolved['attachment_id']);
                }
                continue;
            }

            $gallery_ids[] = $resolved['attachment_id'];
        }

        $merged_gallery_ids = array_values(
            array_unique(
                array_merge($existing_gallery_ids, $gallery_ids)
            )
        );

        update_post_meta($post_id, '_product_image_gallery', implode(',', $merged_gallery_ids));

        return $result;
    }

    /**
     * Imports and assigns an image for a variation when available.
     *
     * @param array<string, mixed> $image Remote image payload.
     * @return array{downloaded:int,failed:int,assigned:bool}
     */
    public function syncVariationImage(int $variation_id, array $image, string $label): array
    {
        $this->loadWordPressMediaDependencies();

        $result = [
            'downloaded' => 0,
            'failed'     => 0,
            'assigned'   => false,
        ];

        $src = trim((string) ($image['src'] ?? ''));
        if (!$this->isAllowedImageUrl($src)) {
            if ($src !== '') {
                ++$result['failed'];
                $this->logger->warning("Rejected variation image for {$label}: {$src}");
            }

            return $result;
        }

        $resolved = $this->resolveAttachmentId($src, $variation_id, trim((string) ($image['name'] ?? '')));
        if (!$resolved['attachment_id']) {
            ++$result['failed'];
            $this->logger->warning("Failed importing variation image for {$label}: {$src}");
            return $result;
        }

        $variation = wc_get_product($variation_id);
        if (!$variation instanceof \WC_Product_Variation) {
            ++$result['failed'];
            return $result;
        }

        if ((int) $variation->get_image_id() !== $resolved['attachment_id']) {
            $variation->set_image_id($resolved['attachment_id']);
            $variation->save();
        }

        $result['assigned'] = true;

        if ($resolved['downloaded']) {
            ++$result['downloaded'];
            $this->logger->log("Imported variation image for {$label} -> attachment {$resolved['attachment_id']}");
        } else {
            $this->logger->log("Reused variation image for {$label} -> attachment {$resolved['attachment_id']}");
        }

        return $result;
    }

    /**
     * Finds or downloads an attachment for a remote source URL.
     *
     * @return array{attachment_id:int,downloaded:bool}
     */
    private function resolveAttachmentId(string $url, int $post_id, string $title): array
    {
        $attachment_id = $this->findAttachmentBySourceUrl($url);

        if ($attachment_id) {
            return [
                'attachment_id' => $attachment_id,
                'downloaded'    => false,
            ];
        }

        $attachment_id = $this->sideloadProductImage($url, $post_id, $title);
        if (!$attachment_id) {
            return [
                'attachment_id' => 0,
                'downloaded'    => false,
            ];
        }

        update_post_meta($attachment_id, self::SOURCE_URL_META_KEY, esc_url_raw($url));

        return [
            'attachment_id' => $attachment_id,
            'downloaded'    => true,
        ];
    }

    private function findAttachmentBySourceUrl(string $url): int
    {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::SOURCE_URL_META_KEY,
            'meta_value'     => esc_url_raw($url),
        ]);

        return !empty($attachments[0]) ? (int) $attachments[0] : 0;
    }

    /**
     * @return array<int, int>
     */
    private function getGalleryImageIds(int $post_id): array
    {
        $gallery = (string) get_post_meta($post_id, '_product_image_gallery', true);
        if ($gallery === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map('absint', explode(',', $gallery))
            )
        );
    }

    private function sideloadProductImage(string $url, int $post_id, string $title = ''): int
    {
        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            return 0;
        }

        $file_array = [
            'name'     => $this->safeImageFilenameFromUrl($url),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id, $title ?: null);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }

        return (int) $attachment_id;
    }

    private function loadWordPressMediaDependencies(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    private function safeImageFilenameFromUrl(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $base = $path ? basename((string) $path) : 'product-image.jpg';
        $base = preg_replace('/[^A-Za-z0-9\.\-_]/', '-', (string) $base);

        if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', (string) $base)) {
            $base .= '.jpg';
        }

        return strtolower((string) $base);
    }

    private function isAllowedImageUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $lower = strtolower($url);
        foreach (['.php', '.phtml', '.php8', '.phar', '.svg', '.gif'] as $blocked) {
            if (strpos($lower, $blocked) !== false) {
                return false;
            }
        }

        return true;
    }
}
