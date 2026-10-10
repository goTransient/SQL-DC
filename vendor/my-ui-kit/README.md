# My UI Kit

A framework-free HTML/CSS/JavaScript reference library and reusable component factory API. No npm, build step, Bootstrap, jQuery, or external assets are required.

## Run the reference library

Serve this folder locally for consistent behavior:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/`. The `components/` and `pages/` folders contain standalone reference examples. `components/api-demo.html` demonstrates the packaged `UIKit` API.

### Reference library localization

The reference pages load `js/i18n.js` before the page interaction scripts. It provides Vietnamese (`vi`, the default) and English (`en`), adds a language selector, and stores the selected locale in `localStorage` under `my-ui-kit.locale`.

Mark interface text with a translation key from `js/i18n.js`:

```html
<h1 data-i18n="page.list.title">Danh sách học phần</h1>
```

Use `data-i18n-placeholder`, `data-i18n-aria-label`, `data-i18n-title`, and `data-i18n-value` for the corresponding attributes. In JavaScript, `UI18n.t(key)` returns a translated string, `UI18n.getLocale()` returns the active locale, and `UI18n.setLocale('en')` changes it. A `ui-language-change` document event is dispatched after a locale change. Only marked UI strings are translated; page-authored data and content remain as written.

## Use the packaged API in another project

Copy these two files into your project (for example, `public/vendor/my-ui-kit/`):

- `css/uikit.css`
- `js/uikit.js`

Load them on a page:

```html
<link rel="stylesheet" href="/vendor/my-ui-kit/uikit.css">
<script src="/vendor/my-ui-kit/uikit.js"></script>
```

Create components programmatically:

```javascript
const saveButton = UIKit.createButton({
  text: 'Lưu thay đổi',
  variant: 'primary',
  onClick: () => saveCourse()
});
document.querySelector('#formActions').append(saveButton);

const status = UIKit.createBadge({text: 'Đã duyệt', variant: 'success'});
document.querySelector('#courseStatus').append(status);

const table = UIKit.createTable({
  columns: [
    {key: 'code', label: 'Mã học phần', sortable: true},
    {key: 'name', label: 'Học phần', sortable: true}
  ],
  data: courses
});
document.querySelector('#courseTable').append(table.element);
```

### Public API

- `UIKit.createButton({text, variant, size, disabled, type, title, ariaLabel, onClick})` returns a button element.
- `UIKit.createBadge({text, variant, title})` returns a badge element. Variants: `success`, `warning`, `danger`, `info`.
- `UIKit.createCard({title, content, body, footer})` returns a card element. `content`, `body`, and `footer` can be strings or DOM nodes.
- `UIKit.createModal({title, message, content, confirmText, cancelText, confirmVariant, onConfirm, closeOnConfirm, closeOnBackdrop, returnFocus})` returns `{element, open(), close(), destroy()}`. Append `element` to `document.body` before calling `open()`.
- `UIKit.createTable({columns, data, rows, search, searchPlaceholder, searchLabel, emptyText, translate})` returns `{element, setData(data), setEmptyText(text), getData(), refresh()}`. Column objects support `key`, `label`, `sortable`, and optional `render(value, row)`. `translate(key, values)` can provide localized table labels.
- `UIKit.version` reports the library version.

## Conflict prevention

The API stylesheet uses the `ukit-` class namespace and does not define global `body`, `button`, `input`, `table`, or `:root` rules. This reduces collisions with application CSS. The library does not overwrite or require the existing project's `layout.css` or `components.css`.

The component factory API is separate from the reference library's `ui-` classes. `js/ui.js` remains for interactions on the reference pages; production pages using the factory API only need `uikit.css` and `uikit.js` unless they also use other reference styles.

## Data and security

Application data, authorization, database operations, and business rules remain in the host project. The table renders plain values as text; custom `render` callbacks should return DOM nodes or plain text, not untrusted HTML strings.

## Design tokens

The reference pages use `css/tokens.css`. The packaged factory components use self-contained `ukit-` styles in `css/uikit.css` to avoid depending on the host project's existing token names.
