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

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
            $json = file_get_contents($cacheFile);
            if ($json !== false) {
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
