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

    /* ---- Dynamic alignment: match the bar to the theme's content container ----
     * The block renders full-width in header.container, so to line the bar up with
     * the real page design we measure a representative content element (the header
     * row / main column / breadcrumbs — whichever the active theme actually uses)
     * and copy its left offset, width and side padding onto the bar. This adapts to
     * ANY theme and container width automatically and recomputes on resize. The CSS
     * --etmm-container-max / --etmm-gutter defaults stay as the pre-JS baseline, so
     * there is no misaligned flash before this runs. */
    var bar = nav.querySelector('.etmm__bar');
    var REF_SELECTORS = ['.header.content', '.page-main', '.column.main',
                         '.columns', '.breadcrumbs', '.page-wrapper'];

    function findRef() {
        for (var i = 0; i < REF_SELECTORS.length; i++) {
            var el = document.querySelector(REF_SELECTORS[i]);
            if (el && el.getBoundingClientRect().width > 0) {
                return el;
            }
        }
        return null;
    }

    function alignBar() {
        if (!bar) return;
        // Mobile: the bar is hidden and the hamburger takes over — drop overrides.
        if (window.innerWidth < 768) {
            bar.style.width = bar.style.maxWidth = bar.style.marginLeft =
                bar.style.marginRight = bar.style.paddingLeft = bar.style.paddingRight = '';
            return;
        }
        var ref = findRef();
        if (!ref) return;
        var r = ref.getBoundingClientRect();
        var navLeft = nav.getBoundingClientRect().left;
        var cs = window.getComputedStyle(ref);
        var padL = parseFloat(cs.paddingLeft) || 0;
        var padR = parseFloat(cs.paddingRight) || 0;
        bar.style.maxWidth = 'none';
        bar.style.width = r.width + 'px';
        bar.style.marginLeft = (r.left - navLeft) + 'px';
        bar.style.marginRight = '0';
        // First item's TEXT lands on the container's content edge (minus link padding).
        bar.style.paddingLeft = 'calc(' + padL + 'px - 1.1rem)';
        bar.style.paddingRight = padR + 'px';
    }

    var alignRaf = null;
    function alignBarDebounced() {
        if (window.requestAnimationFrame) {
            if (alignRaf) window.cancelAnimationFrame(alignRaf);
            alignRaf = window.requestAnimationFrame(alignBar);
        } else {
            alignBar();
        }
    }

    alignBar();
    window.addEventListener('resize', alignBarDebounced);
    window.addEventListener('load', alignBar);
    // Re-align once web fonts settle, in case they shift the container width.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(alignBar);
    }
})();
