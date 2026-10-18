const $ = selector => document.querySelector(selector);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const rackName = rack => `10.78.${rack}.x`;
const statusText = {online:'Online',warning:'Warnung',critical:'Offline',unknown:'Nicht geprüft'};
const tone = {online:'green',warning:'amber',critical:'red',unknown:'neutral'};
const STORAGE_KEY = 'netlab-guardian-devices-v1';
const SESSION_KEY = 'netlab-guardian-session-v1';
const NOTES_KEY = 'netlab-guardian-device-notes-v1';

let currentSession = null;
try { currentSession = JSON.parse(sessionStorage.getItem(SESSION_KEY)); } catch {}
if (!currentSession || !['admin','viewer'].includes(currentSession.role)) window.location.replace('login.html');

const initialDevices = Array.from({length:24}, (_, index) => {
  const rack = Math.floor(index / 8) + 1;
  const number = index % 8 + 1;
  const router = number > 6;
  return {id:index,name:(router?'RTR':'SW')+'-0'+rack+'-0'+(router?number-6:number),rack,unit:12-number,type:router?'Router':'Switch',model:router?'Cisco ISR 4331':'Cisco Catalyst 2960',ip:`10.78.${rack}.${10+number}`,status:index===10?'critical':index===12||index===19?'warning':'online',temp:index===12?68:32+(index%9)};
});

let devices = initialDevices;
try { const saved = localStorage.getItem(STORAGE_KEY); if (saved) devices = JSON.parse(saved); } catch {}
let deviceNotes = {};
try { const savedNotes = localStorage.getItem(NOTES_KEY); if (savedNotes) deviceNotes = JSON.parse(savedNotes); } catch {}
const issues = [
  {id:10,title:'Gerät nicht erreichbar',description:'Keine Antwort auf die letzten 3 Abfragen.',action:'Stromversorgung und Netzwerkkabel prüfen. Anschließend Erreichbarkeit über das Management-Netz testen.'},
  {id:12,title:'Temperatur über Grenzwert',description:'68 °C gemessen · Grenzwert 60 °C',action:'Lüfter und Luftzufuhr prüfen. Lüftungsöffnungen freihalten und Temperatur weiter beobachten.'},
  {id:19,title:'Erhöhte Antwortzeit',description:'142 ms gemessen · Grenzwert 100 ms',action:'Port-Auslastung und Verbindungsqualität prüfen. Auf Netzwerkschleifen und ungewöhnlichen Datenverkehr achten.'}
].filter(issue => devices.some(device => device.id === issue.id && ['critical','warning'].includes(device.status)));
let activeFilter = 'all';
const badge = status => `<span class="pill ${tone[status]}-pill">${statusText[status]}</span>`;

function renderRacks() {
  $('#racks').innerHTML = [3,2,1].filter(rack => activeFilter === 'all' || devices.some(device => device.rack === rack && ['critical','warning'].includes(device.status))).map(rack => {
    const rackDevices = devices.filter(device => device.rack === rack);
    const critical = rackDevices.filter(device => device.status === 'critical').length;
    const warnings = rackDevices.filter(device => device.status === 'warning').length;
    const state = critical ? 'critical' : warnings ? 'warning' : rackDevices.some(device => device.status === 'unknown') || !rackDevices.length ? 'unknown' : 'online';
    const label = critical ? `${critical} kritisch${warnings ? ` / ${warnings} Warnung` : ''}` : warnings ? `${warnings} Warnung` : state === 'unknown' ? 'Nicht vollständig geprüft' : 'Betrieb normal';
    const slots = Array.from({length:30}, (_, index) => index+1).map(unit => {
      const device = rackDevices.find(item => item.unit === unit);
      if (!device) return `<div class="empty-unit"><span class="unit">${String(unit).padStart(2,'0')}</span><span>Frei</span></div>`;
      const noteMarker=deviceNotes[device.id]?'<span class="note-marker" title="Notiz vorhanden" aria-label="Notiz vorhanden">N</span>':'';
      return `<button class="device ${device.status} ${device.type==='Router'?'router':''} ${deviceNotes[device.id]?'has-note':''}" data-device="${device.id}" aria-label="${escapeHtml(device.name)}, ${statusText[device.status]}${deviceNotes[device.id]?', Notiz vorhanden':''}, Details öffnen"><span class="unit">${String(unit).padStart(2,'0')}</span><span class="device-name" title="${escapeHtml(device.name)}">${escapeHtml(device.name)}</span>${noteMarker}<span class="ports" aria-hidden="true">${Array.from({length:device.type==='Router'?4:16},()=>'<i class="port"></i>').join('')}</span></button>`;
    }).join('');
    return `<article class="rack-card"><div class="rack-heading"><h3>Rack ${rack} - ${rackName(rack)}</h3><span class="pill ${tone[state]}-pill">${label}</span></div><div class="rack-frame">${slots}</div><div class="rack-footer"><span><b>${rackDevices.length} Geräte</b> / ${rackDevices.filter(device=>device.type==='Switch').length} Switches / ${rackDevices.filter(device=>device.type==='Router').length} Router</span><span>${rackDevices.length} / 30 HE</span></div></article>`;
  }).join('') || '<p class="subtle">Keine Racks mit Problemen.</p>';
}

function renderTable() {
  const query = $('#search').value.trim().toLowerCase();
  const filtered = devices.filter(device => [device.name,device.ip,device.type,device.model,rackName(device.rack),statusText[device.status]].join(' ').toLowerCase().includes(query)).sort((a,b)=>b.rack-a.rack||b.unit-a.unit);
  $('#deviceTable').innerHTML = filtered.map(device => `<tr><td>${escapeHtml(device.name)}${deviceNotes[device.id]?'<span class="table-note-marker" title="Notiz vorhanden">N</span>':''}</td><td>${escapeHtml(device.model||device.type)}</td><td>${escapeHtml(device.ip)}</td><td>${rackName(device.rack)} / HE ${device.unit}</td><td>${badge(device.status)}</td><td><button class="text-button" data-device="${device.id}" aria-label="Details zu ${escapeHtml(device.name)}">Details ↗</button></td></tr>`).join('') || '<tr><td colspan="6">Keine Geräte gefunden.</td></tr>';
  $('#resultCount').textContent = `${filtered.length} von ${devices.length} Geräten`;
}

function renderIssues() {
  $('#issueList').innerHTML = issues.map(issue => {
    const device = devices.find(item => item.id === issue.id);
    if (!device) return '';
    return `<div class="issue-row"><span class="issue-icon ${device.status==='warning'?'warn':''}">!</span><div class="issue-copy"><strong>${issue.title}</strong><p>${escapeHtml(device.name)} / ${rackName(device.rack)} — ${issue.description}</p></div><button class="text-button" data-device="${device.id}">Prüfen ↗</button></div>`;
  }).join('');
}

function renderStats() {
  const count=devices.length, online=devices.filter(d=>['online','warning'].includes(d.status)).length, unknown=devices.filter(d=>d.status==='unknown').length, warnings=devices.filter(d=>d.status==='warning').length, critical=devices.filter(d=>d.status==='critical').length;
  $('.stats').innerHTML=`<div><span>Geräte insgesamt</span><strong>${count} <small>${unknown} nicht geprüft</small></strong></div><div><span>Erreichbar</span><strong>${online} <small>${count?(online/count*100).toLocaleString('de-AT',{maximumFractionDigits:1}):'0'} %</small></strong></div><div><span>Warnungen</span><strong>${warnings} <small>Prüfung empfohlen</small></strong></div><div><span>Kritisch</span><strong class="red-text">${critical} <small>Aktion erforderlich</small></strong></div>`;
  $('#heroDeviceCount').textContent=`${count} Geräte`;
  $('.nav-count').textContent=warnings+critical;
  $('#issues h2 .pill').textContent=warnings+critical;
  $('[data-filter="issues"] span').textContent=[1,2,3].filter(r=>devices.some(d=>d.rack===r&&['critical','warning'].includes(d.status))).length;
}

function openDevice(id) {
  const device=devices.find(item=>item.id===id); if(!device)return;
  const issue=issues.find(item=>item.id===id);
  $('#dialogEyebrow').textContent='GERÄTEDETAILS';
  const savedNote=deviceNotes[device.id]||'';
  $('#dialogContent').innerHTML=`<h2>${escapeHtml(device.name)}</h2><p>${badge(device.status)}</p><div class="dialog-grid">${[['Modell',device.model||device.type],['Management-IP',device.ip],['Standort',`Rack ${device.rack} - ${rackName(device.rack)} / HE ${device.unit}`],['Temperatur',['critical','unknown'].includes(device.status)?'Nicht verfügbar':`${device.temp} °C`]].map(([key,value])=>`<div><span>${key}</span><b>${escapeHtml(value)}</b></div>`).join('')}</div><div class="dialog-info">${issue?`<strong>${issue.title}</strong><br>${issue.action}`:device.status==='unknown'?'Dieses Gerät wurde noch nicht geprüft.':'Keine Probleme in den Beispieldaten erkannt.'}</div><div id="savedNotePanel" class="saved-note" ${savedNote?'':'hidden'}><div class="saved-note-head"><strong>Gespeicherte Notiz</strong><button class="text-button delete-note" id="deleteNote" type="button">Notiz löschen</button></div><p id="savedNoteText">${escapeHtml(savedNote)}</p></div><div class="notes-panel"><label for="deviceNote">Notiz ${savedNote?'bearbeiten':'hinzufügen'}</label><textarea id="deviceNote" maxlength="1000" placeholder="Notiz zu diesem Gerät hinzufügen …">${escapeHtml(savedNote)}</textarea><div class="notes-actions"><span id="noteStatus" class="subtle">Wird lokal in diesem Browser gespeichert.</span><button class="button light" id="saveNote" type="button">${savedNote?'Notiz aktualisieren':'Notiz speichern'}</button></div></div><p class="subtle">Keine Verbindung zu echten Netzwerkgeräten.</p>`;
  $('#detailDialog').showModal();
  $('#saveNote').addEventListener('click',()=>{
    const note=$('#deviceNote').value.trim();
    if(note)deviceNotes[device.id]=note;else delete deviceNotes[device.id];
    try{localStorage.setItem(NOTES_KEY,JSON.stringify(deviceNotes));$('#noteStatus').textContent=note?'Notiz gespeichert.':'Notiz entfernt.';$('#savedNotePanel').hidden=!note;$('#savedNoteText').textContent=note;$('#saveNote').textContent=note?'Notiz aktualisieren':'Notiz speichern';renderRacks();renderTable();}catch{$('#noteStatus').textContent='Notiz konnte nicht gespeichert werden.';}
  });
  $('#deleteNote').addEventListener('click',()=>{delete deviceNotes[device.id];try{localStorage.setItem(NOTES_KEY,JSON.stringify(deviceNotes));$('#savedNotePanel').hidden=true;$('#savedNoteText').textContent='';$('#deviceNote').value='';$('#saveNote').textContent='Notiz speichern';$('#noteStatus').textContent='Notiz gelöscht.';renderRacks();renderTable();}catch{$('#noteStatus').textContent='Notiz konnte nicht gelöscht werden.';}});
}

function closeOnBackdrop(event) { const rect=event.currentTarget.getBoundingClientRect(); if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)event.currentTarget.close(); }
document.addEventListener('click',event=>{
  const device=event.target.closest('[data-device]'); if(device)openDevice(Number(device.dataset.device));
  const filter=event.target.closest('[data-filter]'); if(filter){activeFilter=filter.dataset.filter;document.querySelectorAll('[data-filter]').forEach(button=>{button.classList.toggle('selected',button===filter);button.setAttribute('aria-pressed',String(button===filter));});renderRacks();}
});
$('#search').addEventListener('input',renderTable);
$('#detailDialog .close').addEventListener('click',()=>$('#detailDialog').close());
$('#detailDialog').addEventListener('click',closeOnBackdrop);
$('#userBadge').textContent=currentSession?.username||'';
$('#adminButton').hidden=currentSession?.role!=='admin';
$('#adminButton').addEventListener('click',()=>{window.location.href='admin.html';});
$('#logoutButton').addEventListener('click',()=>{sessionStorage.removeItem(SESSION_KEY);window.location.replace('login.html');});
document.querySelectorAll('nav a').forEach(link=>link.addEventListener('click',()=>document.querySelectorAll('nav a').forEach(item=>item.classList.toggle('active',item===link))));
renderRacks();renderTable();renderIssues();renderStats();
