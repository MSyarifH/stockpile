/**
 * Captures the UI-01 evidence screenshots in docs/testing/screenshots/.
 *
 * Committed so the evidence is reproducible rather than a set of PNGs nobody
 * can regenerate. Node 22+ only: it uses the built-in WebSocket client, so
 * there are no npm packages, no package.json, and nothing for §4 to object to.
 * It is a documentation tool, not part of the application.
 *
 * Why it drives Chrome over the DevTools protocol instead of using
 * --screenshot --window-size: Chrome on macOS clamps a window's CSS viewport to
 * a 500px minimum, so --window-size=360 yields a 360px-wide PNG of a 500px
 * layout -- a picture that understates the overflow being tested. Only
 * Emulation.setDeviceMetricsOverride gives a genuine 360px layout viewport.
 * `mobile` must stay false: with it true, Chrome applies its Android
 * wide-viewport behaviour and expands the layout to fit overflowing content,
 * hiding the very defect the 360px capture exists to catch.
 *
 * Usage (see docs/testing/screenshots.md for the full procedure):
 *   node docs/testing/capture-screenshots.js <dir-of-saved-html> <out-dir>
 */
const fs = require('fs');
const path = require('path');

// A page is a saved HTML file plus an optional `before` expression evaluated in
// the page before the shot. The `before` hook exists for one capture only: the
// client-side validation messages do not exist in the served HTML -- they are
// produced by form-validate.js when a submit is attempted -- so the only way to
// evidence them is to attempt one.
const PAGES = [
    { file: 'login' },
    { file: 'dashboard' },
    { file: 'products' },
    { file: 'products-empty' },
    { file: 'product-detail' },
    { file: 'sales-orders' },
    { file: 'products-create' },
    {
        file: 'products-create',
        as: 'products-create-invalid',
        before: `document.querySelector('form.card--form')
                     .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))`,
    },
];
const VIEWPORTS = [
    { suffix: 'desktop', width: 1440, height: 900 },
    { suffix: '360', width: 360, height: 800 },
];

const [, , htmlDir, outDir] = process.argv;
if (!htmlDir || !outDir) {
    console.error('usage: node capture-screenshots.js <html-dir> <out-dir>');
    process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
    const list = await (await fetch('http://127.0.0.1:9222/json/list')).json();
    const target = list.find((t) => t.type === 'page');
    if (!target) throw new Error('No Chrome page target on port 9222. Is Chrome running with --remote-debugging-port=9222?');

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
    });

    let id = 0;
    const pending = new Map();
    ws.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        const resolver = pending.get(message.id);
        if (resolver) {
            pending.delete(message.id);
            resolver(message.result);
        }
    });
    const send = (method, params = {}) => new Promise((resolve) => {
        const messageId = ++id;
        pending.set(messageId, resolve);
        ws.send(JSON.stringify({ id: messageId, method, params }));
    });

    for (const page of PAGES) {
        for (const view of VIEWPORTS) {
            await send('Emulation.setDeviceMetricsOverride', {
                width: view.width, height: view.height,
                deviceScaleFactor: 1, mobile: false,
            });
            const url = 'file://' + path.resolve(htmlDir, page.file + '.html');
            await send('Page.navigate', { url });
            // Fixed wait rather than a load event: the pages are static files
            // whose only remote resource is the stylesheet, and the capture is
            // presentation evidence, not a timing measurement.
            await sleep(1200);

            const probe = await send('Runtime.evaluate', {
                expression: 'window.innerWidth', returnByValue: true,
            });
            const innerWidth = probe && probe.result ? probe.result.value : '?';
            if (innerWidth !== view.width) {
                throw new Error(
                    `Viewport is ${innerWidth}, expected ${view.width}. The capture would ` +
                    'misrepresent the layout; aborting rather than writing a misleading PNG.',
                );
            }

            if (page.before) {
                await send('Runtime.evaluate', { expression: page.before });
                await sleep(300);
            }

            const shot = await send('Page.captureScreenshot', { format: 'png' });
            const file = path.join(outDir, `${page.as || page.file}-${view.suffix}.png`);
            fs.writeFileSync(file, Buffer.from(shot.data, 'base64'));
            console.log(`${file}  (layout viewport ${innerWidth}px)`);
        }
    }
    ws.close();
}

main().catch((error) => { console.error(error.message); process.exit(1); });
