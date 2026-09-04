# UI screenshots (UI-01, VIEW-01)

Captured against the running Docker stack at `http://localhost:8080` on 2026-09-04, signed in as
`admin@ioms.test` (role `Admin`). Fourteen PNGs, two viewports per page: **desktop 1440x900** and
**mobile 360x800**. All files live in `docs/testing/screenshots/`.

## Inventory

| File | Page | Viewport | What it demonstrates |
|---|---|---|---|
| `login-desktop.png` | `/login` | 1440x900 | Sign-in card, centred, no navigation chrome. |
| `login-360.png` | `/login` | 360x800 | Sign-in form fits 360px exactly; no horizontal overflow. |
| `dashboard-desktop.png` | `/dashboard` | 1440x900 | Admin dashboard, KPI tiles in a four-column grid. |
| `dashboard-360.png` | `/dashboard` | 360x800 | Tiles collapse to a single column and stack vertically. |
| `products-desktop.png` | `/products` | 1440x900 | Product list **with data**: 7-column table, filter bar, 10 rows per page. |
| `products-360.png` | `/products` | 360x800 | Same table rendered as stacked cards with echoed column labels. |
| `products-empty-desktop.png` | `/products?q=zzzznotfound` | 1440x900 | **Empty state**: filter retained in the input, "No products match those filters." |
| `products-empty-360.png` | `/products?q=zzzznotfound` | 360x800 | Empty state at mobile width, including the `Clear` action. |
| `product-detail-desktop.png` | `/products/1` | 1440x900 | Detail page with per-warehouse stock breakdown. |
| `product-detail-360.png` | `/products/1` | 360x800 | Detail definition list and warehouse table at mobile width. |
| `sales-orders-desktop.png` | `/sales-orders` | 1440x900 | Sales-order list with search, status filter, date sort and pagination. |
| `sales-orders-360.png` | `/sales-orders` | 360x800 | Order rows as stacked cards; `Cancelled` status badge visible. |
| `products-create-desktop.png` | `/products/create` | 1440x900 | Create-product form, constrained-width card. |
| `products-create-360.png` | `/products/create` | 360x800 | Same form, full-width fields, labels above inputs. |

## What the 360px captures actually show about UI-01

### A defect was found here, and fixed

The first round of captures exposed a real UI-01 failure. `.nav` was `display: flex` with **no**
`flex-wrap`, so the ten links an Admin sees formed one unbreakable row. The parent
`.topbar__inner` did set `flex-wrap: wrap`, but that only wraps the topbar's own children —
brand, nav, user block — and cannot break up the nav itself. Measured in the browser at a true
360px layout viewport:

```
BEFORE          nav       scrollWidth 932 / laid-out width 932
                document  scrollWidth 948 / viewport 360      -> 588px of sideways scroll
```

Every authenticated page was cut off after "Sales" and the whole document scrolled horizontally.
Against the UI-01 wording *"navigasi dan tabel tidak terpotong"*, the table half held and the
navigation half did not.

The fix was two declarations on `.nav`: `flex-wrap: wrap`, plus `min-width: 0` — a flex item
defaults to `min-width: auto` and refuses to shrink below its content, so wrapping alone would
not have been enough. Re-measured after the change:

```
AFTER           nav       scrollWidth 313
                document  scrollWidth 345 / viewport 360      -> no overflow
```

Every screenshot in this directory was re-captured after the fix. Verified for all seven pages
at both viewports: `scrollWidth == viewport` exactly (360/360 and 1440/1440), so no page scrolls
sideways at either size.

### The table

`app.css` turns rows into stacked cards below 640px — `thead` is hidden, `tr`/`td` become
block/flex, and `td::before { content: attr(data-label) }` re-prints the column name. The
screenshots confirm this is real rather than theoretical: `products-360.png` shows SKU, NAME,
CATEGORY, STOCK, REORDER AT, STATUS and ACTIONS as label/value pairs inside a card, and
`sales-orders-360.png` shows NUMBER, CUSTOMER, FROM, DATE, RAISED BY, STATUS and ACTIONS the same
way. The filter bar, buttons, badges and pagination all fit as well.

### The navigation, after the fix

`dashboard-360.png` and `products-360.png` show the ten Admin links wrapped across four rows,
with the role badge, user name and Sign out button below them. Nothing is clipped. This is the
worst case: Sales and Warehouse Staff see fewer links and therefore fewer rows.

## How these were captured

Headless Chrome cannot carry the PHP session, so the authenticated HTML is fetched with `curl`
and screenshotted from disk with the stylesheet still loaded over HTTP from the server.

```bash
mkdir -p /tmp/agent5 && cd /tmp/agent5

# 1. CSRF token, then log in, keeping the cookie jar
curl -s -c cookie.txt http://localhost:8080/login -o login.html
TOKEN=$(grep -o 'name="_token" value="[^"]*"' login.html | head -1 | sed 's/.*value="//;s/"//')
curl -s -b cookie.txt -c cookie.txt -X POST http://localhost:8080/login \
  -d "_token=$TOKEN" -d "email=admin@ioms.test" -d "password=Password123!"

# 2. Save each page
curl -s -b cookie.txt http://localhost:8080/dashboard          -o dashboard.html
curl -s -b cookie.txt http://localhost:8080/products           -o products.html
curl -s -b cookie.txt http://localhost:8080/products/1         -o product-detail.html
curl -s -b cookie.txt http://localhost:8080/sales-orders       -o sales-orders.html
curl -s -b cookie.txt http://localhost:8080/products/create    -o products-create.html
curl -s -b cookie.txt "http://localhost:8080/products?q=zzzznotfound" -o products-empty.html

# 3. Point root-relative assets at the server so Chrome loads the real CSS
for f in login dashboard products product-detail sales-orders products-create products-empty; do
  sed -i '' \
    -e 's|href="/assets/|href="http://localhost:8080/assets/|g' \
    -e 's|src="/assets/|src="http://localhost:8080/assets/|g'   \
    -e 's|src="/uploads/|src="http://localhost:8080/uploads/|g' \
    -e 's|href="/uploads/|href="http://localhost:8080/uploads/|g' "$f.html"
done

# 4. Start Chrome once with the DevTools protocol exposed
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless=new --disable-gpu --hide-scrollbars --no-sandbox \
  --remote-debugging-port=9222 --user-data-dir=/tmp/agent5/cdcdp about:blank &

# 5. Screenshot each file at both viewports (shot.js, below)
node shot.js
```

`shot.js` drives one Chrome tab over the DevTools protocol using Node 22's built-in `WebSocket`
(no npm packages). For each page it sets the viewport with
`Emulation.setDeviceMetricsOverride { width, height, deviceScaleFactor: 1, mobile: false }`,
navigates to the `file://` URL, waits 1.2s, then calls `Page.captureScreenshot` and writes the
base64 PNG. The two viewports are `1440x900` and `360x800`.

### Why `--window-size=360,800` was not used

The obvious approach — `--headless=new --screenshot=out.png --window-size=360,800` — produces a
360px-wide PNG but does **not** produce a 360px layout. Chrome on macOS clamps the window's CSS
viewport to a 500px minimum; a probe page reporting `window.innerWidth` rendered `500` in both
`--headless=new` and the old `--headless`, and `--force-device-scale-factor` did not change it.
Those captures were therefore 500px layouts cropped to 360px, which would have overstated the
overflow. Only `Emulation.setDeviceMetricsOverride` gives a genuine 360px layout viewport, which
the probe confirmed (`innerWidth` 360). All committed 360px PNGs come from that path.

A second trap worth recording: with `mobile: true` in the override, Chrome applied its Android
"wide viewport" behaviour and expanded the layout viewport to 949px to accommodate the overflowing
nav — hiding the very defect being tested. Setting `mobile: false` keeps the viewport pinned at
360px so the clipping is visible.

## Limitations

- Screenshots are **viewport-sized, not full-page**. `captureBeyondViewport` was left off, so the
  360x800 images show roughly the first screen and a half of content; long lists are truncated at
  the bottom edge. Row 3 onward of the product list, and the pagination control, are below the
  fold in the mobile captures. Pagination is visible in the desktop list captures.
- Because the HTML is screenshotted from `file://`, the pages are static: no JavaScript-driven
  state, no hover or focus styles beyond the default focus ring that appears on the first field in
  `login-360.png`, and any relative link is dead. Only presentation is evidenced here, not
  interaction.
- CSS loaded correctly in every capture — verified visually (typography, colour tokens, badges,
  the below-640px stacked-card layout) and by `GET /assets/app.css` returning 200. No capture
  rendered unstyled.
- Only the Admin role is shown. Sales and WarehouseStaff see a shorter navigation and fewer action
  buttons; those variants are not captured, so the nav measurement above (ten
  links) is the Admin worst case and will be smaller for the other roles.
- The desktop viewport is a single size (1440x900). No intermediate breakpoint (e.g. 720px, where
  `.grid` and the `min-width: 720px` rules switch) was captured.
- `/products/1` was captured as-is; whichever product holds id 1 in the current seed is shown
  ("Keyboard Mekanik K1"). The capture is not pinned to a stable fixture, so a reseed may change
  its contents.
