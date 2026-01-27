/**
 * Microlink – Link previews for links with class "link-preview" or "microlink-preview".
 * Calls the plugin cache endpoint (/microlink-cache) instead of the Microlink API directly.
 *
 * In Grav markdown: [Link text](https://example.com?classes=link-preview&target=_blank)
 */
(function () {
    'use strict';

    const MICROLINK_API = (document.body && document.body.dataset.microlinkCache) ? document.body.dataset.microlinkCache : '/microlink-cache';

    function isExternalUrl(href) {
        try {
            return href && (href.startsWith('http://') || href.startsWith('https://'));
        } catch {
            return false;
        }
    }

    function buildPreviewCard(data, originalUrl) {
        const card = document.createElement('a');
        card.href = originalUrl;
        card.target = '_blank';
        card.rel = 'noopener noreferrer';
        card.className = 'microlink-preview-card';

        const image = data.image?.url;
        const title = data.title || data.url || originalUrl;
        const description = data.description || '';
        if (!image) card.classList.add('microlink-preview-card--no-image');

        var imagePart = '';
        if (image) {
            var escapedUrl = image.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            imagePart = '<span class="microlink-preview-card__image" style="background-image: url(\'' + escapedUrl + '\')"></span>';
        }
        var hostname = '';
        try { hostname = new URL(originalUrl).hostname; } catch (e) { hostname = originalUrl; }
        card.innerHTML = imagePart +
            '<span class="microlink-preview-card__content">' +
            '<span class="microlink-preview-card__title">' + escapeHtml(title) + '</span>' +
            (description ? '<span class="microlink-preview-card__description">' + escapeHtml(description.slice(0, 120)) + (description.length > 120 ? '…' : '') + '</span>' : '') +
            '<span class="microlink-preview-card__url">' + escapeHtml(hostname) + '</span>' +
            '</span>';

        return card;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function wrapLinkWithPreview(link) {
        if (link.closest('.microlink-preview-wrapper') || link.dataset.microlinkProcessed === 'true') {
            return;
        }
        link.dataset.microlinkProcessed = 'true';

        var parent = link.parentNode;
        var isInsideP = parent && parent.tagName === 'P';

        var wrapper = document.createElement('div');
        wrapper.className = 'microlink-preview-wrapper';

        if (isInsideP) {
            var grandParent = parent.parentNode;
            var nextAfterP = parent.nextSibling;
            grandParent.insertBefore(wrapper, nextAfterP);
        } else {
            parent.insertBefore(wrapper, link);
        }
        wrapper.appendChild(link);

        var placeholder = document.createElement('div');
        placeholder.className = 'microlink-preview-placeholder';
        placeholder.innerHTML = '<span class="microlink-preview-loading">Loading preview…</span>';
        wrapper.appendChild(placeholder);

        const url = link.href;
        const urlForApi = url.replace(/[?&](?:classes|target|rel)=[^&]*/gi, '').replace(/\?&/, '?').replace(/\?$/, '') || url;
        fetch(MICROLINK_API + '?url=' + encodeURIComponent(urlForApi) + '&screenshot=true', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                placeholder.remove();
                if (res.status === 'success' && res.data) {
                    var card = buildPreviewCard(res.data, urlForApi);
                    wrapper.appendChild(card);
                }
            })
            .catch(() => {
                placeholder.remove();
            });
    }

    function initMicrolinkPreviews() {
        const container = document.querySelector('.markdown-body');
        if (!container) return;

        const selector = 'a[href^="http"].link-preview, a[href^="http"].microlink-preview';
        const links = container.querySelectorAll(selector);
        links.forEach(link => {
            if (isExternalUrl(link.href)) {
                wrapLinkWithPreview(link);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMicrolinkPreviews);
    } else {
        initMicrolinkPreviews();
    }
})();
