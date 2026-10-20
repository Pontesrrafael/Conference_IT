'use strict';
(() => {
 const svg=document.getElementById('map-canvas');if(!svg)return;
 const w=Number(svg.dataset.width),h=Number(svg.dataset.height),draft=document.getElementById('map-draft'),input=document.getElementById('map-points'),counter=document.getElementById('map-points-count'),save=document.getElementById('map-area-save'),cursor=document.getElementById('map-cursor');
 let points=input?JSON.parse(input.value):[],mode='nav',view=[0,0,w,h],drag=null,moved=false,keyPoint=[w/2,h/2];
 const ns='http://www.w3.org/2000/svg';
 function render(){draft.replaceChildren();if(points.length){const poly=document.createElementNS(ns,'polygon');poly.setAttribute('points',points.map(p=>`${p[0]*w},${p[1]*h}`).join(' '));poly.setAttribute('class','map-draft-poly');draft.append(poly);for(const p of points){const c=document.createElementNS(ns,'circle');c.setAttribute('cx',p[0]*w);c.setAttribute('cy',p[1]*h);c.setAttribute('r',Math.max(w,h)/250);c.setAttribute('class','map-draft-point');draft.append(c);}}if(input)input.value=JSON.stringify(points);if(counter)counter.textContent=`${points.length} vértices · mínimo 3, máximo 100. Salve a área após conferir o contorno.`;if(save)save.disabled=points.length<3;}
 function setMode(m){mode=m;svg.classList.toggle('drawing',m==='draw');for(const id of ['nav','draw'])document.getElementById('map-'+id)?.setAttribute('aria-pressed',String(id===m));cursor.setAttribute('visibility',m==='draw'?'visible':'hidden');}
 function point(e){const ctm=svg.getScreenCTM();if(!ctm)return null;const p=new DOMPoint(e.clientX,e.clientY).matrixTransform(ctm.inverse());return p.x>=0&&p.y>=0&&p.x<=w&&p.y<=h?[p.x,p.y]:null;}
 function updateView(){view[0]=Math.max(0,Math.min(w-view[2],view[0]));view[1]=Math.max(0,Math.min(h-view[3],view[1]));svg.setAttribute('viewBox',view.join(' '));}
 function zoom(f){const nw=Math.min(w,Math.max(w/16,view[2]*f)),nh=nw*h/w;view=[view[0]+(view[2]-nw)/2,view[1]+(view[3]-nh)/2,nw,nh];updateView();}
 function add(p){if(points.length>=100)return;points.push([Number((p[0]/w).toFixed(8)),Number((p[1]/h).toFixed(8))]);render();}
 document.getElementById('map-nav').addEventListener('click',()=>setMode('nav'));
 document.getElementById('map-draw')?.addEventListener('click',()=>{setMode('draw');svg.focus();});
 document.getElementById('map-undo')?.addEventListener('click',()=>{points.pop();render();});
 document.getElementById('map-clear')?.addEventListener('click',()=>{points=[];render();});
 document.getElementById('map-zoom-in').addEventListener('click',()=>zoom(.7));document.getElementById('map-zoom-out').addEventListener('click',()=>zoom(1/.7));document.getElementById('map-fit').addEventListener('click',()=>{view=[0,0,w,h];updateView();});
 svg.addEventListener('pointerdown',e=>{moved=false;if(mode==='nav'){drag={x:e.clientX,y:e.clientY,view:[...view],ctm:svg.getScreenCTM()};}});
 svg.addEventListener('pointermove',e=>{const p=point(e);if(p){keyPoint=p;cursor.setAttribute('cx',p[0]);cursor.setAttribute('cy',p[1]);const lon=Number(svg.dataset.west)+p[0]/w*(Number(svg.dataset.east)-Number(svg.dataset.west)),lat=Number(svg.dataset.north)-p[1]/h*(Number(svg.dataset.north)-Number(svg.dataset.south));document.getElementById('map-coordinate').textContent=`Latitude ${lat.toFixed(7)} · Longitude ${lon.toFixed(7)} (WGS84)`;}if(drag&&e.buttons){const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.hypot(dx,dy)>4)moved=true;view=[drag.view[0]-dx/drag.ctm.a,drag.view[1]-dy/drag.ctm.d,view[2],view[3]];updateView();}});
 window.addEventListener('pointerup',()=>{drag=null;});svg.addEventListener('pointerleave',()=>{drag=null;});
 svg.addEventListener('click',e=>{if(mode==='draw'||moved){e.preventDefault();e.stopPropagation();if(mode==='draw'){const p=point(e);if(p)add(p);}moved=false;}},true);
 svg.addEventListener('keydown',e=>{if(mode!=='draw')return;const step=view[2]/50;if(e.key==='ArrowLeft')keyPoint[0]=Math.max(0,keyPoint[0]-step);else if(e.key==='ArrowRight')keyPoint[0]=Math.min(w,keyPoint[0]+step);else if(e.key==='ArrowUp')keyPoint[1]=Math.max(0,keyPoint[1]-step);else if(e.key==='ArrowDown')keyPoint[1]=Math.min(h,keyPoint[1]+step);else if(e.key==='Enter'||e.key===' '){add(keyPoint);}else if(e.key==='Backspace'){points.pop();render();}else return;e.preventDefault();cursor.setAttribute('cx',keyPoint[0]);cursor.setAttribute('cy',keyPoint[1]);});
 document.getElementById('map-area-form')?.addEventListener('submit',e=>{if(points.length<3){e.preventDefault();counter.textContent='Desenhe pelo menos três vértices na imagem.';}});
 render();
})();
