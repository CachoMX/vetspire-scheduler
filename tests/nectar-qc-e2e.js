// Browser checks for the Nectar QC fixes against tests/demo-server.php, every API faked:
//  - quick-time confirm popup uses Nicole's wording and the type name as written;
//  - while a (slow) booking sends: no jump to the top, focus stays on the button,
//    the form says it is sending, a second click sends nothing;
//  - a Cloudflare-style HTML 502 on lookup shows a readable message.
// Run from the plugin root:
//   php -c <ini> -S 127.0.0.1:8737 tests/demo-server.php   (in another shell)
//   NODE_PATH=<dir with puppeteer-core> node tests/nectar-qc-e2e.js
// OLDJS=<path to an older scheduler.js> runs the same checks against it.
const puppeteer = require('puppeteer-core')
const BASE = 'http://127.0.0.1:8737'
let pass = 0, fail = 0
const check = (label, ok, detail) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + label + (ok || detail === undefined ? '' : '  -- ' + JSON.stringify(detail))) }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

function iso(d) { return d.toISOString().slice(0, 10) }
const days = []
for (let i = 0; i < 7; i++) {
  const d = new Date(Date.now() + (i + 2) * 86400000)
  days.push({ date: iso(d), slots: [{ time: '09:00', providerId: '1', scheduleId: null, provider: { id: '1', name: 'Dr. Test' } }, { time: '10:00', providerId: '1', scheduleId: null, provider: { id: '1', name: 'Dr. Test' } }] })
}
const TYPES = { types: [{ id: '11', name: 'Wellness Exam - Established Clients', duration: 20, canBookOnline: true }, { id: '12', name: 'Follow Up', duration: 20, canBookOnline: true }] }

;(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new' })
  const p = await b.newPage()
  await p.setViewport({ width: 390, height: 600, isMobile: true, hasTouch: true })
  let bookCalls = 0
  await p.setRequestInterception(true)
  p.on('request', async (req) => {
    const u = req.url()
    const json = (body, status = 200) => req.respond({ status, contentType: 'application/json', body: JSON.stringify(body) })
    if (u.includes('/wp-json/vetspire/v1/types')) return json(TYPES)
    if (u.includes('/wp-json/vetspire/v1/availability')) return json({ days })
    if (u.includes('/wp-json/vetspire/v1/location-info')) return json({ name: 'Test Clinic', address: '', phone: '', hours: [] })
    if (u.includes('/wp-json/vetspire/v1/events')) return json({ ok: true })
    if (u.includes('/wp-json/vetspire/v1/lookup')) return req.respond({ status: 502, contentType: 'text/html', body: '<!DOCTYPE html><html><body>error code: 502</body></html>' })
    if (u.includes('/wp-json/vetspire/v1/book')) {
      bookCalls++
      await sleep(4000)
      return json({ appointment_id: '1', start: '', request: true })
    }
    if (process.env.OLDJS && u.includes('/assets/js/scheduler.js')) return req.respond({ status: 200, contentType: 'application/javascript', body: require('fs').readFileSync(process.env.OLDJS, 'utf8') })
    return req.continue()
  })

  // ---- 1. quick time on the bar → confirm popup wording ----
  await p.goto(BASE + '/?layout=bar', { waitUntil: 'networkidle0' })
  await p.waitForSelector('.vsps-widget button', { timeout: 15000 })
  await sleep(1200)
  const chip = await p.evaluateHandle(() => [...document.querySelectorAll('.vsps-widget button')].find((x) => /\d{1,2}:\d{2}/.test(x.textContent) && x.getBoundingClientRect().height > 0))
  if (await chip.evaluate((x) => !!x)) {
    await chip.click()
    await sleep(600)
    const text = await p.evaluate(() => { const q = document.querySelector('.vsps-overlay .vsps-step-q'); return q ? q.textContent : '' })
    check('confirm text: Nicole\'s wording with the type name as written', text === 'The time you have selected is for the following appointment type: Wellness Exam - Established Clients. Please confirm this choice or select another:', text)
    const yes = await p.evaluate(() => { const y = document.querySelector('.vsps-overlay .vsps-btn-primary'); return y ? y.textContent : '' })
    check('confirm button reads "Yes, book <type>"', yes === 'Yes, book Wellness Exam - Established Clients', yes)
    await p.evaluate(() => document.querySelector('.vsps-overlay .vsps-btn-primary').click())
    await sleep(600)
  } else {
    check('bar shows a quick time', false)
  }

  // ---- 2. new-client form: slow booking ----
  const choice = await p.evaluateHandle(() => [...document.querySelectorAll('.vsps-overlay button')].find((x) => /new client/i.test(x.textContent)))
  if (await choice.evaluate((x) => !!x)) { await choice.click(); await sleep(500) }
  const filled = await p.evaluate(() => {
    const form = document.querySelector('.vsps-overlay form.vsps-form')
    if (!form) return 'no form'
    const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })) }
    form.querySelectorAll('input, select, textarea').forEach((el) => {
      if (el.name === 'vsps_hp' || el.type === 'hidden') return
      if (el.tagName === 'SELECT') { const o = [...el.options].find((o) => o.value); if (o) set(el, o.value); return }
      const n = el.name || ''
      if (el.type === 'email' || /email/.test(n)) return set(el, 'zz-test@example.com')
      if (el.type === 'tel' || /phone/.test(n)) return set(el, '5550100100')
      if (el.type === 'number' || /^age/.test(n)) return set(el, '2')
      if (el.type === 'date') return set(el, '')
      if (/postal|zip/.test(n)) return set(el, '37923')
      if (/state/.test(n)) return set(el, 'TN')
      set(el, 'Test')
    })
    return 'ok'
  })
  check('new-client form filled', filled === 'ok', filled)
  // Scroll the dialog to its bottom, focus Confirm the way a click does.
  await p.evaluate(() => { const sc = () => [document.querySelector('.vsps-overlay'), ...document.querySelectorAll('.vsps-overlay *')].find((e) => e.scrollHeight > e.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(e).overflowY)); const m = sc(); if (m) m.scrollTop = m.scrollHeight })
  await sleep(300)
  const before = await p.evaluate(() => { const sc = () => [document.querySelector('.vsps-overlay'), ...document.querySelectorAll('.vsps-overlay *')].find((e) => e.scrollHeight > e.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(e).overflowY)); const m = sc(); return m ? m.scrollTop : -1 })
  const btn = await p.$('.vsps-overlay form.vsps-form .vsps-btn-primary')
  await btn.click()
  await sleep(1200)
  const mid = await p.evaluate(() => {
    const sc = () => [document.querySelector('.vsps-overlay'), ...document.querySelectorAll('.vsps-overlay *')].find((e) => e.scrollHeight > e.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(e).overflowY))
    const m = sc()
    const f = document.querySelector('.vsps-overlay form.vsps-form')
    const b = f && f.querySelector('.vsps-btn-primary')
    const note = f && f.querySelector('.vsps-sending')
    return {
      scrollTop: m ? m.scrollTop : -1,
      focusOnButton: !!b && document.activeElement === b,
      ariaBusy: f ? f.getAttribute('aria-busy') : null,
      ariaDisabled: b ? b.getAttribute('aria-disabled') : null,
      label: b ? b.textContent : '',
      fieldsReadOnly: !!f && [...f.querySelectorAll('input:not([type=hidden]):not([name=vsps_hp]), textarea')].every((x) => x.readOnly),
      cancelClickable: !!f && (() => { const c = f.querySelector('.vsps-btn-secondary'); return !!c && getComputedStyle(c).pointerEvents !== 'none' })(),
      note: note ? note.textContent : '',
    }
  })
  check('no jump to the top while sending', before > 50 && Math.abs(mid.scrollTop - before) < 5, { before, after: mid.scrollTop })
  check('focus stays on the Confirm button', mid.focusOnButton, mid)
  check('fields are read-only while sending, Cancel still clickable', mid.fieldsReadOnly && mid.cancelClickable, mid)
  check('form is marked busy and says it is sending', mid.ariaBusy === 'true' && mid.ariaDisabled === 'true' && /Sending/.test(mid.note), mid)
  await btn.click().catch(() => {})
  await p.keyboard.press('Enter')
  await sleep(300)
  check('a second click / Enter while sending sends nothing', bookCalls === 1, bookCalls)
  await sleep(4500)
  const done = await p.evaluate(() => { const d = document.querySelector('.vsps-overlay .vsps-booked'); return d ? d.textContent : '' })
  check('the confirmation shows when the booking returns', /Request sent|booked/i.test(done), done)
  await p.evaluate(() => { const c = [...document.querySelectorAll('.vsps-overlay button')].find((x) => /close/i.test(x.textContent)); if (c) c.click() })
  await sleep(500)

  // ---- 3. returning client: lookup answered by a Cloudflare HTML 502 ----
  await p.goto(BASE + '/?layout=full', { waitUntil: 'networkidle0' })
  await sleep(1500)
  await p.evaluate(() => { const s = document.querySelector('.vsps-widget select'); if (s) { s.value = s.options[1].value; s.dispatchEvent(new Event('change', { bubbles: true })) } })
  await sleep(1200)
  const slot = await p.evaluateHandle(() => [...document.querySelectorAll('.vsps-widget button')].find((x) => /\d{1,2}:\d{2}/.test(x.textContent) && x.getBoundingClientRect().height > 0))
  if (await slot.evaluate((x) => !!x)) {
    await slot.click(); await sleep(600)
    await p.evaluate(() => { const r = [...document.querySelectorAll('.vsps-overlay button')].find((x) => /returning/i.test(x.textContent)); if (r) r.click() })
    await sleep(400)
    await p.evaluate(() => { const e = document.querySelector('.vsps-overlay input[type=email], .vsps-overlay input[name=email]'); if (e) { e.value = 'webmaster@example.com'; e.dispatchEvent(new Event('input', { bubbles: true })) } })
    await p.evaluate(() => { const f = document.querySelector('.vsps-overlay form'); if (f) f.requestSubmit() })
    await sleep(1500)
    const err = await p.evaluate(() => [...document.querySelectorAll('.vsps-overlay .vsps-error, .vsps-overlay [role=alert], .vsps-overlay .vsps-field-error')].map((e) => e.textContent.trim()).filter(Boolean).join(' | '))
    check('HTML 502 on lookup: a readable message, not "Unexpected token"', !!err && !/Unexpected token|DOCTYPE|JSON/i.test(err), err)
  } else {
    check('full layout shows a time', false)
  }

  await b.close()
  console.log(`\n${pass} passed, ${fail} failed`)
  process.exit(fail ? 1 : 0)
})().catch((e) => { console.error('ERR', e.message); process.exit(1) })
