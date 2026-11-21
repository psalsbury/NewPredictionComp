# Run by Codemagic (codemagic.yaml) on the Mac before signing: adds the app's own Swift files and the Home Screen
# widget extension (PCWidget) to the Capacitor iPhone project. The Linux server that makes ios/ has no Xcode tools,
# so this happens at build time. Safe to run twice.
require 'xcodeproj'

project = Xcodeproj::Project.open('ios/App/App.xcodeproj')
app = project.targets.find { |t| t.name == 'App' } or abort 'no App target'
if project.targets.any? { |t| t.name == 'PCWidget' }
  puts 'PCWidget already added'
  exit 0
end
app_settings = app.build_configurations.first.build_settings
version = app_settings['MARKETING_VERSION'] || '1.0'

# The app's own plugin (WidgetBridge) and the view controller that registers it
app_group = project.main_group.find_subpath('App', false) or abort 'no App group'
%w[AppViewController.swift WidgetBridgePlugin.swift].each do |f|
  ref = app_group.files.find { |x| x.path == f } || app_group.new_reference(f)
  app.source_build_phase.add_file_reference(ref, true)
end

# The widget extension
widget = project.new_target(:app_extension, 'PCWidget', :ios, '17.0', nil, :swift)
group = project.main_group.new_group('PCWidget', 'PCWidget')
widget.source_build_phase.add_file_reference(group.new_reference('PCWidget.swift'), true)
group.new_reference('Info.plist')
group.new_reference('PCWidget.entitlements')
widget.add_system_framework(%w[WidgetKit SwiftUI])
widget.build_configurations.each do |c|
  s = c.build_settings
  s['PRODUCT_BUNDLE_IDENTIFIER'] = 'uk.salsbury.predictioncomp.widget'
  s['PRODUCT_NAME'] = '$(TARGET_NAME)'
  s['INFOPLIST_FILE'] = 'PCWidget/Info.plist'
  s['GENERATE_INFOPLIST_FILE'] = 'NO'
  s['CODE_SIGN_ENTITLEMENTS'] = 'PCWidget/PCWidget.entitlements'
  s['SWIFT_VERSION'] = '5.0'
  s['TARGETED_DEVICE_FAMILY'] = '1'
  s['IPHONEOS_DEPLOYMENT_TARGET'] = '17.0'
  s['MARKETING_VERSION'] = version
  s['CURRENT_PROJECT_VERSION'] = '1'
  s['SKIP_INSTALL'] = 'YES'
  s['DEVELOPMENT_TEAM'] = 'PXVHAT4H3F'
  s['LD_RUNPATH_SEARCH_PATHS'] = '$(inherited) @executable_path/Frameworks @executable_path/../../Frameworks'
  s['APPLICATION_EXTENSION_API_ONLY'] = 'YES'
end

# Build the widget with the app and put it in the app's PlugIns folder
app.add_dependency(widget)
embed = app.copy_files_build_phases.find { |p| p.name == 'Embed Foundation Extensions' } || app.new_copy_files_build_phase('Embed Foundation Extensions')
embed.dst_subfolder_spec = '13' # PlugIns
embed.dst_path = ''
bf = embed.add_file_reference(widget.product_reference, true)
bf.settings = { 'ATTRIBUTES' => ['RemoveHeadersOnCopy'] }

project.save
puts "PCWidget added (version #{version})"
