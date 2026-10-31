// "Share your week": draws a 1080x1350 matchweek card and shares it (image + text + invite link).
(function(){
 const W=1080,H=1350,MINT='#78d7cf',GOLD='#f4c542',GREEN='#46cf8f',INK='#0c1520',TEXT='#eef3f7',MUTED='#9fb0c0';
 const D='"Barlow Condensed",Barlow,system-ui,sans-serif',F='Barlow,system-ui,-apple-system,"Segoe UI",sans-serif';
 function ord(n){const s=['th','st','nd','rd'],v=n%100;return n+(s[(v-20)%10]||s[v]||s[0])}
 function rr(x,c,X,Y,w,h,r){x.beginPath();x.moveTo(X+r,Y);x.arcTo(X+w,Y,X+w,Y+h,r);x.arcTo(X+w,Y+h,X,Y+h,r);x.arcTo(X,Y+h,X,Y,r);x.arcTo(X,Y,X+w,Y,r);x.closePath();if(c){x.fillStyle=c;x.fill()}}
 function fit(x,t,max,size,w,fam){let s=size;do{x.font=`${w} ${s}px ${fam}`;s-=2}while(x.measureText(t).width>max&&s>18)}
 async function make(c){
  try{await Promise.all([document.fonts.load(`800 120px ${D}`),document.fonts.load(`600 40px ${F}`)])}catch(e){}
  const cv=document.createElement('canvas');cv.width=W;cv.height=H;const x=cv.getContext('2d');
  x.fillStyle=INK;x.fillRect(0,0,W,H);
  let g=x.createRadialGradient(W*.9,-60,30,W*.9,-60,1000);g.addColorStop(0,'rgba(120,215,207,.32)');g.addColorStop(1,'rgba(120,215,207,0)');x.fillStyle=g;x.fillRect(0,0,W,H);
  g=x.createRadialGradient(60,H+40,20,60,H+40,800);g.addColorStop(0,'rgba(244,197,66,.16)');g.addColorStop(1,'rgba(244,197,66,0)');x.fillStyle=g;x.fillRect(0,0,W,H);
  x.textAlign='center';
  x.font=`800 44px ${D}`;x.fillStyle=TEXT;const a='PREDICTION',b='COMP',wa=x.measureText(a).width,wb=x.measureText(b).width;x.textAlign='left';x.fillText(a,W/2-(wa+wb)/2,104);x.fillStyle=MINT;x.fillText(b,W/2-(wa+wb)/2+wa,104);x.textAlign='center';
  x.fillStyle=MUTED;x.font=`600 34px ${F}`;x.fillText(`Matchweek ${c.week} · ${c.season}${c.complete?'':' · so far'}`,W/2,170);
  x.fillStyle=TEXT;fit(x,c.name,W-160,88,'800',D);x.fillText(c.name,W/2,290);
  // Points
  x.fillStyle=GOLD;x.font=`800 260px ${D}`;x.fillText(String(c.pts),W/2,560);
  x.fillStyle=MUTED;x.font=`700 44px ${D}`;x.fillText(c.pts===1?'POINT':'POINTS',W/2,620);
  // Stat tiles
  const tiles=[[c.exact,'Exact scores',GREEN],[c.correct,'Correct results',GOLD],[`${c.scored}`,`Matches scored`,MINT]];
  const tw=290,gap=30,tx=(W-(3*tw+2*gap))/2,ty=680;
  tiles.forEach(([v,l,col],i)=>{const X=tx+i*(tw+gap);rr(x,'rgba(255,255,255,.05)',X,ty,tw,190,26);x.lineWidth=2;x.strokeStyle='rgba(255,255,255,.10)';x.stroke();
   x.fillStyle=col;x.font=`800 104px ${D}`;x.fillText(String(v),X+tw/2,ty+112);x.fillStyle=MUTED;x.font=`600 30px ${F}`;x.fillText(l,X+tw/2,ty+160)});
  // League place
  let y=950;
  if(c.place){const t=`${ord(c.place.rank)} of ${c.place.of} in ${c.place.name}`;x.font=`700 40px ${F}`;const w=Math.min(W-120,x.measureText(t).width+80);rr(x,'rgba(120,215,207,.14)',(W-w)/2,y-52,w,76,38);x.strokeStyle='rgba(120,215,207,.6)';x.lineWidth=2;x.stroke();x.fillStyle=TEXT;fit(x,t,w-50,40,'700',F);x.fillText(t,W/2,y);y+=96}
  // SIM1
  x.font=`700 38px ${F}`;
  if(c.sim1>0||c.beat_sim1){x.fillStyle=c.beat_sim1?GREEN:(c.pts===c.sim1?GOLD:MUTED);x.fillText(c.beat_sim1?`✓ Beat SIM1, the top bot (${c.sim1} pts)`:(c.pts===c.sim1?`Level with SIM1, the top bot`:`SIM1, the top bot, scored ${c.sim1}`),W/2,y);y+=70}
  if(c.exact_list&&c.exact_list.length){x.fillStyle=MUTED;x.font=`600 32px ${F}`;const t='Spot on: '+c.exact_list.join(' · ');fit(x,t,W-140,32,'600',F);x.fillText(t,W/2,y);}
  x.fillStyle=MUTED;x.font=`600 34px ${F}`;x.fillText('Think you can beat me? predictioncomp.com',W/2,1290);
  return await new Promise(r=>cv.toBlob(r,'image/png'));
 }
 window.shareWeekCard=async function(btn){
  const c=window.PC&&PC.weekCard;if(!c)return;
  const old=btn.textContent;btn.disabled=true;btn.textContent='Making your card…';
  try{
   const blob=await make(c);
   const url=c.place&&c.place.code?PC.inviteBase+encodeURIComponent(c.place.code):'https://predictioncomp.com/';
   const text=`I scored ${c.pts} points in Matchweek ${c.week}${c.complete?'':' so far'} on PredictionComp`+(c.place?` (${ord(c.place.rank)} in ${c.place.name})`:'')+(c.beat_sim1?' and beat SIM1':(c.pts===c.sim1?' and matched SIM1':''))+`. Think you can beat me? ${url}`;
   const file=blob&&new File([blob],`predictioncomp-mw${c.week}.png`,{type:'image/png'});
   if(file&&navigator.canShare&&navigator.canShare({files:[file]})){try{await navigator.share({files:[file],text});return}catch(e){if(e.name==='AbortError')return}}
   if(navigator.share){try{await navigator.share({text});return}catch(e){if(e.name==='AbortError')return}}
   // Desktop: download the image and copy the text.
   if(blob){const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`predictioncomp-mw${c.week}.png`;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),4000)}
   try{await navigator.clipboard.writeText(text);btn.textContent='Image saved · text copied';setTimeout(()=>{btn.textContent=old},3000);return}catch(e){}
  }finally{btn.disabled=false;if(btn.textContent==='Making your card…')btn.textContent=old}
 };
 window.PCWeekCard={make};
})();
