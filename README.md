# Etechflow_MegaMenu

A theme-agnostic mega menu for Magento 2.

## At a glance

- **Themes supported:** Hyvä, Luma, Adobe Commerce default, and any custom theme inheriting from either.
- **No jQuery dependency** in the Luma variant — uses vanilla JS only, so it stays light and works in lean themes.
- **No Knockout / RequireJS** in the Hyvä variant — uses Alpine.js (already shipped with every Hyvä theme).
- **Lazy-loaded data:** dropdown subcategories + featured products are fetched on first hover from a 1-hour-cached JSON endpoint (`/megamenu/products/index?cat=<id>`).
- **Auto theme detection** at runtime — picks the right template per request.

## Quick install

```bash
# from your Magento root
cp -r /path/to/Etechflow_MegaMenu app/code/Etechflow/MegaMenu
bin/magento module:enable Etechflow_MegaMenu
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

See the top-level **INSTALL.md** in the distribution ZIP for full instructions (3 install methods, production-mode notes, troubleshooting).

## Where to configure

Stores → Configuration → eTechFlow → Mega Menu:

- Master enable/disable
- Root Category ID (which category's children become the top-level menu items)
- **Attach All Active Categories** — Yes = every active category & sub-category auto-shows on enable, even ones set to "Include in Menu = No". No (default) = respect each category's flag. (Categories attach automatically either way — this just controls hidden ones.)
- **Custom Menu Links** — add your own top-level items (Label + URL/path + Sort). They merge in after the category items. Lets admins add custom links without touching categories.
- Max depth, featured products per category, mobile drill-down toggle, cache TTL
- **Featured Products Source** — choose what fills each dropdown's product column:
  Category Position (default) / Newest / Best Sellers / On Sale / Price Low→High / Price High→Low
- **Dropdown Promo CMS Block** — optional CMS block identifier rendered as a banner/column
  inside every dropdown (Content → Blocks). Leave blank for none.

## Files

```
Etechflow_MegaMenu/
├── composer.json
├── registration.php
├── etc/                # module.xml, config.xml, acl.xml, routes.xml, system.xml, di.xml
├── Controller/         # JSON endpoint (megamenu/products/index)
├── Block/              # MegaMenu renderer (picks template by theme)
├── ViewModel/          # MenuData — top-level categories + theme detection
├── Model/Config.php    # Typed config reader
├── view/frontend/      # Layout, both phtml variants, CSS, vanilla JS
└── i18n/en_US.csv
```

## Override points

- **Template:** drop your own `mega-menu.phtml` (or `mega-menu-luma.phtml`) under
  `app/design/frontend/<Vendor>/<theme>/Etechflow_MegaMenu/templates/`.
- **CSS:** override `mega-menu.css` the same way.
- **Block:** subclass `Etechflow\MegaMenu\Block\MegaMenu` and rebind via your theme's `di.xml`.
- **Data:** subclass `ViewModel\MenuData` for custom queries or pre-baked menus.

## Public route

`GET /megamenu/products/index?cat=<id>` → JSON:

```json
{
  "subcategories": [{"id":3,"name":"Range Rover","url":"https://…","count":42}, …],
  "products":      [{"name":"…","price":29.99,"price_formatted":"£29.99","url":"…","img":"…"}, …]
}
```

## License

MIT — see `LICENSE.md` in the distribution.
