(()=>{
 if('serviceWorker' in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js',{scope:'/',updateViaCache:'none'}).catch(()=>{}));
 let installPrompt=null;
 const standalone=()=>window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
 function links(){return document.querySelectorAll('[data-install-app]')}
 function update(){links().forEach(a=>a.hidden=standalone())}
 window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installPrompt=e;update()});
 window.addEventListener('appinstalled',()=>{installPrompt=null;links().forEach(a=>a.hidden=true)});
 document.addEventListener('DOMContentLoaded',()=>{
  const nav=document.querySelector('footer nav');
  if(nav){const a=document.createElement('a');a.href='/install';a.textContent='Install app';a.dataset.installApp='';nav.appendChild(a)}
  update();
  links().forEach(a=>a.addEventListener('click',async e=>{
   if(!installPrompt)return;
   e.preventDefault();const prompt=installPrompt;installPrompt=null;
   try{await prompt.prompt();await prompt.userChoice}catch(error){location.href='/install'}
  }));
 });
})();
