#!/bin/bash
# Renders launcher icons and splash images into the Android project from src/icons/*.svg (they wrap PredictionComp's app icon).
set -euo pipefail
cd "$(dirname "$0")/.."; R=android/app/src/main/res; I=src/icons
for d in mdpi:48 hdpi:72 xhdpi:96 xxhdpi:144 xxxhdpi:192; do
  n=${d%%:*}; s=${d##*:}
  rsvg-convert -w $s -h $s $I/square.svg -o $R/mipmap-$n/ic_launcher.png
  rsvg-convert -w $s -h $s $I/round.svg -o $R/mipmap-$n/ic_launcher_round.png
  f=$((s * 9 / 4)); rsvg-convert -w $f -h $f $I/foreground.svg -o $R/mipmap-$n/ic_launcher_foreground.png
done
sed -i 's/<color name="ic_launcher_background">#[0-9A-Fa-f]*</<color name="ic_launcher_background">#071522</' $R/values/ic_launcher_background.xml
# Splash: the icon art centred on the night colour, at each existing splash size.
for p in $R/drawable*/splash.png; do
  read w h < <(file -b "$p" | grep -o '[0-9]* x [0-9]*' | tr -d 'x')
  m=$(( (w < h ? w : h) * 2 / 5 ))
  printf '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"><rect width="100%%" height="100%%" fill="#071522"/><image x="%d" y="%d" width="%d" height="%d" href="data:image/png;base64,%s"/></svg>' \
    $w $h $(( (w - m) / 2 )) $(( (h - m) / 2 )) $m $m "$(base64 -w0 $I/icon-1024.png)" | rsvg-convert -o "$p"
done
echo icons done
