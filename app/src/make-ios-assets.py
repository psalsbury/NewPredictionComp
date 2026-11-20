# Renders (rsvg-convert) the iPhone app icon (1024 x 1024, no transparency, as Apple requires) and the launch-screen image from the
# Android adaptive-icon foreground SVG on the app's background colour. Usage:
#   make-ios-assets.py <foreground.svg> <#background> <ios/App/App/Assets.xcassets>
import sys, os, json, zlib, struct, subprocess, re
fg, bg, assets = sys.argv[1], sys.argv[2], sys.argv[3]
svg = open(fg).read()

def render(w, h, scale, out):  # foreground centred at `scale` times the shorter side, on the background colour
    m = min(w, h) * scale
    vb = re.search(r'viewBox="([^"]+)"', svg).group(1)
    inner = re.sub(r'^.*?<svg[^>]*>', '', svg, count=1, flags=re.S).rsplit('</svg>', 1)[0]
    doc = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}"><rect width="100%" height="100%" fill="{bg}"/>'
           f'<svg x="{(w - m) / 2}" y="{(h - m) / 2}" width="{m}" height="{m}" viewBox="{vb}">{inner}</svg></svg>')
    subprocess.run(['rsvg-convert', '-o', out], input=doc.encode(), check=True)
    to_rgb(out)

def to_rgb(path):  # Chrome writes RGBA PNGs; Apple rejects an icon with an alpha channel, so drop it
    d = open(path, 'rb').read(); pos = 8; idat = b''; ihdr = None
    while pos < len(d):
        n, t = struct.unpack('>I4s', d[pos:pos + 8]); body = d[pos + 8:pos + 8 + n]; pos += 12 + n
        if t == b'IHDR': ihdr = body
        elif t == b'IDAT': idat += body
    w, h, depth, ctype = struct.unpack('>IIBB', ihdr[:10])
    if ctype == 2: return
    assert depth == 8 and ctype == 6, (depth, ctype)
    raw = zlib.decompress(idat); bpp = 4; stride = w * bpp; out = bytearray(); prev = bytearray(stride); i = 0
    for _ in range(h):
        f = raw[i]; line = bytearray(raw[i + 1:i + 1 + stride]); i += 1 + stride
        for x in range(stride):
            a = line[x - bpp] if x >= bpp else 0; b = prev[x]; c = prev[x - bpp] if x >= bpp else 0
            if f == 1: line[x] = (line[x] + a) & 255
            elif f == 2: line[x] = (line[x] + b) & 255
            elif f == 3: line[x] = (line[x] + (a + b) // 2) & 255
            elif f == 4:
                p = a + b - c; pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                line[x] = (line[x] + (a if pa <= pb and pa <= pc else b if pb <= pc else c)) & 255
        prev = line
        out += b'\0' + b''.join(bytes(line[k:k + 3]) for k in range(0, stride, 4))
    chunk = lambda t, b: struct.pack('>I', len(b)) + t + b + struct.pack('>I', zlib.crc32(t + b))
    open(path, 'wb').write(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
                           + chunk(b'IDAT', zlib.compress(bytes(out), 9)) + chunk(b'IEND', b''))

icon_dir = os.path.join(assets, 'AppIcon.appiconset'); os.makedirs(icon_dir, exist_ok=True)
for f in os.listdir(icon_dir): os.remove(os.path.join(icon_dir, f))
render(1024, 1024, 1.0, os.path.join(icon_dir, 'AppIcon-1024.png'))  # the icon art fills the whole square
json.dump({'images': [{'filename': 'AppIcon-1024.png', 'idiom': 'universal', 'platform': 'ios', 'size': '1024x1024'}],
           'info': {'author': 'xcode', 'version': 1}}, open(os.path.join(icon_dir, 'Contents.json'), 'w'), indent=2)
# Launch screen (Capacitor's storyboard shows the "Splash" image, scaled to fill): the icon art on the background.
sp = os.path.join(assets, 'Splash.imageset')
if os.path.isdir(sp):
    for f in os.listdir(sp): os.remove(os.path.join(sp, f))
    os.makedirs(sp, exist_ok=True)
    render(2732, 2732, 0.22, os.path.join(sp, 'splash-2732.png'))
    json.dump({'images': [{'filename': 'splash-2732.png', 'idiom': 'universal', 'scale': s} for s in ('1x', '2x', '3x')],
               'info': {'author': 'xcode', 'version': 1}}, open(os.path.join(sp, 'Contents.json'), 'w'), indent=2)
print('ios assets done')
