# PredictionComp-only additions to the iPhone project, run by sync-ios.sh after ios-setup.py (2026-11-21):
# the WidgetBridge plugin + its view controller, the widget's files (the target itself is added on the Mac by
# add_widget.rb), push notifications (AppDelegate forwards the device token to Capacitor) and the entitlements.
import os, re, shutil, plistlib
S = 'src/ios'
A = 'ios/App/App'
for f in ('AppViewController.swift', 'WidgetBridgePlugin.swift'):
    shutil.copy(f'{S}/{f}', f'{A}/{f}')
os.makedirs('ios/App/PCWidget', exist_ok=True)
for f in ('PCWidget.swift', 'Info.plist', 'PCWidget.entitlements'):
    shutil.copy(f'{S}/{f}', f'ios/App/PCWidget/{f}')

sb = f'{A}/Base.lproj/Main.storyboard'
s = open(sb).read()
s = s.replace('customClass="CAPBridgeViewController" customModule="Capacitor"', 'customClass="AppViewController" customModule="App" customModuleProvider="target"')
assert 'AppViewController' in s, 'storyboard not patched'
open(sb, 'w').write(s)

ad = f'{A}/AppDelegate.swift'
s = open(ad).read()
if 'capacitorDidRegisterForRemoteNotifications' not in s:
    hook = '''
    // Push notifications: hand the device token (or the failure) to Capacitor's PushNotifications plugin.
    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
    }
'''
    i = s.rindex('}')
    s = s[:i] + hook + s[i:]
    open(ad, 'w').write(s)

ent = f'{A}/App.entitlements'
e = plistlib.load(open(ent, 'rb'))
e['aps-environment'] = 'production'
e['com.apple.security.application-groups'] = ['group.uk.salsbury.predictioncomp']
plistlib.dump(e, open(ent, 'wb'))
print('PredictionComp iOS extras in place')
