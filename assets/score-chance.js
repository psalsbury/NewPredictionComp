// Live "your pick" chance and expected points from the stored correct-score market (see inc/score-odds.php).
(function(){
 function data(box){try{return box._cs||(box._cs=JSON.parse(box.dataset.cs))}catch(e){return null}}
 function oneIn(p){if(!p)return 'a very long shot';const n=1/p;return '1 in '+(n<10?n.toFixed(1):Math.round(n).toLocaleString())}
 function label(k){return k.replace('-','–')}
 function ev(d,h,a){const r=h>a?d.r[0]:h===a?d.r[1]:d.r[2];return 3*r+2*(d.p[h+'-'+a]||0)}
 function line(d,h,a){
  const k=h+'-'+a,p=d.p[k]||0,e=ev(d,h,a),b=ev(d,...d.best.split('-').map(Number));
  return 'Your '+h+'–'+a+': '+oneIn(p)+' · '+e.toFixed(2)+' expected pts <small>'+(k===d.best?'(the best pick)':'(best: '+label(d.best)+' '+b.toFixed(2)+')')+'</small>';
 }
 function refreshRow(row){
  const box=row&&row.querySelector('.scorechance'),ins=row&&row.querySelectorAll('.score input');
  if(!box||!ins||ins.length<2)return;const d=data(box);if(!d)return;
  const h=Number(ins[0].value),a=Number(ins[1].value);if(!Number.isFinite(h)||!Number.isFinite(a))return;
  box.querySelector('.cspick').innerHTML=line(d,h,a);
 }
 function rowFor(id){return document.querySelector('[data-fixture-id="'+Number(id)+'"]')}
 document.addEventListener('input',e=>{if(e.target.matches&&e.target.matches('.score input'))refreshRow(e.target.closest('[data-fixture-id]'))});
 if(typeof window.markMatchweekSaved==='function'){const orig=window.markMatchweekSaved;window.markMatchweekSaved=function(id){const r=orig.apply(this,arguments);refreshRow(rowFor(id));return r}}
 if(typeof window.updatePickerControls==='function'){const orig=window.updatePickerControls;window.updatePickerControls=function(){const r=orig.apply(this,arguments);
  try{let out=document.getElementById('pickerChance');if(!out){out=document.createElement('p');out.id='pickerChance';out.className='pickerchance';const odds=document.getElementById('pickerOdds');odds.parentNode.insertBefore(out,odds.nextSibling)}
   const b=pickerButtons[pickerIndex],box=b&&b.closest('[data-fixture-id]')?.querySelector('.scorechance'),d=box&&data(box);
   out.hidden=!d;if(d)out.innerHTML=line(d,pickerValues.home,pickerValues.away)}catch(e){}
  return r}}
})();
