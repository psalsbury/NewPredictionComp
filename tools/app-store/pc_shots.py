# PredictionComp App Store screenshots (1206x2622, the required iPhone 6.1"/6.3" size): the site inside a fake iPhone
# app (so native.js's app look applies), signed in as the App Review demo account, plus one image of the Home Screen
# widget and a results notification drawn from the demo account's real data.
import asyncio, json, re, subprocess, os
from playwright.async_api import async_playwright
CHROME = '/root/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell'
OUT = '/var/lib/salsbury-uk/appstore-shots/pc/'; os.makedirs(OUT, exist_ok=True)
cred = open('/etc/predictioncomp/app-review.txt').read()
EMAIL = re.search(r'Email: (\S+)', cred).group(1); PW = re.search(r'Password: (\S+)', cred).group(1)
CODE = re.search(r'League: .*\((\w+), id', cred).group(1)
MOCK = """
const stub = name => new Proxy({}, { get: (t, k) => k === 'then' ? undefined : () => Promise.resolve(k === 'getPending' ? { notifications: [] } : k === 'checkPermissions' ? { receive: 'granted' } : {}) });
window.Capacitor = { getPlatform: () => 'ios', isNativePlatform: () => true, Plugins: { SocialLogin: stub(), LocalNotifications: stub(), PushNotifications: stub(), Haptics: stub(), Share: stub(), Browser: stub(), WidgetBridge: stub() } };
"""
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
        ctx = await b.new_context(viewport={'width': 402, 'height': 874}, device_scale_factor=3, is_mobile=True, has_touch=True, service_workers='block',
                                  user_agent='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 PredictionCompApp/1.0')
        await ctx.add_init_script(MOCK)
        pg = await ctx.new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:150]))
        # signed out: the sign-in screen with Sign in with Apple
        await pg.goto('https://predictioncomp.com/'); await pg.wait_for_timeout(2500)
        await pg.evaluate("(() => { const b = [...document.querySelectorAll('button, a')].find(x => /^\\s*sign in\\s*$/i.test(x.textContent)); if (b) b.click(); })()")
        await pg.wait_for_timeout(1500); await pg.screenshot(path=OUT + '5-signin.png')
        r = await pg.evaluate("(a) => api('password_login', a).then(() => 'ok', e => e.message)", {'email': EMAIL, 'password': PW})
        print('login:', r)
        await pg.goto('https://predictioncomp.com/#predict'); await pg.wait_for_timeout(3000)
        await pg.evaluate("localStorage.removeItem('pc-app-reminders')"); await pg.reload(); await pg.wait_for_timeout(3000)
        await pg.screenshot(path=OUT + '1-predict.png')
        await pg.evaluate("localStorage.setItem('pc-app-reminders', 'on'); document.querySelector('.pc-remind')?.remove()")
        for name, url in (('2-league', f'https://predictioncomp.com/league/{CODE}'), ('3-results', 'https://predictioncomp.com/results')):
            resp = await pg.goto(url); await pg.wait_for_timeout(2500)
            print(name, resp.status if resp else None, pg.url)
            await pg.screenshot(path=OUT + name + '.png')
        print('errors:', errs)
        await b.close()
asyncio.run(main())
