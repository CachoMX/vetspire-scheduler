// Offline checks for the traffic-channel classifier (v1.27.1). Loads the pure
// classifyChannel() block out of assets/js/scheduler.js (between the
// channel-classifier markers) so the test runs the shipped code.
//
//   node tests/channel-unit.js
'use strict';
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'scheduler.js'), 'utf8');
const block = src.match(/\/\* channel-classifier:start[^\n]*\n([\s\S]*?)\/\* channel-classifier:end \*\//);
if (!block) { console.error('classifier block not found'); process.exit(2); }
// eslint-disable-next-line no-new-func
const classifyChannel = new Function(block[1] + '\nreturn classifyChannel;')();

let pass = 0;
let fail = 0;
const SITE = 'iowacolony.easyvet.com';
function t(label, search, referrer, channel, source, ownHost) {
  const r = classifyChannel(search, referrer, ownHost === undefined ? SITE : ownHost);
  const ok = r.channel === channel && (source === undefined || r.source === source);
  if (ok) { pass++; console.log(`  PASS  ${label}`); }
  else { fail++; console.log(`  FAIL  ${label} — got ${JSON.stringify(r)}, want ${JSON.stringify({ channel, source })}`); }
}

console.log('== 1. utm_medium ==');
t('email', '?utm_source=mailchimp&utm_medium=email', '', 'Email', 'mailchimp');
t('e-mail', '?utm_medium=e-mail', '', 'Email', '');
t('newsletter, upper case', '?utm_medium=Newsletter&utm_source=Oct', '', 'Email', 'oct');
t('cpc → Paid Search', '?utm_source=google&utm_medium=cpc', '', 'Paid Search', 'google');
['ppc', 'paid', 'paidsearch', 'paid-search'].forEach((m) => t(`${m} → Paid Search`, `?utm_source=bing&utm_medium=${m}`, '', 'Paid Search', 'bing'));
t('cpc from a social source → Paid Social', '?utm_source=facebook&utm_medium=cpc', '', 'Paid Social', 'facebook');
t('cpc from fb → Paid Social', '?utm_source=fb&utm_medium=paid', '', 'Paid Social', 'fb');
t('cpc from instagram.com → Paid Social', '?utm_source=instagram.com&utm_medium=ppc', '', 'Paid Social', 'instagram.com');
['paid_social', 'paidsocial', 'social-paid', 'paid-social'].forEach((m) => t(`${m} → Paid Social`, `?utm_source=meta&utm_medium=${m}`, '', 'Paid Social', 'meta'));
['social', 'social-network', 'sm', 'social-media'].forEach((m) => t(`${m} → Organic Social`, `?utm_source=nextdoor&utm_medium=${m}`, '', 'Organic Social', 'nextdoor'));
t('organic → Organic Search', '?utm_source=gmb&utm_medium=organic', '', 'Organic Search', 'gmb');
t('referral → Referral', '?utm_source=partner&utm_medium=referral', '', 'Referral', 'partner');
t('unknown utm_medium → Unassigned', '?utm_source=flyer&utm_medium=print', '', 'Unassigned', 'flyer');
t('utm beats the referrer', '?utm_source=google&utm_medium=cpc', 'https://www.facebook.com/', 'Paid Search', 'google');
t('utm beats click ids', '?utm_medium=email&gclid=abc', '', 'Email');
t('utm_source cleaned to [a-z0-9.-] (≤64)', '?utm_source=Google%20Ads_Q4!&utm_medium=cpc', '', 'Paid Search', 'google-ads-q4');
t('utm_source capped at 64', '?utm_medium=cpc&utm_source=' + 'a'.repeat(80), '', 'Paid Search', 'a'.repeat(64));

console.log('== 2. Click ids (no utm_medium) ==');
t('gclid → Paid Search, source = referrer host', '?gclid=Cj0KCQ', 'https://www.google.com/', 'Paid Search', 'google.com');
t('gbraid', '?gbraid=x', '', 'Paid Search', '');
t('wbraid', '?wbraid=x', '', 'Paid Search');
t('msclkid', '?msclkid=x', 'https://www.bing.com/', 'Paid Search', 'bing.com');
t('fbclid → Organic Social', '?fbclid=IwAR', 'https://l.facebook.com/', 'Organic Social', 'l.facebook.com');
t('gclid beats an AI referrer', '?gclid=x', 'https://chatgpt.com/', 'Paid Search');
t('empty gclid is ignored', '?gclid=', '', 'Direct', '');

console.log('== 3. Referrer ==');
t('AI: chatgpt.com', '', 'https://chatgpt.com/c/123?x=1', 'AI Assistant', 'chatgpt.com');
t('AI: chat.openai.com', '', 'https://chat.openai.com/', 'AI Assistant', 'chat.openai.com');
t('AI: www.perplexity.ai', '', 'https://www.perplexity.ai/search', 'AI Assistant', 'perplexity.ai');
t('AI before Google: gemini.google.com', '', 'https://gemini.google.com/app', 'AI Assistant', 'gemini.google.com');
t('AI: copilot.microsoft.com', '', 'https://copilot.microsoft.com/', 'AI Assistant');
t('AI: claude.ai', '', 'https://claude.ai/chat/1', 'AI Assistant', 'claude.ai');
t('google.com', '', 'https://www.google.com/', 'Organic Search', 'google.com');
t('maps.google.com', '', 'https://maps.google.com/maps?cid=1', 'Organic Search', 'maps.google.com');
t('google.co.uk', '', 'https://www.google.co.uk/', 'Organic Search', 'google.co.uk');
t('google.com.mx', '', 'https://www.google.com.mx/', 'Organic Search', 'google.com.mx');
t('bing.com', '', 'https://www.bing.com/search?q=vet', 'Organic Search', 'bing.com');
t('search.yahoo.com', '', 'https://search.yahoo.com/', 'Organic Search', 'search.yahoo.com');
t('duckduckgo.com', '', 'https://duckduckgo.com/', 'Organic Search');
t('ecosia.org', '', 'https://www.ecosia.org/', 'Organic Search');
t('baidu.com', '', 'https://www.baidu.com/', 'Organic Search');
t('yandex.ru', '', 'https://yandex.ru/', 'Organic Search');
t('search.brave.com', '', 'https://search.brave.com/search', 'Organic Search');
t('l.facebook.com', '', 'https://l.facebook.com/l.php?u=x', 'Organic Social', 'l.facebook.com');
t('m.facebook.com', '', 'https://m.facebook.com/', 'Organic Social');
t('instagram.com', '', 'https://www.instagram.com/', 'Organic Social', 'instagram.com');
t('t.co', '', 'https://t.co/abc', 'Organic Social', 't.co');
t('x.com', '', 'https://x.com/', 'Organic Social');
t('linkedin.com', '', 'https://www.linkedin.com/feed', 'Organic Social');
t('lnkd.in', '', 'https://lnkd.in/x', 'Organic Social');
t('pinterest.ca', '', 'https://www.pinterest.ca/pin/1', 'Organic Social', 'pinterest.ca');
t('tiktok.com', '', 'https://www.tiktok.com/', 'Organic Social');
t('m.youtube.com', '', 'https://m.youtube.com/', 'Organic Social');
t('reddit.com', '', 'https://old.reddit.com/r/dogs', 'Organic Social');
t('nextdoor.com', '', 'https://nextdoor.com/', 'Organic Social');
t('yelp.com is Referral, not social', '', 'https://www.yelp.com/biz/x', 'Referral', 'yelp.com');
t('any other site → Referral', '', 'https://www.petfinder.com/a/b?c=d', 'Referral', 'petfinder.com');
t('lookalike host is not Google', '', 'https://google.evil.test/', 'Referral', 'google.evil.test');
t('lookalike host is not Facebook', '', 'https://notfacebook.com/', 'Referral', 'notfacebook.com');
t('referrer with a port and user', '', 'https://user@www.example.org:8443/x', 'Referral', 'example.org');

console.log('== 4. Self-referral / direct / empty values ==');
t('own host → Direct', '', 'https://iowacolony.easyvet.com/home-2/', 'Direct', '');
t('own host with www. → Direct', '', 'https://www.iowacolony.easyvet.com/', 'Direct', '');
t('own host + utm still uses the utm', '?utm_source=google&utm_medium=cpc', 'https://iowacolony.easyvet.com/', 'Paid Search', 'google');
t('no referrer, no utm → Direct', '', '', 'Direct', '');
t('all empty / undefined → Direct', undefined, undefined, 'Direct', '', undefined);
t('empty utm values → Direct', '?utm_source=&utm_medium=', '', 'Direct', '');
t('garbage referrer → Direct', '', 'not a url', 'Direct', '');
t('android-app referrer → Referral', '', 'android-app://com.google.android.gm/', 'Referral', 'com.google.android.gm');

console.log('== 5. utm_source without utm_medium ==');
t('utm_source=google → Organic Search', '?utm_source=google', '', 'Organic Search', 'google');
t('utm_source=facebook → Organic Social', '?utm_source=facebook', 'https://www.google.com/', 'Organic Social', 'facebook');
t('utm_source=chatgpt.com → AI Assistant', '?utm_source=chatgpt.com', '', 'AI Assistant', 'chatgpt.com');
t('other utm_source → Unassigned', '?utm_source=flyer', '', 'Unassigned', 'flyer');

const all = ['Direct', 'Organic Search', 'Paid Search', 'Organic Social', 'Paid Social', 'Email', 'Referral', 'AI Assistant', 'Unassigned'];
const r = classifyChannel('?utm_medium=whatever', '', SITE);
if (all.indexOf(r.channel) !== -1) { pass++; console.log('  PASS  result is always one of the 9 channels'); } else { fail++; console.log('  FAIL  channel set'); }

console.log(`\n==== RESULT: ${pass} passed, ${fail} failed ====`);
process.exit(fail ? 1 : 0);
