(() => {
  'use strict';
  window.FansCreateMedia = class {
    constructor(dialog, back) {
      this.root=dialog;this.back=back;this.selected=null;this.urls=new Set();this.reads=new AbortController();this.busy=false;
      this.list=dialog.querySelector('[data-create-image-list]');this.status=dialog.querySelector('[data-create-image-status]');
      dialog.querySelector('[data-create-image-refresh]').addEventListener('click',()=>this.load());
      dialog.querySelector('[data-create-image-none]').addEventListener('click',()=>this.select(null));
      dialog.querySelector('[data-create-upload]').addEventListener('submit',event=>this.upload(event));
      dialog.querySelector('.fu-author__form')?.addEventListener('submit',event=>this.submit(event));
      document.addEventListener('visibilitychange',()=>{if(document.hidden)this.clear();});
      window.addEventListener('pagehide',()=>this.clear());
      document.addEventListener('fans-session-ended',()=>this.clear());
    }
    endpoint(path) {
      const url=new URL(path,this.root.dataset.api);
      if(url.origin!==location.origin)throw new Error('origin');return url;
    }
    async request(path,options={}) {
      const response=await fetch(this.endpoint(path),{credentials:'same-origin',cache:'no-store',redirect:'error',...options,headers:{'X-WP-Nonce':this.root.dataset.nonce,...options.headers}});
      if(!response.ok)throw new Error('unavailable');return response;
    }
    clear() {this.reads.abort();this.reads=new AbortController();this.urls.forEach(url=>URL.revokeObjectURL(url));this.urls.clear();this.list.replaceChildren();}
    valid(row) {return row && /^[0-9a-f-]{36}$/.test(row.image_id) && /^[1-9][0-9]{0,9}$/.test(String(row.revision)) && Number(row.revision)<2147483647 && ['pending','approved'].includes(row.state);}
    async load() {
      this.clear();const signal=this.reads.signal;this.status.textContent='Chargement de vos images privées…';
      try {
        const data=await (await this.request('images?scope=live',{signal})).json();
        if(signal.aborted)return;
        if(!Array.isArray(data.items)||data.items.length>20||!data.items.every(row=>this.valid(row)))throw new Error('invalid');
        this.status.textContent=data.items.length?'Seules les images approuvées peuvent être sélectionnées.':'Aucune image conservée pour le moment.';
        data.items.forEach((row,index)=>{
          const card=document.createElement('article');card.className='fu-create__image';
          const title=document.createElement('p');title.textContent=`Image ${index+1} · ${row.state==='approved'?'Approuvée':'En attente de modération'}`;card.append(title);
          const preview=document.createElement('button');preview.type='button';preview.textContent='Examiner en privé';
          const output=document.createElement('div');preview.addEventListener('click',()=>this.preview(row,output,preview));card.append(preview,output);
          if(row.state==='approved'){const choose=document.createElement('button');choose.type='button';choose.textContent='Sélectionner cette image';choose.addEventListener('click',()=>this.select(row));card.append(choose);}
          this.list.append(card);
        });
      }catch(error){if(!signal.aborted)this.status.textContent='Images indisponibles. Votre brouillon est conservé ; réessayez après vérification de votre session.';}
    }
    async preview(row,output,button) {
      const signal=this.reads.signal;button.disabled=true;let objectUrl=null;
      try {
        const response=await this.request(`images/${row.image_id}/preview/${row.revision}`,{signal});
        if(response.headers.get('Content-Type')?.split(';')[0].trim()!=='image/jpeg'||!response.body)throw new Error('type');
        const reader=response.body.getReader(),chunks=[];let size=0;
        try {while(true){const part=await reader.read();if(part.done)break;size+=part.value.byteLength;if(size>2097152)throw new Error('size');chunks.push(part.value);}}finally{await reader.cancel();}
        if(signal.aborted)return;
        objectUrl=URL.createObjectURL(new Blob(chunks,{type:'image/jpeg'}));this.urls.add(objectUrl);
        const img=document.createElement('img');img.alt='Aperçu privé de votre image';img.src=objectUrl;await img.decode();
        if(signal.aborted||!output.isConnected)return;
        if(img.naturalWidth>1280||img.naturalHeight>1280)throw new Error('dimensions');output.replaceChildren(img);objectUrl=null;
      }catch(error){if(!signal.aborted)output.textContent='Aperçu indisponible. Actualisez les images.';}
      finally {if(objectUrl){URL.revokeObjectURL(objectUrl);this.urls.delete(objectUrl);}button.disabled=false;}
    }
    select(row) {
      this.selected=row;this.root.querySelector('[data-create-selection]').textContent=row?'Une image approuvée est sélectionnée.':'Aucune image sélectionnée.';this.back();
    }
    async upload(event) {
      event.preventDefault();if(this.busy)return;
      const file=event.target.querySelector('input[type=file]').files[0];
      if(!file||file.size>2097152||!['image/jpeg','image/png'].includes(file.type)){this.status.textContent='Choisissez un JPEG ou PNG de 2 Mio maximum.';return;}
      this.busy=true;const button=event.target.querySelector('button');button.disabled=true;this.status.textContent='Dépôt privé en cours…';
      try {const body=new FormData();body.append('image',file);await this.request('images',{method:'POST',body});event.target.reset();await this.load();}
      catch(error){this.status.textContent='Dépôt non confirmé. Actualisez la galerie avant de réessayer ; votre texte est conservé.';}
      finally{this.busy=false;button.disabled=false;}
    }
    async submit(event) {
      if(!this.selected)return;event.preventDefault();if(this.busy)return;
      this.busy=true;const form=event.target,button=form.querySelector('[type=submit]'),text=form.elements.text.value,key=form.elements.creation_key.value,image=this.selected,status=this.root.querySelector('[data-create-result]');
      button.disabled=true;form.elements.text.readOnly=true;status.replaceChildren(document.createTextNode('Soumission en cours…'));
      let publication=null;
      const post=(path,body,headers={})=>this.request(path,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify(body)}).then(r=>r.json());
      try {
        publication=await post('text-publications',{text,category:'hosted_allowed_content'},{'Idempotency-Key':key});
        if(!publication||!/^[0-9a-f-]{36}$/.test(publication.publication_id)||!Number.isSafeInteger(Number(publication.revision)))throw new Error('invalid');
        await post(`text-publications/${publication.publication_id}/image`,{revision:Number(publication.revision),image_id:image.image_id,image_revision:Number(image.revision)});
        status.textContent='Publication et image soumises à la modération.';
      }catch(error){status.textContent=publication?'Texte enregistré. Association de l’image non confirmée : vérifiez la publication avant une nouvelle action.':'Soumission non confirmée. Vérifiez vos publications avant de renvoyer ; le texte de ce brouillon est conservé.';}
      finally {
        // Two existing transactions: never claim that an uncertain create/association was rolled back.
        const link=document.createElement('a');link.textContent=' Ouvrir mes publications';link.href=new URL(form.action).pathname+(publication?.publication_id?'?publication='+encodeURIComponent(publication.publication_id):'');status.append(link);this.busy=false;
        // Keep the same submission locked against accidental duplicate creation after an uncertain response.
      }
    }
  };
})();
