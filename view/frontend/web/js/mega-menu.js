/* Etechflow_MegaMenu — vanilla JS driver for the Luma / generic template.
 * Hyvä uses Alpine.js inline in its phtml and does NOT load this file.
 *
 * Behaviour:
 *  - Hover (mouse) or focus-within (keyboard) on a top-level item opens its popover.
 *  - On open, lazy-fetches /megamenu/products/index?cat=<id> and renders subs + featured cards.
 *  - Mobile hamburger toggles a slide-out panel with drill-down per top-level cat.
 *  - No jQuery dependency. ES6+ (works in every browser Magento 2.4+ supports).
 */
(function () {
    'use strict';

    var nav = document.querySelector('.etmm--luma');
    if (!nav) {
        return;
    }

    var endpoint = nav.dataset.etmmEndpoint || '/megamenu/products/index';
    var hasThumbs = nav.dataset.etmmThumbs === '1';
    var dataCache = Object.create(null);

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    function fetchCat(id) {
        if (dataCache[id]) {
            return Promise.resolve(dataCache[id]);
        }
        return fetch(endpoint + '?cat=' + id, {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (json) { dataCache[id] = json; return json; })
            .catch(function () { return {subcategories: [], products: []}; });
    }

    function subsListHtml(subs, nested) {
        if (!subs || !subs.length) return '';
        var html = '<ul class="etmm__sublist' + (nested ? ' etmm__sublist--nested' : '') + '">';
        subs.forEach(function (s) {
            html += '<li><a class="etmm__sublink" data-etmm-sub="' + (s.id | 0) + '" href="' + esc(s.url) + '">'
                +     '<span>' + esc(s.name) + '</span>'
                +     '<small class="etmm__count">(' + (s.count | 0) + ')</small>'
                +   '</a></li>';
        });
        html += '</ul>';
        return html;
    }

    function cardsHtml(products) {
        if (!products || !products.length) return '';
        var html = '<ul class="etmm__cards">';
        products.forEach(function (p) {
            html += '<li><a class="etmm__card" href="' + esc(p.url) + '">';
            if (hasThumbs && p.img) {
                html += '<img class="etmm__card-img" loading="lazy" decoding="async" src="' + esc(p.img) + '" alt="' + esc(p.name) + '">';
            }
            html += '<span class="etmm__card-name">' + esc(p.name) + '</span>';
            html += '<span class="etmm__card-price">' + (p.price_formatted || '') + '</span>';
            html += '</a></li>';
        });
        html += '</ul>';
        return html;
    }

    function renderSubs(container, subs) {
        if (!container) return;
        container.innerHTML = (subs && subs.length)
            ? subsListHtml(subs, false)
            : '<p class="etmm__muted">No subcategories.</p>';
    }

    function renderProducts(container, products) {
        if (!container) return;
        container.innerHTML = cardsHtml(products);
    }

    // Hovering a subcategory shows ITS sub-sub-categories (third level) as links,
    // followed by that subcategory's products, in the right-hand panel.
    function renderSubDetail(container, subs, products) {
        if (!container) return;
        var html = subsListHtml(subs, true) + cardsHtml(products);
        container.innerHTML = html || '<p class="etmm__muted">No items.</p>';
    }

    /* ---- Desktop dropdowns ---- */
    nav.querySelectorAll('.etmm__item').forEach(function (item) {
        var pop = item.querySelector('.etmm__pop');
        if (!pop) return;
        var catId = parseInt(item.dataset.etmmCat || '0', 10);
        if (!catId) return;
        var link = item.querySelector('.etmm__link');
        var subsEl = item.querySelector('[data-etmm-subs]');
        var featEl = item.querySelector('[data-etmm-featured]');
        var parentProducts = [];

        // Hovering a subcategory swaps the featured panel to THAT subcategory's
        // products. Previously the panel was stuck on the parent category's products.
        var wireSubHover = function () {
            if (!subsEl || !featEl) return;
            subsEl.querySelectorAll('.etmm__sublink').forEach(function (slink) {
                var sid = parseInt(slink.dataset.etmmSub || '0', 10);
                if (!sid) return;
                slink.addEventListener('mouseenter', function () {
                    fetchCat(sid).then(function (sd) {
                        // Show this subcategory's children (sub-sub-categories) + its products.
                        renderSubDetail(featEl, sd.subcategories, sd.products);
                    });
                });
            });
        };

        var hydrate = function () {
            if (pop.dataset.etmmHydrated === '1') return;
            pop.dataset.etmmHydrated = '1';
            fetchCat(catId).then(function (data) {
                parentProducts = data.products || [];
                renderSubs(subsEl, data.subcategories);
                renderProducts(featEl, parentProducts);
                wireSubHover();
            });
        };
        var show = function () {
            pop.hidden = false;
            link.setAttribute('aria-expanded', 'true');
            hydrate();
            // Reset the featured panel back to the parent category each time it opens.
            if (featEl && parentProducts.length) {
                renderProducts(featEl, parentProducts);
            }
        };
        var hide = function () {
            pop.hidden = true;
            link.setAttribute('aria-expanded', 'false');
        };

        item.addEventListener('mouseenter', show);
        item.addEventListener('mouseleave', hide);
        item.addEventListener('focusin', show);
        item.addEventListener('focusout', function (e) {
            if (!item.contains(e.relatedTarget)) hide();
        });
    });

    /* ---- Mobile drawer ---- */
    var hamburger = nav.querySelector('[data-etmm-mobile-toggle]');
    var panel = nav.querySelector('[data-etmm-mobile-panel]');
    var closeBtn = nav.querySelector('[data-etmm-mobile-close]');

    function openMobile() {
        if (!panel) return;
        panel.hidden = false;
        hamburger && hamburger.setAttribute('aria-expanded', 'true');
    }
    function closeMobile() {
        if (!panel) return;
        panel.hidden = true;
        hamburger && hamburger.setAttribute('aria-expanded', 'false');
    }
    hamburger && hamburger.addEventListener('click', function () {
        panel.hidden ? openMobile() : closeMobile();
    });
    closeBtn && closeBtn.addEventListener('click', closeMobile);
    panel && panel.addEventListener('click', function (e) {
        if (e.target === panel) closeMobile();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobile();
    });

    nav.querySelectorAll('[data-etmm-mobile-sub]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('[data-etmm-mobile-cat]');
            if (!li) return;
            var catId = parseInt(li.dataset.etmmMobileCat || '0', 10);
            var sublist = li.querySelector('[data-etmm-mobile-sublist]');
            if (!catId || !sublist) return;
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                sublist.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = '+';
                return;
            }
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = '−';
            sublist.hidden = false;
            if (sublist.dataset.etmmHydrated !== '1') {
                sublist.dataset.etmmHydrated = '1';
                sublist.innerHTML = '<li class="etmm__muted">Loading…</li>';
                fetchCat(catId).then(function (data) {
                    if (!data.subcategories || !data.subcategories.length) {
                        sublist.innerHTML = '<li class="etmm__muted">No subcategories.</li>';
                        return;
                    }
                    sublist.innerHTML = data.subcategories.map(function (s) {
                        return '<li><a href="' + esc(s.url) + '">' + esc(s.name) + '</a></li>';
                    }).join('');
                });
            }
        });
    });
})();
