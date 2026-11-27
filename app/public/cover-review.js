(() => {
  document.querySelectorAll('.cover-decision[data-match]').forEach(form=>{
    form.addEventListener('change',event=>{
      if(event.target.name==='target')document.getElementById('bulk-target-'+form.dataset.match).value=event.target.value;
    });
  });
  document.getElementById('cover-bulk')?.addEventListener('submit',()=>{
    document.querySelectorAll('.cover-decision[data-match]').forEach(form=>{
      const selected=form.querySelector('input[name="target"]:checked');
      if(selected)document.getElementById('bulk-target-'+form.dataset.match).value=selected.value;
    });
  });
  const box=document.getElementById('cover-progress'); if(!box)return;
  let running=box.dataset.running==='1'; let timer;
  const poll=async()=>{
    if(document.hidden){timer=setTimeout(poll,3000);return;}
    try{
      const response=await fetch(box.dataset.url,{headers:{Accept:'application/json'},cache:'no-store'});
      if(response.status===401||response.status===403)return;
      if(!response.ok)throw new Error();
      const {scan,pending}=await response.json();
      if(scan){
        const active=['queued','running'].includes(scan.status);
        box.replaceChildren();
        const title=document.createElement('strong');title.textContent='Última comparación: '+({queued:'En cola',running:'En ejecución',success:'Completada',failed:'Interrumpida'}[scan.status]||scan.status);
        const counts=document.createElement('p');counts.textContent=`${scan.processed} / ${scan.total_images} imágenes revisadas · ${Number(scan.compared_pairs)+Number(scan.skipped_pairs)} / ${scan.total_pairs} pares resueltos · ${scan.batch_count} lotes completados · ${scan.suggestions} propuestas nuevas · ${scan.errors} imágenes no utilizables.`;
        const message=document.createElement('small');message.textContent=scan.message||'';box.append(title,counts,message);
        if((running&&!active)||pending>document.querySelectorAll('.cover-match').length)document.getElementById('cover-refresh').hidden=false;
        running=active;
      }
    }catch(_){/* Keep the last observed progress and retry. */}
    timer=setTimeout(poll,running?3000:15000);
  };
  timer=setTimeout(poll,3000);window.addEventListener('pagehide',()=>clearTimeout(timer));
})();
