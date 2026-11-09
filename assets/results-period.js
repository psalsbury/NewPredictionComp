// /my-results: change Season / Month / Gameweek without a full page reload.
// Fetches the same page for the new period and swaps in the period bar and the three result panels,
// keeping the open tab and the scroll position. Links still work as plain links if anything fails.
(function(){
 const PANELS=['resultsPeriod','matchesPanel','performancePanel','oddsPanel'];
 const root=document.getElementById('historyDialog');
 if(!root||!document.getElementById('resultsPeriod'))return;
 let ctrl=null;
 const currentTab=()=>{const t=root.querySelector('.resulttabs [aria-selected="true"]');return t?t.id.replace(/Tab$/,''):'matches'};
 async function load(url,push){
  if(ctrl)ctrl.abort();
  ctrl=new AbortController();
  const mine=ctrl;
  const bar=document.getElementById('resultsPeriod');
  root.classList.add('resultsloading');root.setAttribute('aria-busy','true');
  try{
   const r=await fetch(url,{credentials:'same-origin',signal:mine.signal,headers:{'Accept':'text/html'}});
   if(!r.ok)throw new Error('HTTP '+r.status);
   const doc=new DOMParser().parseFromString(await r.text(),'text/html');
   if(!doc.getElementById('resultsPeriod'))throw new Error('no picker');
   const tab=push?currentTab():(new URL(url).searchParams.get('tab')||'matches');
   for(const id of PANELS){const fresh=doc.getElementById(id),old=document.getElementById(id);if(fresh&&old)old.innerHTML=fresh.innerHTML}
   if(push)history.pushState({pcResults:1},'',url);
   if(typeof showResultsTab==='function')showResultsTab(tab);
   const title=doc.querySelector('title');if(title)document.title=title.textContent;
   // If the bar has scrolled off the top, bring it back so the new results start in view.
   const top=document.getElementById('resultsPeriod').getBoundingClientRect().top;
   if(top<0)window.scrollBy({top:top-12,behavior:'auto'});
  }catch(e){
   if(e&&e.name==='AbortError')return;
   location.href=url;return;
  }finally{
   if(ctrl===mine){root.classList.remove('resultsloading');root.removeAttribute('aria-busy');ctrl=null}
  }
 }
 root.addEventListener('click',e=>{
  const a=e.target.closest('#resultsPeriod a[data-period]');
  if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  e.preventDefault();
  if(a.classList.contains('active'))return;
  load(a.href,true);
 });
 root.addEventListener('change',e=>{
  const s=e.target.closest('#resultsPeriod select');
  if(!s||!s.value)return;
  load(new URL(s.value,location.href).href,true);
 });
 window.addEventListener('popstate',()=>{if(location.pathname==='/my-results')load(location.href,false)});
 // Mark the first entry so Back to it reloads the panels too.
 try{history.replaceState({pcResults:1},'',location.href)}catch(e){}
})();
