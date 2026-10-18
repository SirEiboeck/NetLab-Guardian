const SESSION_KEY = 'netlab-guardian-session-v1';
const STORAGE_KEY = 'netlab-guardian-devices-v1';
let currentSession = null;
try { currentSession = JSON.parse(sessionStorage.getItem(SESSION_KEY)); } catch {}
if (!currentSession || currentSession.role !== 'admin') window.location.replace('login.html');

const $ = selector => document.querySelector(selector);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const rackName = rack => `10.78.${rack}.x`;
const initialDevices = Array.from({length:24}, (_, index) => {
  const rack=Math.floor(index/8)+1, number=index%8+1, router=number>6;
  return {id:index,name:(router?'RTR':'SW')+'-0'+rack+'-0'+(router?number-6:number),rack,unit:12-number,type:router?'Router':'Switch',model:router?'Cisco ISR 4331':'Cisco Catalyst 2960',ip:`10.78.${rack}.${10+number}`,status:index===10?'critical':index===12||index===19?'warning':'online',temp:index===12?68:32+(index%9)};
});
let devices = initialDevices;
try { const saved=localStorage.getItem(STORAGE_KEY); if(saved)devices=JSON.parse(saved); } catch {}

function saveDevices(updated) {
  try { localStorage.setItem(STORAGE_KEY,JSON.stringify(updated)); devices=updated; return true; }
  catch { return false; }
}
function render() {
  $('#adminTable').innerHTML=devices.slice().sort((a,b)=>b.rack-a.rack||b.unit-a.unit).map(device=>`<tr><td>${escapeHtml(device.name)}</td><td>${escapeHtml(device.model||device.type)}</td><td>${escapeHtml(device.ip)}</td><td>Rack ${device.rack} - ${rackName(device.rack)}</td><td>HE ${device.unit}</td><td class="action-cell"><button class="text-button" data-edit="${device.id}" aria-label="${escapeHtml(device.name)} bearbeiten">Bearbeiten</button><button class="text-button delete-button" data-delete="${device.id}" aria-label="${escapeHtml(device.name)} löschen">Löschen</button></td></tr>`).join('')||'<tr><td colspan="6">Keine Geräte vorhanden.</td></tr>';
  $('#adminSummary').innerHTML=[3,2,1].map(rack=>`<div><span>Rack ${rack} - ${rackName(rack)}</span><strong>${devices.filter(device=>device.rack===rack).length} / 30</strong></div>`).join('');
}
function openDialog(title,content) { $('#adminDialogEyebrow').textContent=title;$('#adminDialogContent').innerHTML=content;$('#adminDialog').showModal(); }
function editDevice(id) {
  const device=devices.find(item=>item.id===id);
  openDialog('GERÄTEVERWALTUNG',`<h2>${device?'Gerät bearbeiten':'Gerät hinzufügen'}</h2><form id="deviceForm" class="device-form"><label>Gerätename<input name="name" required maxlength="48" value="${escapeHtml(device?.name||'')}" autocomplete="off"></label><div class="form-row"><label>Gerätetyp<select name="type"><option>Switch</option><option${device?.type==='Router'?' selected':''}>Router</option></select></label><label>Modell<input name="model" maxlength="80" value="${escapeHtml(device?.model||'')}"></label></div><label>IP-Adresse<input name="ip" required inputmode="decimal" placeholder="10.78.1.20" value="${escapeHtml(device?.ip||'')}"></label><div class="form-row"><label>Rack<select name="rack">${[1,2,3].map(rack=>`<option value="${rack}"${device?.rack===rack?' selected':''}>${rackName(rack)}</option>`).join('')}</select></label><label>Höheneinheit<select name="unit"></select></label></div><p class="subtle">IP-Adresse und Rack müssen zum gleichen Netzbereich gehören. Jede Höheneinheit kann einmal belegt werden.</p><p id="formError" class="form-error" role="alert"></p><div class="dialog-actions"><button type="button" class="button outline" id="cancelEdit">Abbrechen</button><button class="button light" type="submit">Speichern</button></div></form>`);
  const form=$('#deviceForm'), rackSelect=form.elements.rack, unitSelect=form.elements.unit;
  function updateUnits(){const selected=Number(unitSelect.value)||device?.unit;unitSelect.innerHTML=Array.from({length:30},(_,index)=>index+1).map(unit=>{const occupied=devices.some(item=>item.id!==device?.id&&item.rack===Number(rackSelect.value)&&item.unit===unit);return `<option value="${unit}"${occupied?' disabled':''}${!occupied&&selected===unit?' selected':''}>HE ${unit}${occupied?' (belegt)':''}</option>`;}).join('');if(!unitSelect.value||unitSelect.selectedOptions[0]?.disabled){const free=[...unitSelect.options].find(option=>!option.disabled);unitSelect.value=free?.value||'';}form.querySelector('[type="submit"]').disabled=!unitSelect.value;$('#formError').textContent=unitSelect.value?'':'Dieses Rack ist voll.';}
  rackSelect.addEventListener('change',updateUnits);updateUnits();$('#cancelEdit').addEventListener('click',()=>$('#adminDialog').close());
  form.addEventListener('submit',event=>{event.preventDefault();const name=form.elements.name.value.trim(),ip=form.elements.ip.value.trim(),rack=Number(rackSelect.value),unit=Number(unitSelect.value),parts=ip.split('.');const error=!name?'Bitte einen Gerätenamen eingeben.':devices.some(item=>item.id!==device?.id&&item.name.toLowerCase()===name.toLowerCase())?'Dieser Gerätename wird bereits verwendet.':parts.length!==4||parts.some(part=>!/^\d+$/.test(part)||String(Number(part))!==part)||parts[0]!=='10'||parts[1]!=='78'||Number(parts[2])!==rack||Number(parts[3])<1||Number(parts[3])>254?`Bitte eine gültige IP-Adresse aus ${rackName(rack)} eingeben (1–254).`:devices.some(item=>item.id!==device?.id&&item.ip===ip)?'Diese IP-Adresse wird bereits verwendet.':!unit||devices.some(item=>item.id!==device?.id&&item.rack===rack&&item.unit===unit)?'Diese Höheneinheit ist nicht verfügbar.':'';if(error){$('#formError').textContent=error;return;}const type=form.elements.type.value;const changedNetwork=device&&(device.ip!==ip||device.rack!==rack);const next={...(device||{id:Math.max(23,...devices.map(item=>item.id))+1,status:'unknown',temp:null}),name,ip,rack,unit,type,model:form.elements.model.value.trim()};if(changedNetwork){next.status='unknown';next.temp=null;}const updated=device?devices.map(item=>item.id===device.id?next:item):[...devices,next];if(!saveDevices(updated)){$('#formError').textContent='Speichern nicht möglich. Bitte lokalen Browserspeicher erlauben.';return;}render();$('#adminDialog').close();notify(device?'Änderungen gespeichert.':'Gerät hinzugefügt.');});
}
function deleteDevice(id) {
  const device=devices.find(item=>item.id===id);if(!device)return;
  openDialog('GERÄT LÖSCHEN',`<h2>Gerät entfernen?</h2><p class="dialog-info"><strong>${escapeHtml(device.name)}</strong><br>${escapeHtml(device.ip)} / ${rackName(device.rack)} / HE ${device.unit}</p><p class="subtle">Das Gerät wird aus dem lokalen Inventar entfernt. Der Platz im Rack wird frei.</p><p id="deleteError" class="form-error" role="alert"></p><div class="dialog-actions"><button class="button outline" id="cancelDelete">Abbrechen</button><button class="button danger" id="confirmDelete">Gerät löschen</button></div>`);
  $('#cancelDelete').addEventListener('click',()=>$('#adminDialog').close());
  $('#confirmDelete').addEventListener('click',()=>{if(!saveDevices(devices.filter(item=>item.id!==id))){$('#deleteError').textContent='Löschen konnte nicht gespeichert werden.';return;}render();$('#adminDialog').close();notify('Gerät entfernt.');});
}
let toastTimer;
function notify(text){$('#toast').textContent=text;$('#toast').classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>$('#toast').classList.remove('show'),3000);}
document.addEventListener('click',event=>{const edit=event.target.closest('[data-edit]');if(edit)editDevice(Number(edit.dataset.edit));const remove=event.target.closest('[data-delete]');if(remove)deleteDevice(Number(remove.dataset.delete));});
$('#addDevice').addEventListener('click',()=>editDevice());
$('#logoutButton').addEventListener('click',()=>{sessionStorage.removeItem(SESSION_KEY);window.location.replace('login.html');});
$('#adminDialog .close').addEventListener('click',()=>$('#adminDialog').close());
$('#adminDialog').addEventListener('click',event=>{const rect=event.currentTarget.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)event.currentTarget.close();});
render();
