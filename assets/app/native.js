// PredictionComp iPhone app (Pete, 2026-11-20). Does nothing on the website: it only runs when the page is inside the
// app (Capacitor, which loads predictioncomp.com itself). There it:
// * replaces Google's web sign-in (Google blocks that inside apps) with Google's own iPhone sign-in, and adds
//   Sign in with Apple next to it (the App Store requires it beside Google). Both use the site's sign-in APIs.
// * offers reminders on the phone: a local notification 24 hours and 2 hours before the first match you haven't
//   predicted in each matchweek locks (/?api=app_reminders), rescheduled whenever the app is opened or left.
(() => {
  const C = window.Capacitor;
  if (!C || !C.isNativePlatform || !C.isNativePlatform()) return;
  const P = C.Plugins || {}, Social = P.SocialLogin, LN = P.LocalNotifications;
  const WEB_CLIENT = '368920224445-2kda8mnmsccsg890ttt2iiranrm2j1t7.apps.googleusercontent.com';
  const IOS_CLIENT = '368920224445-1cvoukharum8617el0cgqtt3tja1p68f.apps.googleusercontent.com'; // Google Cloud (PredictionComp project) > iOS client for uk.salsbury.predictioncomp
  const BUNDLE = 'uk.salsbury.predictioncomp';
  document.documentElement.classList.add('in-app');
  window.pcGoogleRequested = true; // home.js: never load Google's web sign-in script in the app

  document.head.insertAdjacentHTML('beforeend', `<style>
.pc-native{display:flex;flex-direction:column;gap:10px;width:100%;max-width:400px}
.pc-native button{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:46px;border-radius:8px;font:600 16px/1 -apple-system,system-ui,sans-serif;cursor:pointer}
.pc-native .g{background:#fff;color:#1f1f1f;border:1px solid #747775}.pc-native .a{background:#000;color:#fff;border:1px solid #000}
.pc-native svg{width:18px;height:18px;flex:none}
.pc-remind{margin:12px auto;max-width:640px;padding:14px 16px;border-radius:12px;background:#12324a;color:#eef5f8;border:1px solid #2a5b7c;font:15px/1.4 -apple-system,system-ui,sans-serif}
.pc-remind b{display:block;font-size:16px;margin-bottom:4px}.pc-remind div{display:flex;gap:10px;margin-top:10px}
.pc-remind button{flex:1;min-height:40px;border-radius:8px;border:1px solid #7fb8d8;background:transparent;color:inherit;font:600 15px/1 inherit}
.pc-remind button.on{background:#7fe0b0;border-color:#7fe0b0;color:#06243a}
.in-app #installApp,.in-app .installapp,.in-app [data-install]{display:none!important}
/* the website lays Google's invisible button over a drawn one; in the app the native buttons show instead */
.in-app .googleloginwrap{overflow:visible;min-height:0;border-radius:0}.in-app .googlelogin{display:none}.in-app .googlehit{position:static;opacity:1;display:block}
</style>`);

  const G_ICON = '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.6 5.4 2.7 13.3l7.9 6.1C12.5 13.6 17.8 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.3-4.6 7l7.5 5.8c4.4-4.1 6.8-10.1 6.8-17.3z"/><path fill="#FBBC05" d="M10.6 28.6A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.6l-7.9-6.1A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.7l8-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.8 2.3-8.4 2.3-6.2 0-11.5-4.2-13.4-9.9l-8 6.1C6.5 42.6 14.6 48 24 48z"/></svg>';
  const A_ICON = '<svg viewBox="0 0 17 20" aria-hidden="true"><path fill="#fff" d="M14.1 10.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.4-.9C3.6 4.8 2 5.8 1.1 7.4c-1.8 3.2-.5 7.9 1.3 10.5.9 1.3 1.9 2.7 3.2 2.6 1.3-.1 1.8-.8 3.3-.8 1.6 0 2 .8 3.4.8 1.4 0 2.3-1.3 3.2-2.6 1-1.5 1.4-2.9 1.4-3-.1 0-2.8-1.1-2.8-4.3zM11.6 3c.7-.9 1.2-2 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4z"/></svg>';

  const cancelled = e => e && (e.code === 'USER_CANCELLED' || /cancel|1001/i.test(e.message || ''));
  const call = (a, d) => (typeof window.api === 'function' ? window.api(a, d) : fetch('/?api=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ csrf: (window.PC && PC.csrf) || '' }, d || {})) }).then(async r => { const j = await r.json(); if (!r.ok || !j.ok) throw Error(j.error || 'Something went wrong. Please try again.'); return j; }));
  const signedIn = () => { location.href = (window.PC && PC.afterSignIn) || '/?signed_in=1#predict'; };
  let gInit = null, aInit = null;
  async function google() {
    await (gInit ||= Social.initialize({ google: { iOSClientId: IOS_CLIENT, iOSServerClientId: WEB_CLIENT } }).catch(e => { gInit = null; throw e; }));
    let token;
    try { token = (await Social.login({ provider: 'google', options: {} })).result.idToken; }
    catch (e) { if (cancelled(e)) return; throw Error(`Google sign-in didn't work (${(e && e.message) || 'unknown error'}). Please try again.`); }
    await call('google_login', { credential: token }); signedIn();
  }
  async function apple() {
    await (aInit ||= Social.initialize({ apple: { clientId: BUNDLE } }).catch(e => { aInit = null; throw e; }));
    let r;
    try { r = (await Social.login({ provider: 'apple', options: { scopes: ['name', 'email'] } })).result; }
    catch (e) { if (cancelled(e)) return; throw Error(`Sign in with Apple didn't work (${(e && e.message) || 'unknown error'}). Please try again.`); }
    const p = r.profile || {};
    await call('apple_login', { id_token: r.idToken, name: [p.givenName, p.familyName].filter(Boolean).join(' ') }); signedIn();
  }
  function run(fn, msgEl) {
    return async () => {
      if (msgEl) msgEl.textContent = 'Signing you in…';
      try { await fn(); if (msgEl) msgEl.textContent = ''; } catch (e) { if (msgEl) msgEl.textContent = e.message; else alert(e.message); }
    };
  }
  function renderSignIn() {
    if (!Social) return;
    document.querySelectorAll('#googleButton').forEach(el => {
      if (el.dataset.native) return;
      el.dataset.native = el.dataset.rendered = '1';
      const msg = document.getElementById('authMessage') || el.parentElement.querySelector('.authmessage');
      el.innerHTML = `<div class="pc-native"><button type="button" class="a">${A_ICON}Sign in with Apple</button><button type="button" class="g">${G_ICON}Continue with Google</button></div>`;
      el.querySelector('.a').onclick = run(apple, msg);
      el.querySelector('.g').onclick = run(google, msg);
    });
    // "Link Google account" in the profile: the same native sign-in links Google to the signed-in account.
    document.querySelectorAll('a[href*="auth=google_start"]').forEach(a => {
      if (a.dataset.native) return;
      a.dataset.native = '1';
      a.addEventListener('click', e => { e.preventDefault(); run(google, a.parentElement.querySelector('.authnote'))(); });
    });
  }

  // ---------- Reminders on this phone ----------
  const KEY = 'pc-app-reminders'; // 'on' or 'off' once the player has chosen
  const pref = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
  const setPref = v => { try { localStorage.setItem(KEY, v); } catch (e) {} };
  async function schedule() {
    if (!LN || pref() !== 'on') return;
    let r;
    try { r = await fetch('/?api=app_reminders', { credentials: 'same-origin' }).then(x => x.json()); } catch (e) { return; }
    if (!r || !r.ok) return;
    try {
      const pending = (await LN.getPending()).notifications || [];
      if (pending.length) await LN.cancel({ notifications: pending.map(n => ({ id: n.id })) });
      const now = Date.now() + 60000, list = [];
      r.weeks.slice(0, 4).forEach((w, i) => {
        const kick = Date.parse(w.first_kickoff), picks = `${w.missing} ${w.missing === 1 ? 'pick' : 'picks'} missing`;
        [[24, 'Predictions lock tomorrow', `Matchweek ${w.gameweek}: ${picks}. ${w.first} kicks off in 24 hours.`],
         [2, 'Last chance to predict', `Matchweek ${w.gameweek}: ${picks}. ${w.first} kicks off in 2 hours.`]].forEach(([h, title, body], j) => {
          const at = kick - h * 3600000;
          if (at > now) list.push({ id: 100 + i * 10 + j, title, body, schedule: { at: new Date(at), allowWhileIdle: true } });
        });
      });
      if (list.length) await LN.schedule({ notifications: list });
    } catch (e) { console.warn('reminders', e); }
  }
  function offerReminders() {
    if (!LN || pref() || !(window.PC && PC.signedIn !== false) || !document.querySelector('#predict, .fixture')) return;
    const box = document.createElement('section');
    box.className = 'pc-remind';
    box.innerHTML = '<b>Reminders on this phone?</b>We\'ll send a notification 24 hours and 2 hours before the first match you haven\'t predicted in each matchweek. Nothing else.<div><button type="button" class="on">Turn on</button><button type="button">No thanks</button></div>';
    const [yes, no] = box.querySelectorAll('button');
    yes.onclick = async () => {
      let ok = false;
      try { ok = (await LN.requestPermissions()).display === 'granted'; } catch (e) {}
      setPref(ok ? 'on' : 'off'); box.remove(); if (ok) schedule();
      else alert('Notifications are off for PredictionComp. You can switch them on in the iPhone Settings app.');
    };
    no.onclick = () => { setPref('off'); box.remove(); };
    const main = document.querySelector('main') || document.body;
    main.insertBefore(box, main.firstChild);
  }

  const start = () => { renderSignIn(); offerReminders(); schedule(); new MutationObserver(renderSignIn).observe(document.body, { childList: true, subtree: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  document.addEventListener('visibilitychange', () => { if (document.hidden) schedule(); }); // picks saved since: drop reminders that no longer apply
})();
