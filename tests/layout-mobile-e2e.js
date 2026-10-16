// Browser test for the per-device layout (v1.27.0). Needs the Local test WP
// (http://test-environment.local) with the dev-autologin mu-plugin, a page
// /layout-mobile-test/ holding [vetspire_scheduler primary="1"],
// [vetspire_scheduler layout="float" layout_mobile="bar"], [vetspire_scheduler layout="full"]
// and <a id="booklink" href="#vsps-book">, plus puppeteer-core. Restores the Settings it changes.
//   VSPS_AUTOLOGIN=<secret> node tests/layout-mobile-e2e.js
// v1.27.0: per-device layout. Settings "On phones" + layout_mobile attribute,
// layout picked by viewport, live switch on resize, switch deferred under an
// open popup, analytics report the shown layout, lightbox stays "full".
const puppeteer = require('puppeteer-core');
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const BASE = 'http://test-environment.local';
const SECRET = process.env.VSPS_AUTOLOGIN || ''; // the dev-autologin mu-plugin secret
const PAGE = `${BASE}/layout-mobile-test/`;
const DESKTOP = { width: 1280, height: 900 };
const PHONE = { width: 390, height: 844, isMobile: false };

let fails = 0;
const check = (name, ok, extra) => {
  if (!ok) fails++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra !== undefined ? '  — ' + JSON.stringify(extra) : ''}`);
};

const layouts = (page) => page.$$eval('.vsps-widget[data-vsps-config]:not(.vsps-embedded)', (els) =>
  els.map((e) => (e.className.match(/vsps-layout-(\w+)/) || [])[1] || '?'));
const views = (page) => page.evaluate(() => (window.dataLayer || []).filter((e) => e.event === 'vsps_widget_view').map((e) => e.vsps_layout));

async function settings(page, layout, mobile) {
  await page.setViewport(DESKTOP);
  await page.goto(`${BASE}/wp-admin/?vsps_autologin=${SECRET}`, { waitUntil: 'networkidle2', timeout: 90000 });
  await page.goto(`${BASE}/wp-admin/admin.php?page=vsps-settings`, { waitUntil: 'networkidle2', timeout: 90000 });
  const before = await page.evaluate(() => ({
    layout: (document.querySelector('.vsps-layout-radio:checked') || {}).value,
    mobile: (document.getElementById('vsps_layout_mobile') || {}).value,
    hasSelect: !!document.getElementById('vsps_layout_mobile'),
  }));
  await page.evaluate((l) => { const r = document.querySelector(`.vsps-layout-radio[value="${l}"]`); r.click(); }, layout);
  await page.select('#vsps_layout_mobile', mobile);
  await wait(500);
  const chip = await page.$eval('#vsps-preview-chip', (c) => c.textContent);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), page.click('#submit, input[type=submit][name=submit]')]);
  const after = await page.evaluate(() => ({
    layout: (document.querySelector('.vsps-layout-radio:checked') || {}).value,
    mobile: (document.getElementById('vsps_layout_mobile') || {}).value,
  }));
  return { before, after, chip };
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new' });
  const page = await browser.newPage();
  page.on('pageerror', (e) => { fails++; console.log('PAGE ERROR', e.message); });

  // 1. Settings screen
  const s = await settings(page, 'float', 'bar');
  check('settings: "On phones" select exists', s.before.hasSelect);
  check('settings: preview chip names the phone layout', /Floating card \(phones: Bar\)/.test(s.chip), s.chip);
  check('settings: saved float + bar', s.after.layout === 'float' && s.after.mobile === 'bar', s.after);

  // 2. Desktop load
  await page.setViewport(DESKTOP);
  await page.goto(PAGE, { waitUntil: 'networkidle2', timeout: 90000 }); await wait(3000);
  let l = await layouts(page);
  check('desktop: A float (settings), B float (attr), C full (own layout)', JSON.stringify(l) === '["float","float","full"]', l);
  check('desktop: widget_view reports float, float, full', JSON.stringify(await views(page)) === '["float","float","full"]', await views(page));

  // 3. Live resize to phone
  await page.setViewport(PHONE); await wait(3000);
  l = await layouts(page);
  check('resize to phone: A bar, B bar, C stays full', JSON.stringify(l) === '["bar","bar","full"]', l);
  check('resize: no extra widget_view events', (await views(page)).length === 3, await views(page));
  check('resize: bar actually rendered (bar strip present in A and B)', await page.$$eval('.vsps-layout-bar', (els) => els.filter((e) => e.querySelector('.vsps-bar')).length) === 2);

  // 4. Back to desktop
  await page.setViewport(DESKTOP); await wait(3000);
  l = await layouts(page);
  check('resize back to desktop: float, float, full', JSON.stringify(l) === '["float","float","full"]', l);

  // 5. Phone load from scratch
  await page.setViewport(PHONE);
  await page.goto(PAGE, { waitUntil: 'networkidle2', timeout: 90000 }); await wait(3000);
  l = await layouts(page);
  check('phone load: bar, bar, full', JSON.stringify(l) === '["bar","bar","full"]', l);
  check('phone load: widget_view reports bar, bar, full', JSON.stringify(await views(page)) === '["bar","bar","full"]', await views(page));

  // 6. #vsps-book still opens widget A (primary) after switches; lightbox inner widget is "full" on a phone
  await page.click('#booklink'); await wait(3500);
  const inner = await page.evaluate(() => {
    const o = document.querySelector('.vsps-overlay .vsps-widget.vsps-embedded');
    return o ? (o.className.match(/vsps-layout-(\w+)/) || [])[1] : null;
  });
  check('phone: #vsps-book lightbox opens with the full picker inside', inner === 'full', inner);

  // 7. Resize while the popup is open: wait, then switch on close
  await page.setViewport(DESKTOP); await wait(2000);
  l = await layouts(page);
  check('popup open + resize to desktop: layouts held (still bar, bar, full)', JSON.stringify(l) === '["bar","bar","full"]', l);
  check('popup still open after the resize', !!(await page.$('.vsps-overlay')));
  await page.keyboard.press('Escape'); await wait(2500);
  l = await layouts(page);
  check('popup closed: pending switch ran (float, float, full)', JSON.stringify(l) === '["float","float","full"]', l);

  // 8. #vsps-book after a switch opens A (primary) -- order kept in WIDGETS
  const opener = await page.evaluate(() => {
    document.getElementById('booklink').click();
    return true;
  }); await wait(3000);
  check('after switch: #vsps-book still opens a lightbox', opener && !!(await page.$('.vsps-overlay .vsps-embedded')));
  await page.keyboard.press('Escape'); await wait(1000);

  // 8b. Keyboard focus inside a widget: no rebuild under it; switch once focus leaves
  await page.setViewport(DESKTOP); await wait(2500);
  await page.evaluate(() => {
    const a = document.querySelectorAll('.vsps-widget[data-vsps-config]:not(.vsps-embedded)')[0];
    const btn = a.querySelector('button'); if (btn) btn.focus();
  });
  const focusedBefore = await page.evaluate(() => !!document.activeElement.closest('.vsps-widget'));
  await page.setViewport(PHONE); await wait(2500);
  l = await layouts(page);
  const focusKept = await page.evaluate(() => !!document.activeElement.closest('.vsps-widget') && document.body.contains(document.activeElement));
  check('focus inside A + resize to phone: A held (float), B switched (bar)', focusedBefore && JSON.stringify(l) === '["float","bar","full"]', l);
  check('focus inside A: focus not lost to <body>', focusKept);
  await page.evaluate(() => document.getElementById('booklink').focus()); await wait(2500);
  l = await layouts(page);
  check('focus leaves A: A switches to bar', JSON.stringify(l) === '["bar","bar","full"]', l);

  // 9. Events after a switch report the shown layout: pick a time in widget B (bar) on a phone
  await page.setViewport(PHONE); await wait(3000);
  const picked = await page.evaluate(() => {
    const roots = document.querySelectorAll('.vsps-widget[data-vsps-config]:not(.vsps-embedded)');
    const b = roots[1];
    const slot = b.querySelector('.vsps-bar-chips button');
    if (!slot) return 'no-slot';
    slot.click();
    return slot.className;
  });
  await wait(2500);
  const ev = await page.evaluate(() => (window.dataLayer || []).filter((e) => e.event === 'vsps_slot_selected').map((e) => e.vsps_layout));
  check('phone: a time picked in the bar reports layout "bar"', ev.length > 0 && ev[ev.length - 1] === 'bar', { picked, ev });
  await page.keyboard.press('Escape'); await wait(800);
  await page.keyboard.press('Escape'); await wait(800);

  // 10. "Same as above" -> old behaviour (no switching)
  await settings(page, 'float', '');
  await page.setViewport(PHONE);
  await page.goto(PAGE, { waitUntil: 'networkidle2', timeout: 90000 }); await wait(3000);
  l = await layouts(page);
  check('"Same as above": phone shows A float (unchanged sites), B bar (attr), C full', JSON.stringify(l) === '["float","bar","full"]', l);

  // restore the site's original settings
  await settings(page, s.before.layout || 'full', s.before.mobile || '');
  console.log(`\nrestored settings: layout=${s.before.layout} mobile="${s.before.mobile || ''}"`);
  await browser.close();
  console.log(fails ? `\n${fails} FAILED` : '\nALL PASS');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
