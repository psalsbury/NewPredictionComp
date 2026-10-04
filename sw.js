const CACHE='predictioncomp-app-v1';
const ASSETS=['/offline.html','/assets/app/icon-192.png','/assets/app/icon-512.png','/assets/app/icon-180.png'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('predictioncomp-app-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 const req=e.request,url=new URL(req.url);
 if(req.method!=='GET'||url.origin!==self.location.origin)return;
 if(req.mode==='navigate'){e.respondWith(fetch(req).catch(()=>caches.match('/offline.html')));return;}
 if(ASSETS.includes(url.pathname)&&!url.search)e.respondWith(caches.match(req).then(cached=>cached||fetch(req)));
});
