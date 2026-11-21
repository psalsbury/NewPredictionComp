# App Store screenshot 4: the Home Screen widget (small + medium, drawn like PCWidget.swift) and a results notification,
# filled with the App Review demo account's real data from ?api=widget. 1206x2622.
import asyncio, json, re, subprocess
from playwright.async_api import async_playwright
CHROME = '/root/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell'
OUT = '/var/lib/salsbury-uk/appstore-shots/pc/'
uid = re.search(r'User id: (\d+)', open('/etc/predictioncomp/app-review.txt').read()).group(1)
php = ('define("PC_ROOT","/var/www/predictioncomp.com/public_html"); require PC_ROOT."/inc/app.php"; '
       f'$db=new PDO("sqlite:/var/lib/predictioncomp/predictioncomp.sqlite"); echo json_encode(pc_app_summary($db,{uid}));')
d = json.loads(subprocess.check_output(['sudo', '-u', 'www-data', 'php', '-r', php]))
n, l = d['next'], d['league']
ordn = lambda k: f"{k}{'th' if 10 <= k % 100 <= 20 else {1: 'st', 2: 'nd', 3: 'rd'}.get(k % 10, 'th')}"
HTML = f"""<!doctype html><html><head><style>
*{{box-sizing:border-box;margin:0}}body{{width:402px;height:874px;overflow:hidden;font-family:-apple-system,'SF Pro Text',system-ui,sans-serif;color:#fff;
background:radial-gradient(120% 70% at 20% 10%,#2b6f8f 0%,#123a57 40%,#071522 100%)}}
.top{{padding:64px 26px 0;text-align:center}}.top h1{{font-size:30px;font-weight:800;line-height:1.1;letter-spacing:-.5px}}.top p{{margin-top:10px;font-size:16px;color:#bcd3e2}}
.note{{margin:26px 14px 0;padding:12px 14px;border-radius:20px;background:rgba(245,245,247,.92);color:#111;display:flex;gap:10px;box-shadow:0 10px 30px rgba(0,0,0,.3)}}
.note img{{width:38px;height:38px;border-radius:9px;flex:none}}.note b{{font-size:15px}}.note .t{{display:flex;justify-content:space-between;font-size:13px;color:#555}}.note p{{font-size:15px;line-height:1.3;margin-top:2px}}
.grid{{display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:34px 22px 0}}
.w{{background:#071522;border-radius:22px;padding:15px;box-shadow:0 12px 30px rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.06)}}
.w.m{{grid-column:1/3;display:grid;grid-template-columns:1fr 1fr;gap:14px;height:170px}}.w.s{{height:170px;display:flex;flex-direction:column}}
.k{{font-size:11px;font-weight:900;color:#7fe0b0;letter-spacing:.3px}}.h{{font-size:17px;font-weight:700;margin-top:3px}}.sub{{font-size:15px;font-weight:600;margin-top:2px}}
.soft{{color:#a8c2d4;font-size:12px}}.col{{display:flex;flex-direction:column}}.grow{{flex:1}}.big{{font-size:22px;font-weight:800;margin-top:3px}}
.app{{display:flex;flex-direction:column;align-items:center;gap:6px;font-size:12px}}.app img{{width:64px;height:64px;border-radius:15px}}
.lbl{{text-align:center;font-size:12px;margin-top:6px;color:#dbe7ef}}
</style></head><body>
<div class="top"><h1>Your league on your Home Screen</h1><p>A widget for your next deadline, and your results the moment they're in.</p></div>
<div class="note"><img src="https://predictioncomp.com/assets/app/icon-192.png"><div style="flex:1"><div class="t"><b style="color:#111">PredictionComp</b><span>now</span></div>
<p><b>Matchweek 10 results</b><br>You scored 22 points. You're {ordn(l['position'])} of {l['of']} in {l['name']}.</p></div></div>
<div class="grid">
 <div class="w m"><div class="col"><div class="k">PREDICTIONCOMP</div><div class="h">Matchweek {n['gameweek']}</div><div class="sub">{n['missing']} of {n['total']} picks missing</div><div class="grow"></div><div class="soft">Locks Sat 12:30</div></div>
  <div class="col"><div class="k">YOUR LEAGUE</div><div class="big">{ordn(l['position'])} of {l['of']}</div><div class="soft">{l['name']}</div><div class="grow"></div><div style="font-size:12px;font-weight:600">{l['points']} pts</div></div></div>
 <div><div class="w s"><div class="k">PREDICTIONCOMP</div><div class="h">Matchweek {n['gameweek']}</div><div class="sub">{n['missing']} of {n['total']} picks missing</div><div class="grow"></div><div class="soft">Locks Sat 12:30</div></div><div class="lbl">PredictionComp</div></div>
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-content:start;padding-top:6px">
  <div class="app"><img src="https://predictioncomp.com/assets/app/icon-192.png">PredictionComp</div>
 </div>
</div></body></html>"""
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
        pg = await b.new_page(viewport={'width': 402, 'height': 874}, device_scale_factor=3)
        await pg.set_content(HTML); await pg.wait_for_timeout(1500)
        await pg.screenshot(path=OUT + '4-widget.png'); await b.close()
asyncio.run(main())
print('ok', n, l)
