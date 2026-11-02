#!/usr/bin/env node
/**
 * Review drawer e2e: every storefront, desktop and phone, against a throwaway pool.
 *
 *   node app/code/IXPF/Syncer/Test/e2e/drawer.cjs [product_id]      (from magento/)
 *
 * 1. drawer-fixture.php points a product at pool ZZDRAWER (restored at the end).
 * 2. HTML pass: the AJAX render of every store, parsed without loading a page.
 * 3. Browser pass: the real product page per store x viewport, in parallel.
 *
 * Env: E2E_CONCURRENCY (default 6), E2E_KEEP=1 (leave the fixture up to look at it),
 *      E2E_SHOTS=dir (screenshot every page, not only failures), MAGENTO_PHP ("docker/cli php"),
 *      PLAYWRIGHT_MODULE / CHROMIUM_PATH to point at a specific install.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = path.resolve(__dirname, '../../../../../..');
const FIXTURE = 'app/code/IXPF/Syncer/Test/e2e/drawer-fixture.php';
// The storefront theme differs per install (Evalent/carpet on .com, carpetavenue/rugs on JP).
const I18N = path.join(ROOT, 'app/design/frontend', process.env.E2E_THEME
    || ['Evalent/carpet', 'carpetavenue/rugs'].find(t => fs.existsSync(path.join(ROOT, 'app/design/frontend', t, 'i18n'))), 'i18n');
const PHP = process.env.MAGENTO_PHP || 'docker/cli php';
const CONCURRENCY = Number(process.env.E2E_CONCURRENCY || 6);
const SHOTS = process.env.E2E_SHOTS || path.join(os.tmpdir(), 'ixpf-review-e2e');
const PAGE_TIMEOUT = 120000;
const PREVIEW_MAX = 120;
const EXPANDED_MAX = 450;
const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'phone', width: 390, height: 844, isMobile: true, hasTouch: true },
];

// ---------------------------------------------------------------- results

const failures = [];
const controlLines = { 1: [], 2: [], 3: [] };
let checks = 0;
const check = (scope, name, ok, detail) => {
    checks++;
    if (!ok) failures.push(`${scope}: ${name}${detail === undefined ? '' : ' -- ' + JSON.stringify(detail)}`);
    return ok;
};
const near = (a, b, tolerance = 1) => Math.abs(a - b) <= tolerance;
const failed = scope => failures.filter(f => f.startsWith(`${scope}:`) || f.startsWith(`${scope} `)).length;
/** A collapsed photo: longest side at the cap. */
const isPreview = p => near(Math.max(p.w, p.h), PREVIEW_MAX);
/** An expanded photo: natural size fitted into 450 x min(450, row width), shape kept. */
const isExpanded = (p, rowWidth) => {
    const scale = Math.min(Math.min(EXPANDED_MAX, rowWidth) / p.nw, EXPANDED_MAX / p.nh, 1);
    return near(p.w, p.nw * scale, 1.5) && near(p.h, p.nh * scale, 1.5);
};

// ---------------------------------------------------------------- setup

function playwright() {
    const candidates = [process.env.PLAYWRIGHT_MODULE, 'playwright'];
    // npx keeps its installs here; `npx playwright install chromium` leaves one behind.
    const npx = path.join(os.homedir(), '.npm/_npx');
    if (fs.existsSync(npx)) {
        for (const dir of fs.readdirSync(npx)) candidates.push(path.join(npx, dir, 'node_modules/playwright'));
    }
    for (const candidate of candidates.filter(Boolean)) {
        try { return require(candidate); } catch (e) { /* next */ }
    }
    throw new Error('Playwright not found: run `npx playwright install chromium` or set PLAYWRIGHT_MODULE');
}

async function launch(chromium) {
    try {
        return await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
    } catch (e) {
        // A newer Playwright than its downloaded browser: use the newest Chromium on disk.
        const cache = path.join(os.homedir(), process.platform === 'darwin' ? 'Library/Caches/ms-playwright' : '.cache/ms-playwright');
        const builds = fs.existsSync(cache) ? fs.readdirSync(cache).filter(d => /^chromium-\d+$/.test(d)).sort().reverse() : [];
        for (const build of builds) {
            const exe = [
                'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
                'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
                'chrome-linux/chrome',
            ].map(p => path.join(cache, build, p)).find(p => fs.existsSync(p));
            if (exe) return chromium.launch({ executablePath: exe });
        }
        throw e;
    }
}

const fixture = (args) => execSync(`${PHP} ${FIXTURE} ${args}`, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });

/**
 * The theme CSV for a locale, as a key => translation map. Missing file or key = English.
 */
const translations = {};
function tr(locale, key, arg) {
    if (!translations[locale]) {
        translations[locale] = {};
        const file = path.join(I18N, `${locale}.csv`);
        if (fs.existsSync(file)) {
            for (const [k, v] of parseCsv(fs.readFileSync(file, 'utf8'))) translations[locale][k] = v;
        }
    }
    return (translations[locale][key] || key).replace('%1', arg);
}

/** Same rules as fgetcsv: a quote only opens quoting at the start of a field. */
function parseCsv(text) {
    const rows = [];
    let row = [], field = '', quoted = false, fresh = true;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
            if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
            else if (c === '"') quoted = false;
            else field += c;
        } else if (c === '"' && fresh) { quoted = true; fresh = false; }
        else if (c === ',') { row.push(field); field = ''; fresh = true; }
        else if (c === '\n' || c === '\r') {
            if (field !== '' || row.length) { row.push(field); rows.push(row); }
            row = []; field = ''; fresh = true;
        } else { field += c; fresh = false; }
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows.filter(r => r.length >= 2);
}

/** requested -> en_US -> first sent, as ReviewProvider::resolveLabel(). */
const resolve = (map, locale) => (map ? map[locale] ?? map.en_US ?? Object.values(map)[0] : null) ?? null;

// ---------------------------------------------------------------- shared DOM probes

/** Runs in the page: what each rendered card says. */
function readCards() {
    return [...document.querySelectorAll('.ixpf-review')].map(card => ({
        id: card.dataset.reviewId,
        sku: card.querySelector('.ixpf-review__bought-sku')?.textContent.trim() ?? null,
        bought: card.querySelector('.ixpf-review__bought-variant')?.textContent.trim() ?? null,
        thumb: !!card.querySelector('.ixpf-review__bought-thumb img'),
        replies: [...card.querySelectorAll('.ixpf-review__reply-author-text')].map(e => e.textContent.trim()),
        row: !!card.querySelector('[data-ixpf-review-photos]'),
        photos: [...card.querySelectorAll('[data-ixpf-review-photo]')].map(b => {
            const img = b.querySelector('img');
            return {
                tag: b.tagName, type: b.getAttribute('type'), expanded: b.getAttribute('aria-expanded'),
                src: img.getAttribute('src'), alt: img.getAttribute('alt'), loading: img.getAttribute('loading'),
                width: img.getAttribute('width'), height: img.getAttribute('height'),
            };
        }),
    }));
}

/** Runs in the page: geometry of the open drawer. */
function measure() {
    const box = el => el.getBoundingClientRect();
    const wrap = document.querySelector('.ixpf-reviews-modal .modal-inner-wrap');
    const controls = document.querySelector('.ixpf-reviews-drawer__controls');
    const filters = controls.querySelector('.ixpf-reviews-drawer__filters');
    const selects = controls.querySelector('.ixpf-reviews-drawer__selects');
    const labels = [...filters.querySelectorAll('label')];
    return {
        drawerOverflow: wrap.scrollWidth - wrap.clientWidth,
        controls: {
            width: controls.clientWidth, gap: parseFloat(getComputedStyle(controls).columnGap),
            filtersW: box(filters).width,
            // Natural one-line width, not the laid-out one: a wrapped group has shrunk.
            selectsW: [...selects.children].reduce((w, s) => w + box(s).width, 0)
                + parseFloat(getComputedStyle(selects).columnGap) * (selects.children.length - 1),
            selectsStacked: box(selects.children[1]).top >= box(selects.children[0]).bottom - 1,
            selectsFit: box(selects).width + 0.5 >= [...selects.children].reduce((w, s) => w + box(s).width, 0)
                + parseFloat(getComputedStyle(selects).columnGap) * (selects.children.length - 1),
            sameLine: box(selects).top < box(filters).bottom && box(filters).top < box(selects).bottom,
            selectsLeft: box(selects).left - box(controls).left,
            checkboxes: labels.length,
            pairSameLine: labels.length < 2 || Math.abs(box(labels[0]).top - box(labels[1]).top) < 4,
            pairGap: labels.length < 2 ? null : box(labels[1]).left - box(labels[0]).right,
            clipped: labels.some(l => l.scrollWidth > l.clientWidth + 1),
            overflow: controls.scrollWidth - controls.clientWidth,
        },
        cards: [...document.querySelectorAll('[data-ixpf-reviews-list] .ixpf-review')].map(card => {
            const c = box(card);
            const bought = card.querySelector('.ixpf-review__bought');
            const sku = card.querySelector('.ixpf-review__bought-sku');
            const variant = card.querySelector('.ixpf-review__bought-variant');
            const text = card.querySelector('.ixpf-review__text');
            const row = card.querySelector('[data-ixpf-review-photos]');
            return {
                id: card.dataset.reviewId,
                boughtOverflow: bought ? bought.scrollWidth - bought.clientWidth : 0,
                sameLine: sku && variant ? Math.abs(box(variant).top - box(sku).top) < 4 : null,
                separator: variant ? getComputedStyle(variant, '::before').content : null,
                headings: [...card.querySelectorAll('.ixpf-review__reply-author')]
                    .map(h => ({ overflow: h.scrollWidth - h.clientWidth, right: box(h).right - c.right })),
                row: row && {
                    expanded: row.classList.contains('is-expanded'),
                    top: box(row).top, textBottom: text ? box(text).bottom : null,
                    right: box(row).right - c.right, left: box(row).left, scrollLeft: row.scrollLeft, width: row.clientWidth,
                    maxScroll: row.scrollWidth - row.clientWidth,
                    photos: [...row.querySelectorAll('[data-ixpf-review-photo]')].map(b => {
                        const img = b.querySelector('img');
                        return { left: box(b).left, w: box(img).width, h: box(img).height, nw: img.naturalWidth,
                            nh: img.naturalHeight, expanded: b.getAttribute('aria-expanded') };
                    }),
                },
            };
        }),
    };
}

// ---------------------------------------------------------------- expectations

function expectCards(scope, store, setup, cards) {
    const L = store.locale;
    check(scope, 'all reviews rendered, newest first', JSON.stringify(cards.map(c => c.id)) === JSON.stringify(setup.reviews.map(r => r.id)),
        cards.map(c => c.id));

    setup.reviews.forEach((r, i) => {
        const card = cards.find(c => c.id === r.id);
        const s = `${scope} R${i + 1}`;
        if (!check(s, 'rendered', !!card)) return;

        check(s, 'bought line', card.bought === resolve(r.bought, L), { got: card.bought, want: resolve(r.bought, L) });
        check(s, 'Art. No translated', card.sku === tr(L, 'Art. No: %1', `ZZ-R${i + 1}`), card.sku);
        check(s, 'bought thumbnail only where sent', card.thumb === (i === 0), card.thumb);
        const heading = tr(L, 'Reply from %1', store.replyName);
        check(s, 'reply heading', JSON.stringify(card.replies) === JSON.stringify(r.reply ? [heading] : []), { got: card.replies, want: heading });

        check(s, 'photo count', card.photos.length === r.photos.length, card.photos.length);
        check(s, 'photo row only with photos', card.row === r.photos.length > 0);
        card.photos.forEach((p, j) => {
            const dims = r.photos[j];
            check(`${s} photo ${j + 1}`, 'is a collapsed button', p.tag === 'BUTTON' && p.type === 'button' && p.expanded === 'false', p);
            check(`${s} photo ${j + 1}`, '800px derivative', /\/ixpf\/reviews\/.+\/800\//.test(p.src), p.src);
            check(`${s} photo ${j + 1}`, 'alt translated', p.alt === tr(L, 'Photo by %1', r.reviewer), p.alt);
            check(`${s} photo ${j + 1}`, 'lazy', p.loading === 'lazy');
            check(`${s} photo ${j + 1}`, 'width/height only when sent',
                dims ? p.width === String(dims[0]) && p.height === String(dims[1]) : p.width === null && p.height === null, p);
        });
    });
}

// ---------------------------------------------------------------- passes

async function htmlPass(browser, setup) {
    const page = await browser.newPage();
    await page.route('**/*', r => r.abort());
    await Promise.all(setup.stores.map(async store => {
        const res = await fetch(`${store.base}ixpfsyncer/reviews/load?product_id=${setup.productId}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: AbortSignal.timeout(30000) });
        store.json = res.ok ? await res.json() : null;
        store.status = res.status;
    }));
    for (const store of setup.stores) {
        const scope = `html ${store.code}`;
        if (!check(scope, 'load endpoint 200', store.status === 200 && !!store.json, store.status)) continue;
        check(scope, 'one page', store.json.page === 1 && store.json.page_count === 1, [store.json.page, store.json.page_count]);
        check(scope, 'no staff name', !store.json.items_html.includes(setup.staffName));
        await page.setContent(store.json.items_html);
        expectCards(scope, store, setup, await page.evaluate(readCards));
    }
    await page.close();

    // Filters server-side: with_text only counts where the setting offers it.
    const ids = pred => setup.reviews.filter(pred).map(r => r.id);
    await Promise.all(setup.stores.map(async store => {
        const scope = `html ${store.code}`;
        const cases = {
            'has_photos=1': ids(r => r.photos.length),
            'with_text=1': store.textFilter ? ids(r => r.hasBody) : ids(() => true),
            'has_photos=1&with_text=1': ids(r => r.photos.length && (!store.textFilter || r.hasBody)),
        };
        for (const [query, want] of Object.entries(cases)) {
            const res = await fetch(`${store.base}ixpfsyncer/reviews/load?product_id=${setup.productId}&${query}`,
                { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: AbortSignal.timeout(30000) });
            const got = res.ok ? [...(await res.json()).items_html.matchAll(/data-review-id="([^"]+)"/g)].map(m => m[1]) : res.status;
            check(scope, `filter ${query}${store.textFilter ? '' : ' (text filter off: ignored)'}`, JSON.stringify(got) === JSON.stringify(want), { got, want });
        }
    }));

    const base = setup.stores[0].base.replace(/\/$/, '');
    for (const photo of setup.photos) {
        const res = await fetch(`${base}/media/${photo}`, { method: 'HEAD', signal: AbortSignal.timeout(30000) });
        check('html media', `${photo} served`, res.status === 200, res.status);
    }
}

async function browserPass(browser, setup, store, viewport) {
    const scope = `${store.code} ${viewport.name}`;
    const context = await browser.newContext({ viewport, isMobile: !!viewport.isMobile, hasTouch: !!viewport.hasTouch, ignoreHTTPSErrors: true });
    context.setDefaultTimeout(20000);
    context.setDefaultNavigationTimeout(60000);
    // page.evaluate has no timeout: a stuck page gets its context closed instead of hanging the run.
    let hung = false;
    const watchdog = setTimeout(() => { hung = true; context.close().catch(() => {}); }, PAGE_TIMEOUT);
    // Only what is under test: other hosts, catalog images and the consent script are noise.
    // Anything off the storefront's own host (not a fixed domain: .com and JP use different ones).
    const ownHost = new URL(store.base).host;
    await context.route(url => url.host !== ownHost, r => r.abort());
    await context.route(/\/media\/catalog\/|cookie-?script/i, r => r.abort());
    const page = await context.newPage();
    // Ours only: third-party scripts are blocked above, so the page logs their fallout too.
    const errors = [];
    const ours = /reviews-drawer|ixpf|IXPF_Syncer/i;
    page.on('pageerror', e => ours.test(e.stack || e.message) && errors.push(e.message));
    page.on('console', m => m.type() === 'error' && ours.test(m.text()) && !/Failed to load resource/.test(m.text()) && errors.push(m.text()));
    page.on('response', r => /\/media\/ixpf\//.test(r.url()) && r.status() >= 400 && errors.push(`${r.status()} ${r.url()}`));

    try {
        await page.goto(`${store.base}catalog/product/view/id/${setup.productId}`, { waitUntil: 'load', timeout: 90000 });
        // The trigger is bound once RequireJS has run; click until the list arrives.
        const trigger = page.locator('[data-ixpf-reviews-open]:visible').first();
        const list = page.locator('[data-ixpf-reviews-list] .ixpf-review');
        for (let attempt = 0; attempt < 6 && !(await list.count()); attempt++) {
            await trigger.click();
            await list.first().waitFor({ timeout: 3000 }).catch(() => {});
        }
        if (!check(scope, 'drawer opens with reviews', (await list.count()) === setup.reviews.length, await list.count())) return;

        expectCards(scope, store, setup, await page.evaluate(readCards));

        // Lazy images load as they scroll in; bring each into view and wait for it.
        await page.evaluate(async () => {
            for (const img of document.querySelectorAll('[data-ixpf-reviews-list] .ixpf-review__photo img')) {
                img.scrollIntoView({ block: 'center' });
                if (!img.complete || !img.naturalWidth) {
                    await new Promise(r => { img.addEventListener('load', r, { once: true }); img.addEventListener('error', r, { once: true }); setTimeout(r, 5000); });
                }
            }
        });

        const m = await page.evaluate(measure);
        check(scope, 'drawer never scrolls sideways', m.drawerOverflow <= 0, m.drawerOverflow);

        // Filters: the checkbox pair stays together; the selects share its line only when they fit.
        const c = m.controls;
        const fits = c.filtersW + c.gap + c.selectsW <= c.width + 0.5;
        check(scope, `"Text only" ${store.textFilter ? 'shown' : 'hidden'} per setting`, c.checkboxes === (store.textFilter ? 2 : 1), c.checkboxes);
        if (c.checkboxes === 2) check(scope, 'image and text filters side by side', c.pairSameLine && near(c.pairGap, 16, 2), c);
        check(scope, 'one line when it fits, two when not', c.sameLine === fits, c);
        if (!c.sameLine) check(scope, 'wrapped selects start at the left edge', near(c.selectsLeft, 0), c.selectsLeft);
        check(scope, 'selects side by side when they fit, stacked when not', c.selectsStacked === !c.selectsFit, c);
        check(scope, 'filter labels never clipped', !c.clipped);
        check(scope, 'controls never overflow', c.overflow <= 0, c.overflow);
        controlLines[c.sameLine ? 1 : c.selectsStacked ? 3 : 2].push(`${store.code}/${viewport.name}${store.textFilter ? '+text' : ''}`);
        const [r1] = m.cards;
        if (viewport.name === 'desktop') {
            check(scope, 'bought line inline after Art. No, with separator', r1.sameLine === true && r1.separator === '"|"', r1);
        } else {
            check(scope, 'bought line on its own row, no separator', r1.sameLine === false && /none|normal/.test(r1.separator), r1);
        }
        for (const card of m.cards) {
            const s = `${scope} ${card.id.slice(-2)}`;
            check(s, 'bought row fits the card', card.boughtOverflow <= 0, card.boughtOverflow);
            card.headings.forEach(h => check(s, 'reply heading wraps, not clipped', h.overflow <= 0 && h.right <= 1, h));
            if (!card.row) continue;
            check(s, 'photos under the text', card.row.textBottom === null || card.row.top >= card.row.textBottom - 1, card.row);
            check(s, 'photo row inside the card', card.row.right <= 1, card.row.right);
            card.row.photos.forEach((p, j) => {
                check(`${s} photo ${j + 1}`, 'loaded', p.nw > 0, p);
                check(`${s} photo ${j + 1}`, `preview longest side ${PREVIEW_MAX}px`, isPreview(p), [p.w, p.h]);
                check(`${s} photo ${j + 1}`, 'keeps aspect ratio', p.nw > 0 && near(p.w / p.h, p.nw / p.nh, 0.03), p);
            });
        }

        // Click the second photo of R1: the whole row grows, the clicked photo stays in view.
        const r1Photos = page.locator(`[data-review-id="${setup.reviews[0].id}"] [data-ixpf-review-photo]`);
        await r1Photos.nth(1).click();
        let now = await page.evaluate(measure);
        let row = now.cards[0].row;
        check(scope, 'click expands the whole row', row.expanded && row.photos.every(p => p.expanded === 'true'), row.photos);
        check(scope, `expanded: max ${EXPANDED_MAX}px, never wider than the card`, row.photos.every(p => isExpanded(p, row.width)),
            { row: row.width, photos: row.photos.map(p => [Math.round(p.w), Math.round(p.h)]) });
        check(scope, 'expanded photos keep aspect ratio', row.photos.every(p => near(p.w / p.h, p.nw / p.nh, 0.03)));
        check(scope, 'clicked photo scrolled into view',
            near(row.photos[1].left, row.left) || near(row.scrollLeft, row.maxScroll), row);
        check(scope, 'other reviews untouched', now.cards[1].row.photos.every(p => isPreview(p) && p.expanded === 'false'));
        check(scope, 'row scrolls, drawer does not', now.drawerOverflow <= 0, now.drawerOverflow);

        await r1Photos.nth(0).click();
        row = (await page.evaluate(measure)).cards[0].row;
        check(scope, 'second click collapses and rewinds', !row.expanded && row.scrollLeft === 0
            && row.photos.every(p => isPreview(p) && p.expanded === 'false'), row);

        // Keyboard: a native button, so Enter and Space both toggle.
        const r2Photo = page.locator(`[data-review-id="${setup.reviews[1].id}"] [data-ixpf-review-photo]`).first();
        await r2Photo.focus();
        await page.keyboard.press('Enter');
        check(scope, 'Enter expands', (await r2Photo.getAttribute('aria-expanded')) === 'true');
        await page.keyboard.press('Space');
        check(scope, 'Space collapses', (await r2Photo.getAttribute('aria-expanded')) === 'false');

        // A filter replaces the list: nothing stays expanded, the text-only review drops out.
        await r1Photos.nth(0).click();
        await page.locator('[data-ixpf-reviews-photos]').check({ force: true });
        await page.waitForFunction(n => document.querySelectorAll('[data-ixpf-reviews-list] .ixpf-review').length === n,
            setup.reviews.filter(r => r.photos.length).length, { timeout: 15000 }).catch(() => {});
        const filtered = await page.evaluate(() => ({
            count: document.querySelectorAll('[data-ixpf-reviews-list] .ixpf-review').length,
            expanded: document.querySelectorAll('[data-ixpf-review-photos].is-expanded').length,
        }));
        check(scope, '"Images only" keeps photo reviews, collapsed',
            filtered.count === setup.reviews.filter(r => r.photos.length).length && filtered.expanded === 0, filtered);

        // Both filters together, then text alone - only where the setting offers the checkbox.
        if (store.textFilter) {
            const listIds = () => page.$$eval('[data-ixpf-reviews-list] .ixpf-review', els => els.map(e => e.dataset.reviewId));
            const expectList = async (name, want) => {
                await page.waitForFunction(w => JSON.stringify([...document.querySelectorAll('[data-ixpf-reviews-list] .ixpf-review')]
                    .map(e => e.dataset.reviewId)) === w, JSON.stringify(want), { timeout: 15000 }).catch(() => {});
                const got = await listIds();
                check(scope, name, JSON.stringify(got) === JSON.stringify(want), { got, want });
            };
            await page.locator('[data-ixpf-reviews-withtext]').check({ force: true });
            await expectList('"Images only" + "Text only" together', setup.reviews.filter(r => r.photos.length && r.hasBody).map(r => r.id));
            await page.locator('[data-ixpf-reviews-photos]').uncheck({ force: true });
            await expectList('"Text only" alone', setup.reviews.filter(r => r.hasBody).map(r => r.id));
            await page.locator('[data-ixpf-reviews-withtext]').uncheck({ force: true });
            await expectList('both off: everything back', setup.reviews.map(r => r.id));
        }

        check(scope, 'no drawer JS errors or broken review media', errors.length === 0, errors);
    } catch (e) {
        check(scope, hung ? `finished within ${PAGE_TIMEOUT / 1000}s` : 'ran to completion', false, e.message.split('\n')[0]);
    } finally {
        clearTimeout(watchdog);
        if (!hung && (process.env.E2E_SHOTS || failed(scope))) {
            fs.mkdirSync(SHOTS, { recursive: true });
            await page.locator('.ixpf-reviews-modal .modal-inner-wrap').screenshot({ path: path.join(SHOTS, `${store.code}-${viewport.name}.png`) })
                .catch(() => page.screenshot({ path: path.join(SHOTS, `${store.code}-${viewport.name}.png`) }).catch(() => {}));
        }
        await context.close().catch(() => {});
    }
}

async function pool(tasks, size) {
    const queue = [...tasks];
    await Promise.all(Array.from({ length: size }, async () => {
        while (queue.length) await queue.shift()();
    }));
}

// ---------------------------------------------------------------- main

// Ctrl+C still restores the product.
let fixtureUp = false;
process.on('SIGINT', () => {
    if (fixtureUp && !process.env.E2E_KEEP) fixture('down');
    process.exit(130);
});

(async () => {
    const started = Date.now();
    const setup = JSON.parse(fixture(`up ${process.argv[2] || ''}`));
    fixtureUp = true;
    const browser = await launch(playwright().chromium);
    console.log(`drawer e2e: product ${setup.productId}, ${setup.stores.length} storefronts x ${VIEWPORTS.length} viewports, ${CONCURRENCY} at a time`);

    try {
        let t = Date.now();
        await htmlPass(browser, setup);
        console.log(`  html pass     ${setup.stores.length} stores  ${((Date.now() - t) / 1000).toFixed(1)}s`);

        t = Date.now();
        const tasks = setup.stores.flatMap(store => VIEWPORTS.map(vp => async () => {
            const s = Date.now();
            await browserPass(browser, setup, store, vp);
            console.log(`  ${(failed(`${store.code} ${vp.name}`) ? 'FAIL' : 'ok  ')} ${store.code.padEnd(6)} ${store.locale} ${vp.name.padEnd(7)} ${((Date.now() - s) / 1000).toFixed(1)}s`);
        }));
        await pool(tasks, CONCURRENCY);
        console.log(`  browser pass  ${tasks.length} pages  ${((Date.now() - t) / 1000).toFixed(1)}s`);
        console.log(`  filters on 1 line: ${controlLines[1].sort().join(' ') || '-'}`);
        console.log(`  filters on 2 lines: ${controlLines[2].sort().join(' ') || '-'}`);
        console.log(`  filters on 3 lines: ${controlLines[3].sort().join(' ') || '-'}`);
    } finally {
        await browser.close();
        if (process.env.E2E_KEEP) {
            console.log(`fixture left up (E2E_KEEP): ${setup.stores[0].base}catalog/product/view/id/${setup.productId} - clean with: ${PHP} ${FIXTURE} down`);
        } else {
            fixture('down');
        }
    }

    failures.forEach(f => console.log(`  FAIL ${f}`));
    if (failures.length) console.log(`screenshots: ${SHOTS}`);
    console.log(`\n${checks - failures.length} passed, ${failures.length} failed in ${((Date.now() - started) / 1000).toFixed(1)}s`);
    process.exit(failures.length ? 1 : 0);
})().catch(e => {
    console.error(e);
    try { if (!process.env.E2E_KEEP) fixture('down'); } catch (_) { /* already reported */ }
    process.exit(2);
});
