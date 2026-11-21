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
  report.browser = await cdp('Browser.getVersion');
  async function navigate(url) { await cdp('Page.navigate', { url }); await waitFor(async () => await js(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`)); }
  async function submit(expression, expected) {
    await js(expression);
    await waitFor(async () => await js(`document.readyState === 'complete' && (${expected})`));
  }
  async function screenshot(name) { const shot = await cdp('Page.captureScreenshot', { captureBeyondViewport: true }); await writeFile(path.join(run, name), Buffer.from(shot.data, 'base64')); }
  async function key(key, code, windowsVirtualKeyCode) {
    await cdp('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode });
    await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode });
  }
  async function openForm(name) {
    await js(`(() => { const f=document.querySelector('[name=${name}]').form;const d=f.closest('dialog');if(!d.open)document.querySelector('[aria-controls="'+d.id+'"]').click(); })()`);
    assert(await js(`document.querySelector('[name=${name}]').closest('dialog').matches(':modal')`), `Diálogo modal: ${name}`);
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
    await screenshot(`${width}-inicio.png`);
    await navigate(`${base}/?page=reportes`);
    assert(await js(`document.querySelector('nav [aria-current=page]').textContent==='Reportes' && document.querySelector('[data-kpi=periods]').textContent==='0'`), 'Reportes vacío y navegación activa');
    assert(await js(`document.body.innerText.includes('Aún no hay periodos registrados') && document.querySelectorAll('meter').length>=3 && document.documentElement.scrollWidth<=innerWidth`), 'Vacío honesto y medidores sin desbordamiento');
    await screenshot(`${width}-reportes-vacio.png`);
    await navigate(`${base}/?page=clientes`);
    const createLayout=await modalChecks('code');
    const code = `CP12-${width}`; const name = `Cliente sintético ${width}`;
    const row = { width, height: 900, code, loginLayout, createLayout, status: 'iniciado' }; report.cases.push(row);
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
    row.layout = await js(`({viewport:innerWidth,document:document.documentElement.scrollWidth,table:document.querySelector('.table').clientWidth,tableContent:document.querySelector('.table').scrollWidth,invalidControls:[...document.querySelectorAll('input:not([type=hidden]),button,select')].filter(e=>{const r=e.getBoundingClientRect();return r.left<0||r.right>innerWidth}).map(e=>e.name||e.innerText)})`);
    assert.equal(row.layout.viewport, width);
    assert(row.layout.document <= width, 'Desbordamiento de página');
    assert.deepEqual(row.layout.invalidControls, [], 'Controles fuera del viewport');
    await screenshot(`${width}-consulta.png`);
    // Negative exact-code search must not leak the previous result.
    await navigate(`${base}/?page=clientes&q=CP12-NO-EXISTE`);
    assert.equal(await js(`document.querySelectorAll('tbody tr').length`), 0);
    assert(await js(`document.body.innerText.includes('No hay registros para esta consulta.')`));
    row.missingCode = 'sin filas, mensaje de ausencia presente'; row.status = 'PASS';
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
  assert(await js(`!document.querySelector('dialog')&&document.querySelector('[name=email]').getBoundingClientRect().height>0`));
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
    await screenshot(`${width}-reportes.png`);
    await cdp('Emulation.setEmulatedMedia',{media:'print'});
    assert(await js(`getComputedStyle(document.querySelector('aside')).display==='none' && [...document.querySelectorAll('[data-band]')].every(e=>e.getBoundingClientRect().width>0)`), 'Conteos visibles para imprimir');
    await cdp('Emulation.setEmulatedMedia',{media:''});
    report.reportes.push({width,status:'PASS',clients:2,periods:4,soon:2});
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
