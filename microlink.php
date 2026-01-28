<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Processors\Events\RequestHandlerEvent;
use Grav\Framework\Psr7\Response;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Cache côté serveur des réponses Microlink dans user/data/microlink.
 * Expose /microlink-cache?url=... utilisé par le JS des previews.
 */
class MicrolinkPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onRequestHandlerInit' => ['onRequestHandlerInit', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }
        $this->enable([
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    public function onTwigSiteVariables(): void
    {
        $base = rtrim($this->grav['base_url_relative'] ?? '', '/');
        $this->grav['twig']->twig_vars['microlink_cache_url'] = ($base ? $base . '/' : '/') . 'microlink-cache';

        if ($this->config->get('plugins.microlink.built_in_css', true)) {
            $this->grav['assets']->addCss('plugin://microlink/assets/microlink-previews.css', 101);
        }
        if ($this->config->get('plugins.microlink.built_in_js', true)) {
            $this->grav['assets']->addJs('plugin://microlink/assets/microlink-previews.js', ['group' => 'bottom', 'loading' => 'defer']);
        }
    }

    public function onRequestHandlerInit(RequestHandlerEvent $event): void
    {
        $request = $event->getRequest();
        $path = trim($request->getUri()->getPath(), '/');
        $parts = $path !== '' ? explode('/', $path) : [];

        $idx = array_search('microlink-cache', $parts, true);
        if ($idx === false) {
            return;
        }

        // Serve cached image: .../microlink-cache/image/{filename}
        if (isset($parts[$idx + 1]) && $parts[$idx + 1] === 'image' && isset($parts[$idx + 2])) {
            $filename = $parts[$idx + 2];
            if (preg_match('/^[a-f0-9]{32}\.(png|jpe?g|gif|webp|ico|svg)$/i', $filename)) {
                $imagesDir = $this->getImagesDir();
                $filePath = $imagesDir . '/' . $filename;
                if (is_file($filePath) && is_readable($filePath)) {
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'][$ext] ?? 'application/octet-stream';
                    $body = file_get_contents($filePath);
                    $event->setResponse(new Response(200, ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=604800'], $body));
                    return;
                }
            }
            $event->setResponse(new Response(404, ['Content-Type' => 'application/json'], json_encode(['status' => 'error', 'message' => 'Image not found'])));
            return;
        }

        // API: .../microlink-cache?url=...
        if (end($parts) !== 'microlink-cache') {
            return;
        }

        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        $url = isset($params['url']) ? trim($params['url']) : '';

        if ($url === '' || !$this->isValidTargetUrl($url)) {
            $event->setResponse(new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'error',
                'message' => 'Invalid url parameter or missing',
            ])));
            return;
        }

        $urlNormalized = $this->normalizeUrl($url);
        $cacheKey = md5($urlNormalized);
        $cacheDir = $this->getCacheDir();
        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        $ttlDays = (int) ($this->config->get('plugins.microlink.cache_ttl_days') ?: 7);
        $ttlSeconds = $ttlDays * 86400;

        $baseUrl = rtrim($this->grav['base_url_relative'] ?? '', '/');
        $imageBase = ($baseUrl ? $baseUrl . '/' : '/') . 'microlink-cache/image';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
            $json = file_get_contents($cacheFile);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $this->cacheImagesInData($data, $imageBase);
                    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_dir($cacheDir) && is_writable($cacheDir)) {
                        file_put_contents($cacheFile, $json, LOCK_EX);
                    }
                }
                $event->setResponse(new Response(200, ['Content-Type' => 'application/json'], $json));
                return;
            }
        }

        $apiUrl = 'https://api.microlink.io?url=' . rawurlencode($urlNormalized) . '&screenshot=true';
        $json = @file_get_contents($apiUrl);

        if ($json === false || $json === '') {
            $event->setResponse(new Response(502, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'error',
                'message' => 'Unable to retrieve Microlink data',
            ])));
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $event->setResponse(new Response(502, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'error',
                'message' => 'Invalid Microlink response',
            ])));
            return;
        }

        $this->cacheImagesInData($data, $imageBase);

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            file_put_contents($cacheFile, $json, LOCK_EX);
        }

        $event->setResponse(new Response(200, ['Content-Type' => 'application/json'], $json));
    }

    private function getCacheDir(): string
    {
        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $base = $locator->findResource('user://data', true, true);
        return rtrim($base, DIRECTORY_SEPARATOR) . '/microlink';
    }

    private function getImagesDir(): string
    {
        return $this->getCacheDir() . '/images';
    }

    /**
     * Download image URLs from Microlink response, store locally, and replace URLs in $data.
     */
    private function cacheImagesInData(array &$data, string $imageBaseUrl): void
    {
        $imageKeys = ['image', 'logo', 'screenshot'];
        $imagesDir = $this->getImagesDir();

        foreach ($imageKeys as $key) {
            if (!isset($data['data'][$key]['url']) || !is_string($data['data'][$key]['url'])) {
                continue;
            }
            $imageUrl = $data['data'][$key]['url'];
            if (strpos($imageUrl, $imageBaseUrl) === 0) {
                continue;
            }
            $ext = $this->getImageExtensionFromUrl($imageUrl);
            $hash = md5($imageUrl);
            $filename = $hash . '.' . $ext;
            $filePath = $imagesDir . '/' . $filename;

            if (!is_file($filePath)) {
                if (!is_dir($imagesDir)) {
                    @mkdir($imagesDir, 0755, true);
                }
                if (is_dir($imagesDir) && is_writable($imagesDir)) {
                    $content = @file_get_contents($imageUrl);
                    if ($content !== false && $content !== '') {
                        $detectedExt = $this->getImageExtensionFromContentType($imageUrl, $content);
                        if ($detectedExt !== $ext) {
                            $filename = $hash . '.' . $detectedExt;
                            $filePath = $imagesDir . '/' . $filename;
                        }
                        file_put_contents($filePath, $content, LOCK_EX);
                    }
                }
            }

            if (is_file($filePath)) {
                $data['data'][$key]['url'] = $imageBaseUrl . '/' . $filename;
            }
        }
    }

    private function getImageExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path !== null && $path !== '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'], true)) {
                return $ext === 'jpeg' ? 'jpg' : $ext;
            }
        }
        return 'jpg';
    }

    private function getImageExtensionFromContentType(string $url, string $content): string
    {
        $ext = $this->getImageExtensionFromUrl($url);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);
        $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/svg+xml' => 'svg'];
        return $map[$mime] ?? $ext;
    }

    private function isValidTargetUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function normalizeUrl(string $url): string
    {
        $url = preg_replace('/[?&](?:classes|target|rel)=[^&]*/i', '', $url);
        $url = preg_replace('/\?&/', '?', $url);
        return rtrim(preg_replace('/\?$/', '', $url), '?') ?: $url;
    }
}
