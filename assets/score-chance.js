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

 // Every scoreline with odds, in the score picker: tap one to make it the prediction.
 function chance(p){return p>=.095?Math.round(p*100)+'%':p>=.01?(p*100).toFixed(1)+'%':(p<.0055?'1 in 200+':oneIn(p))}
 function pickerData(){const b=pickerButtons[pickerIndex],box=b&&b.closest('[data-fixture-id]')?.querySelector('.scorechance');return box?data(box):null}
 function buildGrid(){
  let wrap=document.getElementById('pickerScores');
  if(!wrap){wrap=document.createElement('details');wrap.id='pickerScores';wrap.className='pickerscores';wrap.open=localStorage.getItem('pcScoreGrid')!=='closed';
   wrap.addEventListener('toggle',()=>localStorage.setItem('pcScoreGrid',wrap.open?'open':'closed'));
   wrap.addEventListener('click',e=>{const c=e.target.closest('button[data-score]');if(!c||pickerBusy)return;const [h,a]=c.dataset.score.split('-').map(Number);pickerValues={home:h,away:a};updatePickerControls()});
   const st=document.getElementById('pickerStatus');st.parentNode.insertBefore(wrap,st)}
  const d=pickerData();wrap.hidden=!d;if(!d)return;
  const b=pickerButtons[pickerIndex],cols=[[],[],[]];
  Object.entries(d.p).sort((x,y)=>y[1]-x[1]).forEach(([k,p])=>{const [h,a]=k.split('-').map(Number);if(h>15||a>15)return;cols[h>a?0:h===a?1:2].push([k,p])});
  const names=['Home win','Draw','Away win'],tip=['the bookies’ most likely score','the best pick for points'];
  const fav=Object.entries(d.p).sort((x,y)=>y[1]-x[1])[0][0];
  const esc=t=>String(t).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  wrap.innerHTML='<summary>All '+Object.keys(d.p).length+' scores with odds <small>tap one to pick it</small></summary><div class="psgrid">'+cols.map((c,i)=>'<div class="pscol"><div class="pshead">'+(i===0?esc(b.dataset.home):i===2?esc(b.dataset.away):'Draw')+'<small>'+(i===1?'':names[i])+' '+Math.round(100*d.r[i])+'%</small></div>'+c.map(([k,p])=>'<button type="button" data-score="'+k+'" aria-pressed="false" title="'+label(k)+': '+oneIn(p)+(k===fav?' – '+tip[0]:'')+(k===d.best?' – '+tip[1]:'')+'"><b>'+label(k)+'</b><span>'+chance(p)+'</span>'+(k===d.best?'<i aria-label="best pick for points">★</i>':'')+'</button>').join('')+'</div>').join('')+'</div><p class="psnote">★ best pick for points. Chances from bookmaker correct-score prices.</p>';
 }
 function markGrid(){
  const wrap=document.getElementById('pickerScores');if(!wrap||wrap.hidden)return;const k=pickerValues.home+'-'+pickerValues.away;let sel=null;
  wrap.querySelectorAll('button[data-score]').forEach(c=>{const on=c.dataset.score===k;c.setAttribute('aria-pressed',on);c.classList.toggle('on',on);c.disabled=pickerBusy;if(on)sel=c});
  if(sel&&wrap.open){const col=sel.closest('.pscol'),g=wrap.querySelector('.psgrid');const top=sel.offsetTop-g.offsetTop;if(top<g.scrollTop||top>g.scrollTop+g.clientHeight-sel.offsetHeight)g.scrollTop=Math.max(0,top-g.clientHeight/2)}
 }
 if(typeof window.renderScorePicker==='function'){const orig=window.renderScorePicker;window.renderScorePicker=function(){try{buildGrid()}catch(e){}const r=orig.apply(this,arguments);const w=document.getElementById('pickerScores'),st=document.getElementById('pickerStatus');if(w&&!w.hidden&&st)st.textContent='Tap a score below, or use + and −.';return r}}
 if(typeof window.updatePickerControls==='function'){const orig=window.updatePickerControls;window.updatePickerControls=function(){const r=orig.apply(this,arguments);try{markGrid()}catch(e){}return r}}
})();
