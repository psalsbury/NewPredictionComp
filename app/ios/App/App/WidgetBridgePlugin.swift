// Gives the website (assets/app/native.js) a way to hand the Home Screen widget its data: the player's widget token,
// stored in the App Group the widget reads. Registered by AppViewController.
import Foundation
import Capacitor
import WidgetKit

@objc(WidgetBridgePlugin)
public class WidgetBridgePlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "WidgetBridgePlugin"
    public let jsName = "WidgetBridge"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "setToken", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "refresh", returnType: CAPPluginReturnPromise)
    ]

    @objc func setToken(_ call: CAPPluginCall) {
        let shared = UserDefaults(suiteName: "group.uk.salsbury.predictioncomp")
        let token = call.getString("token") ?? ""
        if shared?.string(forKey: "widgetToken") != token {
            shared?.set(token, forKey: "widgetToken")
            WidgetCenter.shared.reloadAllTimelines()
        }
        call.resolve()
    }

    @objc func refresh(_ call: CAPPluginCall) {
        WidgetCenter.shared.reloadAllTimelines()
        call.resolve()
    }
}
