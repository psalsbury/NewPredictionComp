// The app's main view (Main.storyboard uses this class instead of CAPBridgeViewController) so the app's own plugin,
// WidgetBridge, is available to the website's native.js.
import UIKit
import Capacitor

class AppViewController: CAPBridgeViewController {
    override open func capacitorDidLoad() {
        bridge?.registerPluginInstance(WidgetBridgePlugin())
    }
}
