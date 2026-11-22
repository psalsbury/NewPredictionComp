# Applies salsbury.uk's settings to a Capacitor iPhone project (ios/), after `npx cap add ios` / `npx cap sync ios`.
# Safe to run again. Usage (from the app folder):
#   ios-setup.py <app name> <bundle id> <version> <AdMob iOS app id or ''> <Google iOS client id or ''> <tracking text>
import sys, plistlib, re, os
name, bundle, version, admob, gclient, tracking = sys.argv[1:7]
TEST_ADMOB = 'ca-app-pub-3940256099942544~1458002511'  # Google's sample iOS app ID: test adverts only
P = 'ios/App/App/Info.plist'
pl = plistlib.load(open(P, 'rb'))
pl['CFBundleDisplayName'] = name
if admob == 'none':  # an app without adverts: no AdMob settings and no tracking question
    for k in ('GADApplicationIdentifier', 'NSUserTrackingUsageDescription', 'SKAdNetworkItems'): pl.pop(k, None)
else:
    pl['GADApplicationIdentifier'] = admob or TEST_ADMOB
    pl['NSUserTrackingUsageDescription'] = tracking
pl['ITSAppUsesNonExemptEncryption'] = False  # only HTTPS, so no export-compliance questions
# Google's own ad network ID (AdMob asks for it; other networks' IDs only matter with mediation, which isn't used).
if admob != 'none': pl['SKAdNetworkItems'] = [{'SKAdNetworkIdentifier': 'cstr6suwn9.skadnetwork'}]
pl['UISupportedInterfaceOrientations'] = ['UIInterfaceOrientationPortrait']
pl['UISupportedInterfaceOrientations~ipad'] = ['UIInterfaceOrientationPortrait', 'UIInterfaceOrientationPortraitUpsideDown']  # iPad: upright either way up
pl['UIRequiresFullScreen'] = True
pl['UIStatusBarStyle'] = 'UIStatusBarStyleLightContent'
types = [t for t in pl.get('CFBundleURLTypes', []) if t.get('CFBundleURLName') != 'google-sign-in']
if gclient:  # Google's iOS sign-in needs the reversed client ID as a URL scheme
    types.append({'CFBundleURLName': 'google-sign-in', 'CFBundleURLSchemes': ['.'.join(reversed(gclient.split('.')))]})
if types: pl['CFBundleURLTypes'] = types
else: pl.pop('CFBundleURLTypes', None)
plistlib.dump(pl, open(P, 'wb'))

# Sign in with Apple capability.
plistlib.dump({'com.apple.developer.applesignin': ['Default']}, open('ios/App/App/App.entitlements', 'wb'))

X = 'ios/App/App.xcodeproj/project.pbxproj'
s = open(X).read()
s = s.replace('TARGETED_DEVICE_FAMILY = 1;', 'TARGETED_DEVICE_FAMILY = "1,2";')  # iPhone and iPad (full screen on an iPad; Pete, 2026-11-22)
s = re.sub(r'PRODUCT_BUNDLE_IDENTIFIER = [^;]+;', f'PRODUCT_BUNDLE_IDENTIFIER = {bundle};', s)
s = re.sub(r'MARKETING_VERSION = [^;]+;', f'MARKETING_VERSION = {version};', s)
if 'CODE_SIGN_ENTITLEMENTS' not in s:
    s = re.sub(r'(\n(\t+)PRODUCT_BUNDLE_IDENTIFIER = )', r'\n\2CODE_SIGN_ENTITLEMENTS = App/App.entitlements;\1', s)
open(X, 'w').write(s)
print('ios setup done:', name, bundle, version, 'admob', 'NONE' if admob == 'none' else 'REAL' if admob else 'TEST', 'google', 'yes' if gclient else 'no')
