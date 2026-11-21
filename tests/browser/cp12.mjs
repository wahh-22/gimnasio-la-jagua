// No dependencies/downloads. Node 22+ (WebSocket), PHP + pdo_sqlite, installed Chrome.
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const run = path.join(root, 'var', 'test-browser-' + new Date().toISOString().replace(/[:.]/g, '-'));
await mkdir(run, { recursive: true, mode: 0o700 });
const chromePath = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const firefoxLocations = ['/Applications/Firefox.app', '/Applications/Firefox Developer Edition.app', '/opt/homebrew/bin/firefox'];
const firefoxOnPath = spawnSync('which', ['firefox'], { encoding: 'utf8' });
const firefoxFound = [...firefoxLocations.filter(existsSync), ...(firefoxOnPath.status === 0 ? [firefoxOnPath.stdout.trim()] : [])];
const report = { started: new Date().toISOString(), run, engine: 'SQLite (solo prueba)',
  firefox: { status: firefoxFound.length ? 'detectado, no ejercitado por este runner CDP' : 'no disponible en ubicaciones comprobadas', checked: [...firefoxLocations, 'PATH (which firefox)'], found: firefoxFound }, cases: [] };
let server, chrome, socket;
const sleep = ms => new Promise(r => setTimeout(r, ms));
const env = { ...process.env, APP_DSN: `sqlite:${run}/cp12.sqlite`, APP_DB_USER: '', APP_DB_PASSWORD: '',
  APP_SESSION_PATH: `${run}/sessions`, APP_ADMIN_EMAIL: 'cp12@example.invalid', APP_ADMIN_PASSWORD: 'Synthetic-CP12-only-2026' };
async function waitFor(fn) {
  for (let i = 0; i < 150; i++) { const value = await fn().catch(() => null); if (value) return value; await sleep(100); }
  throw Error('Tiempo de espera agotado');
}
try {
  assert(existsSync(chromePath), 'Chrome no instalado');
  report.chromeVersion = spawnSync(chromePath, ['--version'], { encoding: 'utf8' }).stdout.trim();
  const setup = spawnSync('php', [path.join(root, 'bin/setup.php')], { env, encoding: 'utf8' });
  assert.equal(setup.status, 0, setup.stderr); report.setup = setup.stdout.trim();
  const port = await new Promise((resolve, reject) => {
    const probe = net.createServer(); probe.on('error', reject);
    probe.listen(0, '127.0.0.1', () => { const p = probe.address().port; probe.close(() => resolve(p)); });
  });
  assert.notEqual(port, 8080);
  const base = `http://127.0.0.1:${port}`; report.base = base;
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public')], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  let serverLog = ''; server.stdout.on('data', d => serverLog += d); server.stderr.on('data', d => serverLog += d);
  server.on('exit', () => writeFile(path.join(run, 'server.log'), serverLog));
  await waitFor(async () => (await fetch(`${base}/?page=ingreso`)).ok);
  chrome = spawn(chromePath, ['--headless=new', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--disable-sync', '--disable-breakpad', '--disable-crash-reporter',
    '--password-store=basic', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
    `--user-data-dir=${run}/profile`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
  let chromeLog = ''; chrome.stderr.on('data', d => chromeLog += d);
  const debugPort = await waitFor(async () => (await readFile(`${run}/profile/DevToolsActivePort`, 'utf8')).split('\n')[0]);
  const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  socket = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((res, rej) => { socket.addEventListener('open', res, { once: true }); socket.addEventListener('error', rej, { once: true }); });
  let id = 0; const pending = new Map();
  socket.addEventListener('message', e => { const v = JSON.parse(e.data); const p = pending.get(v.id); if (p) { pending.delete(v.id); clearTimeout(p.timer); if (v.error) p.reject(Error(JSON.stringify(v.error))); else p.resolve(v.result); } });
  function cdp(method, params = {}) { return new Promise((resolve, reject) => { const n = ++id; const timer = setTimeout(() => { pending.delete(n); reject(Error(`CDP timeout: ${method}`)); }, 10000); pending.set(n, { resolve, reject, timer }); socket.send(JSON.stringify({ id: n, method, params })); }); }
  async function js(expression) {
    const r = await cdp('Runtime.evaluate', { expression, returnByValue: true });
    assert(!r.exceptionDetails, JSON.stringify(r.exceptionDetails)); return r.result.value;
  }
  await cdp('Page.enable'); await cdp('Runtime.enable');
  await cdp('Page.bringToFront');
  report.browser = await cdp('Browser.getVersion');
  async function navigate(url) { await cdp('Page.navigate', { url }); await waitFor(async () => await js(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`)); }
  async function submit(expression, expected) {
    await js(expression);
    await waitFor(async () => await js(`document.readyState === 'complete' && (${expected})`));
  }
  async function contrastChecks() {
    const failures = await js(`(() => {
      const luminance = rgb => {const c=rgb.match(/[\\d.]+/g).slice(0,3).map(v=>{v=Number(v)/255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return c[0]*.2126+c[1]*.7152+c[2]*.0722};
      const ratio=(a,b)=>{a=luminance(a);b=luminance(b);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
      return [...document.querySelectorAll('h1,h2,h3,p,a,button,label,input:not([type=hidden]),select,th,td,dt,dd,caption,.role')].filter(e=>e.getBoundingClientRect().height&&getComputedStyle(e).visibility!=='hidden').flatMap(e=>{
        const s=getComputedStyle(e);let p=e,bg;
        while(p){bg=getComputedStyle(p).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)')break;p=p.parentElement}
        const r=ratio(s.color,bg||'rgb(255,255,255)');return r<4.5?[{element:e.tagName,text:e.textContent.trim().slice(0,45),ratio:r}]:[];
      });
    })()`);
    assert.deepEqual(failures, [], 'Texto visible con contraste mínimo 4.5:1');
  }
  async function screenshot(name) { const shot = await cdp('Page.captureScreenshot', { captureBeyondViewport: true }); await writeFile(path.join(run, name), Buffer.from(shot.data, 'base64')); }
  async function key(key, code, windowsVirtualKeyCode) {
    await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode });
  }
  async function openForm(name) {
    await js(`(() => { const f=document.querySelector('[name=${name}]').form;const d=f.closest('dialog');if(!d.open)document.querySelector('[aria-controls="'+d.id+'"]').click(); })()`);
    assert(await js(`document.querySelector('[name=${name}]').closest('dialog').matches(':modal')`), `Diálogo modal: ${name}`);
    assert(await js(`(() => {const d=document.querySelector('[name=${name}]').closest('dialog');return [...d.querySelectorAll('button'),document.querySelector('[aria-controls="'+d.id+'"]')].every(b=>!!b.querySelector('svg[aria-hidden=true] use') && !!b.textContent.trim())})()`),'S1: acciones de negocio, abrir y cancelar con iconos y etiquetas');
  }
  async function modalChecks(name) {
    await openForm(name);
    assert(await js(`(() => {const d=document.querySelector('[name=${name}]').closest('dialog');return d.contains(document.activeElement)&&!!document.getElementById(d.getAttribute('aria-labelledby')).textContent.trim()})()`), 'Título y foco dentro del diálogo');
    for(let i=0;i<14;i++) {
      await key('Tab','Tab',9);
      assert(await js(`document.querySelector('[name=${name}]').closest('dialog').contains(document.activeElement)`), 'Tab contenido en el modal');
    }
    const layout=await js(`(() => {const d=document.querySelector('[name=${name}]').closest('dialog');const r=d.getBoundingClientRect();d.scrollTop=d.scrollHeight;return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,height:innerHeight,width:innerWidth,scrollable:d.scrollHeight<=d.clientHeight||d.scrollTop>0}})()`);
    assert(layout.left>=0&&layout.right<=layout.width&&layout.top>=0&&layout.bottom<=layout.height&&layout.scrollable,'Diálogo accesible dentro del viewport, con scroll');
    await key('Escape','Escape',27);
    await waitFor(async () => await js(`!document.querySelector('[name=${name}]').closest('dialog').open && document.activeElement.hasAttribute('aria-controls')`));
    assert(await js(`(() => {const d=document.querySelector('[name=${name}]').closest('dialog');return !d.open&&document.activeElement.getAttribute('aria-controls')===d.id})()`), 'Escape cierra y devuelve foco');
    await openForm(name);
    await js(`Array.from(document.querySelector('[name=${name}]').form.querySelectorAll('button')).find(b=>b.textContent==='Cancelar').click()`);
    await waitFor(async () => await js(`!document.querySelector('[name=${name}]').closest('dialog').open && document.activeElement.hasAttribute('aria-controls')`));
    assert(await js(`(() => {const d=document.querySelector('[name=${name}]').closest('dialog');return !d.open&&document.activeElement.getAttribute('aria-controls')===d.id})()`), 'Cancelar no envía y devuelve foco');
    await openForm(name);
    return layout;
  }
  // Theme contract: default is dark even on a light OS; storage and keyboard are progressive enhancements.
  await cdp('Emulation.setEmulatedMedia', { features: [{name:'prefers-color-scheme',value:'light'}] });
  await navigate(`${base}/?page=inicio`);
  assert.equal(await js(`getComputedStyle(document.documentElement).colorScheme`), 'dark', 'Plataforma oscura por defecto');
  assert(await js(`document.querySelector('#theme-toggle').getAttribute('aria-pressed')==='true'`));
  assert(await js(`(() => {const b=document.querySelector('#theme-toggle');return !b.textContent.trim() && b.getAttribute('aria-label')==='Modo oscuro' && b.title==='Cambiar a modo claro' && !!b.querySelector('svg.theme-sun use')})()`), 'S1: tema solo iconos, estado nombrado y acción clara');
  await js(`document.querySelector('#theme-toggle').focus()`);
  await key(' ', 'Space', 32);
  assert(await js(`document.documentElement.dataset.theme==='light' && document.querySelector('#theme-toggle').getAttribute('aria-pressed')==='false'`), 'Toggle de teclado y estado accesible');
  await key('Enter','Enter',13);
  assert.equal(await js(`document.documentElement.dataset.theme`),'dark','Enter alterna el botón nativo');
  await key(' ', 'Space', 32);
  await navigate(`${base}/?page=privacidad`);
  assert.equal(await js(`document.documentElement.dataset.theme`), 'light', 'Preferencia persiste entre páginas');
  await cdp('Page.reload');
  await waitFor(async () => await js(`document.readyState==='complete' && !!document.querySelector('#theme-toggle')`));
  assert.equal(await js(`document.documentElement.dataset.theme`), 'light', 'Preferencia persiste tras recarga');
  await js(`localStorage.setItem('gimnasio-theme','invalid')`);
  await navigate(`${base}/?page=inicio`);
  assert.equal(await js(`document.documentElement.dataset.theme`), 'dark', 'Valor inválido usa oscuro');
  const denied = await cdp('Page.addScriptToEvaluateOnNewDocument', {source:`Object.defineProperty(window,'localStorage',{get(){throw new DOMException('denied','SecurityError')}})`});
  await navigate(`${base}/?page=inicio`);
  await js(`document.querySelector('#theme-toggle').click()`);
  assert.equal(await js(`document.documentElement.dataset.theme`),'light','Storage denegado no impide alternar');
  await cdp('Page.removeScriptToEvaluateOnNewDocument',{identifier:denied.identifier});
  await navigate(`${base}/?page=inicio`);
  await js(`localStorage.removeItem('gimnasio-theme')`);
  report.themePreference='PASS: oscuro predeterminado, teclado, navegación, recarga, valor inválido, storage denegado';
  await navigate(`${base}/?page=ingreso`);
  assert(await js(`!!document.querySelector('dialog[open] input[name=email]')`), 'El ingreso debe abrir un diálogo nativo inmediatamente');
  await submit(`document.querySelector('[name=email]').value='cp12@example.invalid';document.querySelector('[name=password]').value='Synthetic-CP12-only-2026';document.querySelector('form').requestSubmit()`, `location.search === '?page=inicio'`);
  for (const width of [360, 1366]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    await navigate(`${base}/?page=ingreso`);
    assert(await js(`document.querySelector('dialog').matches(':modal')`), 'Ingreso inmediato');
    const loginLayout=await modalChecks('email');
    await submit(`document.querySelector('[name=email]').value='cp12@example.invalid';document.querySelector('[name=password]').value='Synthetic-CP12-only-2026';document.querySelector('[name=email]').form.requestSubmit()`, `location.search === '?page=inicio'`);
    assert(await js(`document.querySelector('nav [aria-current=page]').textContent==='Inicio'&&document.querySelectorAll('.module').length===8`), 'Dashboard de administración y página actual');
    for(const theme of ['light','dark']) {
      await js(`document.querySelector('#theme-toggle').click()`);
      assert.equal(await js(`document.documentElement.dataset.theme`),theme,'Toggle real en móvil y escritorio');
      assert(await js(`(() => {const b=document.querySelector('#theme-toggle');const r=b.getBoundingClientRect();return b.getAttribute('aria-pressed')===String(document.documentElement.dataset.theme==='dark')&&r.height>=44&&r.left>=0&&r.right<=innerWidth})()`),'Estado y tamaño accesible del toggle');
      assert(await js(`(() => {const b=document.querySelector('#theme-toggle');const icon=b.querySelector(document.documentElement.dataset.theme==='dark'?'.theme-sun':'.theme-moon');return getComputedStyle(icon).display!=='none' && b.title===(document.documentElement.dataset.theme==='dark'?'Cambiar a modo claro':'Cambiar a modo oscuro') && [...document.querySelectorAll('nav svg,.module svg')].every(s=>getComputedStyle(s).stroke==='currentcolor' || getComputedStyle(s).stroke===getComputedStyle(s).color)})()`),'S1: sol/luna y trazos heredan contraste en ambos temas');
      await contrastChecks();
      await screenshot(`${width}-inicio-${theme}.png`);
      await navigate(`${base}/?page=privacidad`);
      assert.equal(await js(`document.documentElement.dataset.theme`),theme,'Preferencia conservada en ambos tamaños');
      await contrastChecks();
      await navigate(`${base}/?page=inicio`);
    }
    assert(await js(`(() => {const forms=document.querySelectorAll('form[action="?page=salir"]');const f=forms[0];const b=f?.querySelector('button');const r=b?.getBoundingClientRect();return forms.length===1 && !!f.closest('aside') && !f.closest('main,dialog') && f.method==='post' && !!f.querySelector('[name=csrf]')?.value && b.textContent==='Cerrar sesión' && !!b.querySelector('svg use') && r.height>=44 && r.left>=0 && r.right<=innerWidth})()`), 'S2: única salida POST/CSRF en aside, accesible en ambos tamaños');
    assert(await js(`[...document.querySelectorAll('nav a,.module')].every(a=>!!a.querySelector('svg[aria-hidden=true] use') && !!a.textContent.trim())`), 'S1: navegación y módulos con iconos decorativos y texto');
    await screenshot(`${width}-inicio.png`);
    await navigate(`${base}/?page=reportes`);
    assert(await js(`document.querySelector('nav [aria-current=page]').textContent==='Reportes' && document.querySelector('[data-kpi=periods]').textContent==='0'`), 'Reportes vacío y navegación activa');
    assert(await js(`document.body.innerText.includes('Aún no hay periodos registrados') && document.querySelectorAll('meter').length>=3 && document.documentElement.scrollWidth<=innerWidth`), 'Vacío honesto y medidores sin desbordamiento');
    await screenshot(`${width}-reportes-vacio.png`);
    await navigate(`${base}/?page=clientes`);
    const createLayout=await modalChecks('code');
    const code = `CP12-${width}`; const name = `Cliente sintético ${width}`;
    const row = { width, height: 900, code, loginLayout, createLayout, status: 'iniciado' }; report.cases.push(row);
    for (const theme of ['dark','light']) {
      await js(`document.documentElement.dataset.theme='${theme}'`);
      assert(await js(`(() => {const d=document.querySelector('dialog[open]');return getComputedStyle(d).backgroundColor===getComputedStyle(d.querySelector('input:not([type=hidden])')).backgroundColor && getComputedStyle(d).color===getComputedStyle(d.querySelector('input:not([type=hidden])')).color})()`), 'Modal y controles comparten tema');
      await contrastChecks();
      await screenshot(`${width}-modal-${theme}.png`);
    }
    await js(`document.documentElement.dataset.theme='dark'`);
    await screenshot(`${width}-crear-formulario.png`);
    if(width===360) {
      await cdp('Emulation.setDeviceMetricsOverride', { width, height: 520, deviceScaleFactor: 1, mobile: false });
      row.shortScreen=await modalChecks('code');
      await screenshot(`${width}-modal-scroll.png`);
      await cdp('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    }
    await submit(`(() => {const f=document.querySelector('input[name=code]').form;f.elements.code.value='${code}';f.elements.name.value='${name}';f.elements.evidence.value='SOPORTE-SINTETICO-${width}';f.elements.administrative.checked=true;f.requestSubmit();})()`, `!!Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes('${code}'))`);
    row.created = await js(`Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes('${code}')).innerText`);
    await screenshot(`${width}-creado.png`);
    row.searchLayout=await modalChecks('q');
    await submit(`(() => {const f=document.querySelector('input[name=q]').form;f.elements.q.value='${code}';f.requestSubmit();})()`, `location.search.includes('q=${code}') && document.querySelectorAll('tbody tr').length === 1`);
    row.consulted = await js(`document.querySelector('tbody').innerText`);
    assert(row.consulted.includes(code) && row.consulted.includes(name));
    row.layout = await js(`({viewport:innerWidth,document:document.documentElement.scrollWidth,table:document.querySelector('.table').clientWidth,tableContent:document.querySelector('.table').scrollWidth,invalidControls:[...document.querySelectorAll('input:not([type=hidden]),button,select')].filter(e=>{const r=e.getBoundingClientRect();return r.left<0||r.right>innerWidth}).map(e=>({name:e.name||e.innerText,tag:e.tagName,rect:{left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width},inClosedDetails:!!e.closest('details:not([open])')}))})`);
    assert.equal(row.layout.viewport, width);
    assert(row.layout.document <= width, 'Desbordamiento de página');
    assert.deepEqual(row.layout.invalidControls, [], 'Controles fuera del viewport');
    await screenshot(`${width}-consulta.png`);
    // Negative exact-code search must not leak the previous result.
    await navigate(`${base}/?page=clientes&q=CP12-NO-EXISTE`);
    assert.equal(await js(`document.querySelectorAll('tbody tr').length`), 0);
    assert(await js(`document.body.innerText.includes('No hay registros para esta consulta.')`));
    row.missingCode = 'sin filas, mensaje de ausencia presente'; row.status = 'PASS';
    await submit(`document.querySelector('aside form[action="?page=salir"] button').click()`, `location.search==='?page=ingreso'`);
    assert.equal(await js(`document.querySelectorAll('form[action="?page=salir"]').length`),0,'S2: salida real elimina la sesión');
    await navigate(`${base}/?page=clientes`);
    assert(!await js(`!!document.querySelector('[name=code]')`),'S2: recurso protegido no disponible tras salir');
    await navigate(`${base}/?page=ingreso`);
    await submit(`document.querySelector('[name=email]').value='cp12@example.invalid';document.querySelector('[name=password]').value='Synthetic-CP12-only-2026';document.querySelector('[name=email]').form.requestSubmit()`, `location.search==='?page=inicio'`);
  }
  // Every admin data-entry action receives a titled native dialog, including long forms.
  report.dialogPages=[];
  for (const page of ['mensualidades','autorizaciones','solicitudes','cuentas','planes']) {
    await navigate(`${base}/?page=${page}`);
    assert(await js(`document.querySelectorAll('form[data-entry]').length===document.querySelectorAll('dialog').length`));
    const field=await js(`document.querySelector('dialog input:not([type=hidden]),dialog select').name`);
    await modalChecks(field);
    report.dialogPages.push(page);
  }
  // Exercise the actual role-specific navigation and training dialogs, without demo data.
  for (const role of ['recepcion','entrenamiento']) {
    await navigate(`${base}/?page=cuentas`);
    await openForm('email');
    await submit(`(() => {const f=document.querySelector('[name=email]').form;f.elements.email.value='${role}@example.invalid';f.elements.password.value='Synthetic-role-only-2026';f.elements.role.value='${role}';f.requestSubmit()})()`, `location.search==='?page=cuentas'&&document.querySelector('tbody').innerText.includes('${role}@example.invalid')`);
  }
  report.roles=[];
  for (const width of [360,1366]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    for (const role of ['recepcion','entrenamiento']) {
      await navigate(`${base}/?page=ingreso`);
      await submit(`document.querySelector('[name=email]').value='${role}@example.invalid';document.querySelector('[name=password]').value='Synthetic-role-only-2026';document.querySelector('[name=email]').form.requestSubmit()`, `location.search==='?page=inicio'`);
      const modules=await js(`[...document.querySelectorAll('.module')].map(a=>new URL(a.href).searchParams.get('page'))`);
      assert.deepEqual(modules,role==='recepcion'?['clientes','mensualidades']:['medidas']);
      assert(!await js(`!!document.querySelector('nav a[href="?page=cuentas"]')`));
      assert(!await js(`!!document.querySelector('nav a[href="?page=reportes"]')`));
      if(role==='entrenamiento') {
        await navigate(`${base}/?page=medidas`);
        await modalChecks('measured_on');
        await key('Escape','Escape',27);
        await waitFor(async () => await js(`!document.querySelector('dialog[open]')`));
        await openForm('client_id');
        assert(await js(`document.querySelector('dialog[open] form').method==='get'`));
      }
      report.roles.push({width,role,modules,status:'PASS'});
    }
  }
  // Restore administration to check direct logout and no-JS login independently.
  await navigate(`${base}/?page=ingreso`);
  await submit(`document.querySelector('[name=email]').value='cp12@example.invalid';document.querySelector('[name=password]').value='Synthetic-CP12-only-2026';document.querySelector('[name=email]').form.requestSubmit()`, `location.search==='?page=inicio'`);
  // Direct logout remains an ordinary CSRF-protected POST, never a modal.
  await navigate(`${base}/?page=inicio`);
  assert(await js(`!document.querySelector('form[action="?page=salir"]').closest('dialog')`));
  await submit(`document.querySelector('form[action="?page=salir"]').requestSubmit()`, `location.search==='?page=ingreso'`);
  // No-JS fallback still exposes native forms and accepts the same server workflow.
  await cdp('Emulation.setScriptExecutionDisabled', { value: true });
  await navigate(`${base}/?page=ingreso`);
  assert(await js(`!document.querySelector('dialog')&&document.querySelector('[name=email]').getBoundingClientRect().height>0 && getComputedStyle(document.documentElement).colorScheme==='dark' && getComputedStyle(document.querySelector('#theme-toggle')).display==='none'`));
  await submit(`document.querySelector('[name=email]').value='cp12@example.invalid';document.querySelector('[name=password]').value='Synthetic-CP12-only-2026';document.querySelector('[name=email]').form.requestSubmit()`, `location.search==='?page=inicio'`);
  await navigate(`${base}/?page=clientes`);
  assert(await js(`!document.querySelector('dialog')&&document.querySelector('[name=code]').getBoundingClientRect().height>0&&document.querySelector('[name=q]').getBoundingClientRect().height>0`));
  report.noJavaScript='PASS: ingreso y formularios visibles';
  await cdp('Emulation.setScriptExecutionDisabled', { value: false });
  // Independent persisted-state check: only the two browser-created synthetic clients.
  const check = spawnSync('php', ['-r', `require $argv[1]; echo json_encode(database()->query('SELECT code,name FROM clients ORDER BY id')->fetchAll());`, path.join(root, 'app/bootstrap.php')], { env, encoding: 'utf8' });
  assert.equal(check.status, 0, check.stderr); report.persisted = JSON.parse(check.stdout);
  assert.deepEqual(report.persisted.map(c => c.code), ['CP12-360', 'CP12-1366']);
  // Synthetic report fixtures live only in this runner's new var/test-* database.
  const fixture = spawnSync('php', ['-r', `require $argv[1]; $m=new Model(database()); $p=$m->insert('plans',['name'=>str_repeat('Plan largo ',12),'days'=>30,'amount'=>1]); foreach([-1,0,7,8] as $o){$d=date('Y-m-d',strtotime("$o days")); $m->insert('memberships',['client_id'=>1,'plan_id'=>$p,'start_date'=>$d,'end_date'=>$d,'amount'=>1]);}`, path.join(root, 'app/bootstrap.php')], {env, encoding:'utf8'});
  assert.equal(fixture.status,0,fixture.stderr);
  report.reportes=[];
  for(const width of [360,1366]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    await navigate(`${base}/?page=reportes`);
    assert(await js(`document.querySelector('[data-kpi=clients]').textContent==='2' && document.querySelector('[data-kpi=periods]').textContent==='4' && document.querySelector('[data-kpi=soon]').textContent==='2'`));
    assert(await js(`document.documentElement.scrollWidth<=innerWidth && [...document.querySelectorAll('.report-charts .table')].every(t=>t.scrollWidth<=t.clientWidth) && [...document.querySelectorAll('meter')].every(m=>!!m.getAttribute('aria-label')) && !!document.querySelector('caption')`), 'Reportes responsive, medidores etiquetados y tablas');
    for(const theme of ['dark','light']) {
      await js(`document.documentElement.dataset.theme='${theme}'`);
      assert(await js(`getComputedStyle(document.documentElement).colorScheme==='${theme}'`));
      // Chromium does not expose native meter shadow pseudo styles through getComputedStyle.
      // Verify the authored fill/track contract here, then retain screenshots for visual review.
      assert(await js(`(() => {const s=getComputedStyle(document.querySelector('meter'));return s.getPropertyValue('--teal').trim()!==s.getPropertyValue('--soft').trim() && [...document.styleSheets].some(sheet=>[...sheet.cssRules].some(r=>r.selectorText?.includes('::-webkit-meter-optimum-value')&&r.style.background==='var(--teal)'))})()`), 'Barras con token de relleno explícito distinto del riel');
      assert(await js(`document.documentElement.scrollWidth<=innerWidth`), 'Tema sin desbordamiento');
      await contrastChecks();
      await screenshot(`${width}-reportes-${theme}.png`);
    }
    await js(`document.documentElement.dataset.theme='dark'`);
    await cdp('Emulation.setEmulatedMedia',{media:'print'});
    assert(await js(`getComputedStyle(document.documentElement).colorScheme==='light' && getComputedStyle(document.documentElement).backgroundColor==='rgb(255, 255, 255)' && getComputedStyle(document.querySelector('h1')).color==='rgb(25, 51, 59)' && getComputedStyle(document.querySelector('.report-kpis>div')).backgroundColor==='rgb(255, 255, 255)'`), 'Impresión clara incluso desde oscuro');
    await screenshot(`${width}-reportes-print.png`);
    if(width===1366) {const pdf=await cdp('Page.printToPDF',{printBackground:false});await writeFile(path.join(run,'reportes.pdf'),Buffer.from(pdf.data,'base64'));}
    assert(await js(`getComputedStyle(document.querySelector('aside')).display==='none' && [...document.querySelectorAll('[data-band]')].every(e=>e.getBoundingClientRect().width>0)`), 'Conteos visibles para imprimir');
    await cdp('Emulation.setEmulatedMedia',{media:''});
    report.reportes.push({width,status:'PASS',clients:2,periods:4,soon:2});
  }
  // Row transactions: use only this runner's isolated synthetic database.
  async function openRow(action, index=0) {
    await js(`(() => {const f=[...document.querySelectorAll('form[data-entry]')].filter(f=>f.elements.action?.value===${JSON.stringify(action)})[${index}];const d=f.closest('dialog');const b=document.querySelector('[aria-controls="'+d.id+'"]');b.focus();b.click()})()`);
    assert(await js(`document.querySelector('dialog[open]').matches(':modal') && document.querySelector('dialog[open]').contains(document.activeElement)`));
    assert(await js(`document.querySelector('dialog[open] [name=id]').type==='hidden' && !document.querySelector('input[name=id]:not([type=hidden])')`),'Identificador ligado a la fila, nunca escrito');
  }
  async function saveRow(expected) {
    await submit(`document.querySelector('dialog[open] form').requestSubmit()`,`!document.querySelector('dialog[open]') && (${expected})`);
  }
  report.rowLayouts=[];
  for(const width of [360,1366]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    for(const page of ['clientes','planes','mensualidades','autorizaciones','solicitudes','cuentas']) {
      await navigate(`${base}/?page=${page}`);
      const layout=await js(`(() => {const t=document.querySelector('.row-records');return {width:innerWidth,table:t?{width:t.clientWidth,content:t.scrollWidth}:null,controls:[...document.querySelectorAll('.row-actions button')].map(e=>{const r=e.getBoundingClientRect();return {name:e.textContent.trim(),left:r.left,right:r.right,height:r.height}})}})()`);
      assert(layout.controls.every(r=>r.left>=0&&r.right<=width&&r.height>=44),'Todas las acciones directas caben y tienen objetivos táctiles de 44px');
      if(width===360 && layout.table) assert(layout.table.content<=layout.table.width,'Registros móviles completos sin scroll horizontal');
      report.rowLayouts.push({page,...layout});
      if(page==='clientes'||(width===360&&page==='mensualidades')) await screenshot(`${width}-${page}-acciones.png`);
    }
  }
  report.rowActions=[];
  for(const width of [360,1366]) {
    await cdp('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    await navigate(`${base}/?page=clientes`);
    assert(await js(`!![...document.querySelectorAll('th')].find(h=>h.textContent==='Acciones')`));
    assert(await js(`!document.querySelector('.row-actions summary,.row-actions details') && [...document.querySelectorAll('.row-actions button')].every(b=>!b.textContent.trim() && b.getAttribute('aria-label').includes('Registro #') && !!b.querySelector('svg[aria-hidden=true] use'))`),'Acciones directas solo icono, nombre asociado a la fila, sin menú');
    await js(`document.querySelector('.row-icon-action').scrollIntoView({block:'center'})`);
    await sleep(150);
    await js(`document.querySelector('.row-icon-action').focus()`);
    const tooltip=await js(`(() => {const b=document.activeElement,t=document.getElementById(b.getAttribute('aria-describedby')),r=t.getBoundingClientRect();return {body:t.parentElement===document.body,role:t.role,hidden:t.hidden,text:t.textContent,left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:innerWidth,height:innerHeight}})()`);
    assert(tooltip.body && tooltip.role==='tooltip' && !tooltip.hidden && tooltip.text.includes('cliente') && tooltip.left>=0 && tooltip.right<=tooltip.width && tooltip.top>=0 && tooltip.bottom<=tooltip.height,`Tooltip visible con foco: ${JSON.stringify(tooltip)}`);
    await key('Escape','Escape',27);
    assert(await js(`document.getElementById(document.activeElement.getAttribute('aria-describedby')).hidden && !document.querySelector('dialog[open]')`),'Escape oculta tooltip sin abrir diálogo');
    const point=await js(`(() => {document.activeElement.blur();const r=document.querySelector('.row-icon-action').getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()`);
    await cdp('Input.dispatchMouseEvent',{type:'mouseMoved',...point});
    assert(await js(`!document.getElementById(document.querySelector('.row-icon-action').getAttribute('aria-describedby')).hidden`),'Tooltip visible con hover real');
    await cdp('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
    await js(`document.querySelector('.row-icon-action').focus()`);
    await key('Enter','Enter',13);
    assert(await js(`document.querySelector('dialog[open]').matches(':modal')`),'Acción directa por teclado abre modal');
    for(let i=0;i<8;i++) {
      await key('Tab','Tab',9);
      assert(await js(`document.querySelector('dialog[open]').contains(document.activeElement)`),'Tab permanece en la edición de fila');
    }
    assert(await js(`document.querySelector('dialog[open] [name=name]').value.includes('Cliente') && !document.querySelector('dialog[open] [name=code]')`),'Nombre precargado y código inmutable');
    await key('Escape','Escape',27);
    assert(await js(`!document.querySelector('dialog[open]') && document.activeElement.hasAttribute('aria-controls')`),'Foco devuelto a la acción de fila');
    if(width===360) {
      await cdp('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[point]});
      await cdp('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
      assert(await js(`document.querySelector('dialog[open]').matches(':modal')`),'Un toque abre directamente el modal existente');
      await key('Escape','Escape',27);
    }
    await cdp('Emulation.setEmulatedMedia',{media:'print'});
    assert(await js(`getComputedStyle(document.querySelector('.row-actions')).display==='none' && [...document.querySelectorAll('.row-tooltip')].every(t=>getComputedStyle(t).display==='none')`),'Impresión sin acciones ni tooltips');
    await cdp('Emulation.setEmulatedMedia',{media:''});
    await openRow('archivar');
    assert(await js(`!document.querySelector('dialog[open] form').checkValidity() && document.querySelector('dialog[open] .row-context').textContent.includes('CP12-')`),'Confirmación obligatoria con identidad visible');
    await key('Escape','Escape',27);
    assert(await js(`document.querySelectorAll('tbody tr').length===2`),'Cancelar archivo conserva las filas');
    report.rowActions.push({width,status:'PASS',checks:'prefill, hidden ID, confirmation, Escape/focus'});
  }
  await openRow('editar');
  const editedName='Cliente <img src=x onerror=alert(1)> & sintético';
  await js(`document.querySelector('dialog[open] [name=name]').value=${JSON.stringify(editedName)}`);
  await saveRow(`document.querySelector('tbody').textContent.includes('onerror=alert(1)')`);
  assert(await js(`!document.querySelector('tbody img') && !document.querySelector('.row-context img')`),'Datos escapados en tabla y formularios');
  await openRow('archivar');
  const archivedID=await js(`document.querySelector('dialog[open] [name=id]').value`);
  await js(`document.querySelector('dialog[open] [name=confirmar]').checked=true`);
  await saveRow(`document.querySelectorAll('tbody tr').length===1`);
  await navigate(`${base}/?page=clientes&archivados=1`);
  assert(await js(`document.querySelectorAll('tbody tr').length===1 && ![...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='editar')`));
  await openRow('restaurar');
  assert.equal(await js(`document.querySelector('dialog[open] [name=id]').value`),archivedID);
  await saveRow(`location.search==='?page=clientes' && document.querySelectorAll('tbody tr').length===2`);
  await navigate(`${base}/?page=planes`);
  await openRow('editar');
  assert(await js(`document.querySelector('dialog[open] [name=days]').value==='30' && Number(document.querySelector('dialog[open] [name=amount]').value)===50000`));
  await js(`document.querySelector('dialog[open] [name=name]').value='Plan actualizado'`);
  await saveRow(`document.querySelector('tbody').textContent.includes('Plan actualizado')`);
  await navigate(`${base}/?page=mensualidades`);
  assert(await js(`![...document.querySelectorAll('th')].some(h=>['Plan_id','ID del cliente'].includes(h.textContent))`),'IDs relacionales fuera de la tabla');
  await openRow('editar');
  assert(await js(`(() => {const f=document.querySelector('dialog[open] form');return f.elements.client_id.type==='hidden' && !!f.elements.plan_id.value && !!f.elements.start_date.value && !!f.elements.end_date.value && f.elements.amount.value==='1' && !f.elements.confirm_overlap.checked})()`),'Periodo precargado y cliente fijo');
  await js(`document.querySelector('dialog[open] [name=amount]').value='2'`);
  await saveRow(`[...document.querySelectorAll('tbody tr')].some(r=>r.textContent.includes('2')) && !document.querySelector('[role=alert]')`);
  // Archive/restore is available on both plans and monthly periods, never delete.
  for(const page of ['planes','mensualidades']) {
    await navigate(`${base}/?page=${page}`);
    await openRow('archivar');
    const id=await js(`document.querySelector('dialog[open] [name=id]').value`);
    await js(`document.querySelector('dialog[open] [name=confirmar]').checked=true`);
    await saveRow(`![...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='archivar' && f.elements.id.value===${JSON.stringify(id)})`);
    await navigate(`${base}/?page=${page}&archivados=1`);
    await openRow('restaurar');
    assert.equal(await js(`document.querySelector('dialog[open] [name=id]').value`),id);
    await saveRow(`location.search===${JSON.stringify('?page='+page)}`);
  }
  await navigate(`${base}/?page=autorizaciones`);
  await openRow('retirar');
  const withdrawnID=await js(`document.querySelector('dialog[open] [name=id]').value`);
  assert(await js(`document.querySelector('dialog[open] [name=client_id]').type==='hidden' && document.querySelector('dialog[open]').textContent.includes('irreversible')`));
  await saveRow(`![...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='retirar' && f.elements.id.value===${JSON.stringify(withdrawnID)})`);
  await navigate(`${base}/?page=solicitudes`);
  await openForm('kind');
  await submit(`document.querySelector('dialog[open] form').requestSubmit()`,`!!document.querySelector('tbody tr')`);
  await openRow('resolver');
  assert(await js(`document.querySelector('dialog[open] [name=client_id]').type==='hidden'`));
  await js(`document.querySelector('dialog[open] [name=resolution]').value='Consulta sintética atendida'`);
  await saveRow(`document.querySelector('tbody').textContent.includes('Consulta sintética atendida')`);
  assert(!await js(`[...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='resolver')`));
  await navigate(`${base}/?page=cuentas`);
  assert(await js(`![...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='permiso' && f.querySelector('.row-context').textContent.includes('cp12@example.invalid'))`),'No se ofrece cambiar el propio acceso');
  await openRow('permiso');
  assert(await js(`!!document.querySelector('dialog[open] [name=role]').value && document.querySelector('dialog[open] [name=active]').value==='1'`));
  await js(`document.querySelector('dialog[open] [name=active]').value='0'`);
  await saveRow(`!document.querySelector('[role=alert]')`);
  await openRow('permiso');
  assert(await js(`document.querySelector('dialog[open] [name=active]').value==='0'`),'Bloqueo ligado a la cuenta seleccionada');
  await js(`document.querySelector('dialog[open] [name=active]').value='1'`);
  await saveRow(`!document.querySelector('[role=alert]')`);
  for(const page of ['bitacora','reportes']) {
    await navigate(`${base}/?page=${page}`);
    assert(!await js(`!!document.querySelector('.row-actions')`),'Registros inmutables sin acciones de mutación');
  }
  // The same selected-row edit remains functional with scripting disabled.
  await cdp('Emulation.setScriptExecutionDisabled',{value:true});
  await navigate(`${base}/?page=clientes`);
  assert(await js(`!document.querySelector('dialog') && !!document.querySelector('.row-actions form [name=id][type=hidden]')`));
  await cdp('Emulation.setDeviceMetricsOverride',{width:360,height:900,deviceScaleFactor:1,mobile:false});
  const noJSLayout=await js(`(() => {const d=document.querySelector('.row-actions');return [...d.querySelectorAll('input:not([type=hidden]),select,button')].map(e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right}})})()`);
  assert(noJSLayout.length>0&&noJSLayout.every(r=>r.left>=0&&r.right<=360),'Formularios de fila sin JS caben en móvil');
  await screenshot('360-acciones-sin-js.png');
  await submit(`(() => {const f=[...document.querySelectorAll('.row-actions form')].find(f=>f.elements.action.value==='editar');f.elements.contact.value='Contacto sin JS';f.requestSubmit()})()`,`document.querySelector('tbody').textContent.includes('Contacto sin JS')`);
  await cdp('Emulation.setScriptExecutionDisabled',{value:false});
  await navigate(`${base}/?page=ingreso`);
  await submit(`document.querySelector('[name=email]').value='recepcion@example.invalid';document.querySelector('[name=password]').value='Synthetic-role-only-2026';document.querySelector('[name=email]').form.requestSubmit()`,`location.search==='?page=inicio'`);
  for(const page of ['clientes','mensualidades']) {
    await navigate(`${base}/?page=${page}`);
    assert(await js(`[...document.querySelectorAll('form[data-entry]')].some(f=>f.elements.action?.value==='editar') && ![...document.querySelectorAll('form[data-entry]')].some(f=>['archivar','restaurar'].includes(f.elements.action?.value))`),'Recepción edita sin archivo/restauración');
  }
  report.status = 'PASS Chrome; Firefox pendiente';
  await writeFile(path.join(run, 'chrome.log'), chromeLog);
} catch (e) { report.status = 'FAIL'; report.error = e.stack; process.exitCode = 1; }
finally {
  socket?.close(); chrome?.kill('SIGTERM'); server?.kill('SIGTERM');
  report.finished = new Date().toISOString();
  await writeFile(path.join(run, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify(report, null, 2));
}
