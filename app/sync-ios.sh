#!/bin/bash
# Makes or refreshes the iPhone project (ios/). The app loads https://predictioncomp.com (capacitor.config.json
# server.url); www/ only holds the offline page. Capacitor 8 uses Swift Package Manager, so this runs on Linux;
# Codemagic builds it on a Mac (codemagic.yaml at the NewPredictionComp repo root). Commit ios/ afterwards.
set -euo pipefail
cd "$(dirname "$0")"
export PATH=/opt/node/bin:$PATH
NAME='PredictionComp'; BUNDLE='uk.salsbury.predictioncomp'; BG='#071522'; VERSION='1.0'
IOS_GOOGLE_CLIENT_ID='368920224445-1cvoukharum8617el0cgqtt3tja1p68f.apps.googleusercontent.com'  # Google Cloud (PredictionComp project) > Credentials > iOS client for $BUNDLE (same as in the site's assets/app/native.js)
[ -d ios ] || npx cap add ios
npx cap sync ios
python3 src/ios-setup.py "$NAME" "$BUNDLE" "$VERSION" none "$IOS_GOOGLE_CLIENT_ID" ''
python3 src/make-ios-assets.py src/icons/square.svg "$BG" ios/App/App/Assets.xcassets
sed -i '/^App\/App\/public$/d; /^App\/App\/capacitor.config.json$/d; /^App\/App\/config.xml$/d' ios/.gitignore
echo "iPhone project ready ($NAME $VERSION)"
