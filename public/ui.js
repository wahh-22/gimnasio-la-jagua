'use strict';
// Run in the head before CSS paints: dark is the product default, not an OS preference.
(() => {
  const root = document.documentElement;
  let theme = 'dark';
  try {
    const saved = localStorage.getItem('gimnasio-theme');
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch { /* Storage can be denied; the page and toggle still work. */ }
  root.dataset.theme = theme;
  document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('theme-toggle');
    if (!toggle) return;
    const apply = value => {
      root.dataset.theme = value;
      toggle.setAttribute('aria-pressed', String(value === 'dark'));
      // Stable toggle name describes the pressed state; tooltip describes the next action.
      toggle.title = value === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
    };
    apply(theme);
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
      theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      apply(theme);
      try { localStorage.setItem('gimnasio-theme', theme); } catch { /* Session-only fallback. */ }
    });
    window.addEventListener('storage', event => {
      if (event.key === 'gimnasio-theme' || event.key === null) {
        theme = event.newValue === 'light' ? 'light' : 'dark';
        apply(theme);
      }
    });
  });
})();
// First-party sprite shared with the PHP-rendered controls (no icon font or runtime dependency).
function actionIcon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('icon');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  svg.setAttribute('width', '24');
  svg.setAttribute('height', '24');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', `/icons.svg?v=fila-20261121-1#${name}`);
  svg.append(use);
  return svg;
}
// A body-level fixed tooltip escapes table overflow. It is hoverable, focusable by
// its trigger, and dismissible with Escape without opening or submitting anything.
function attachRowTooltip(trigger, text, id) {
  const tip = document.createElement('span');
  tip.id = id;
  tip.className = 'row-tooltip';
  tip.setAttribute('role', 'tooltip');
  tip.textContent = text;
  tip.hidden = true;
  document.body.append(tip);
  trigger.setAttribute('aria-describedby', id);
  let timer;
  const hide = () => { clearTimeout(timer); tip.hidden = true; };
  const show = () => {
    clearTimeout(timer);
    tip.hidden = false;
    const r = trigger.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    tip.style.left = `${Math.max(8, Math.min(innerWidth - t.width - 8, r.left + (r.width - t.width) / 2))}px`;
    tip.style.top = `${r.top >= t.height + 8 ? r.top - t.height - 4 : r.bottom + 4}px`;
  };
  const deferHide = () => { timer = setTimeout(() => {
    if (document.activeElement !== trigger && !tip.matches(':hover') && !trigger.matches(':hover')) hide();
  }, 120); };
  trigger.addEventListener('mouseenter', show);
  trigger.addEventListener('mouseleave', deferHide);
  trigger.addEventListener('focus', show);
  trigger.addEventListener('blur', hide);
  trigger.addEventListener('click', hide);
  tip.addEventListener('mouseenter', () => clearTimeout(timer));
  tip.addEventListener('mouseleave', deferHide);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
  const reposition = () => { if (!tip.hidden) show(); };
  window.addEventListener('scroll', reposition, true);
  window.addEventListener('resize', reposition);
}
// Progressive enhancement: server-rendered forms remain usable without JavaScript.
document.addEventListener('DOMContentLoaded', () => {
  if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
  const page = new URLSearchParams(location.search).get('page') || 'inicio';
  const titles = {
    ingreso: { crear: 'Iniciar sesión' },
    clientes: { crear: 'Registrar cliente', editar: 'Actualizar cliente', buscar: 'Buscar cliente por código' },
    mensualidades: { crear: 'Registrar nuevo periodo', editar: 'Editar periodo' },
    medidas: { crear: 'Añadir medición histórica', buscar: 'Consultar historial corporal' },
    autorizaciones: { crear: 'Registrar autorización', retirar: 'Retirar autorización' },
    solicitudes: { crear: 'Registrar solicitud', resolver: 'Documentar resolución' },
    cuentas: { crear: 'Crear cuenta', permiso: 'Cambiar permisos o bloquear cuenta' },
    planes: { crear: 'Crear plan', editar: 'Editar plan' }
  };
  document.querySelectorAll('form[data-entry]').forEach((form, index) => {
    const action = form.method === 'get' ? 'buscar' : form.elements.action?.value || 'crear';
    const title = ({ archivar: 'Archivar registro', restaurar: 'Restaurar registro' })[action] || titles[page]?.[action] || 'Completar formulario';
    const context = form.querySelector('.row-context')?.textContent;
    const dialog = document.createElement('dialog');
    dialog.id = `formulario-${index}`;
    const heading = document.createElement('h2');
    heading.id = `${dialog.id}-titulo`;
    heading.textContent = context ? `${title} · ${context}` : title;
    dialog.setAttribute('aria-labelledby', heading.id);
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = action === 'buscar' ? 'secondary form-trigger' : 'form-trigger';
    if (context) {
      trigger.classList.add('row-icon-action');
      trigger.setAttribute('aria-label', `${title} · ${context}`);
      attachRowTooltip(trigger, title, `${dialog.id}-ayuda`);
    } else trigger.textContent = title;
    const symbols = { buscar: 'search', crear: page === 'ingreso' ? 'login' : 'plus', editar: 'edit', retirar: 'stop', resolver: 'check', permiso: 'key', archivar: 'archive', restaurar: 'restore' };
    trigger.prepend(actionIcon(symbols[action] || 'file'));
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', dialog.id);
    // Existing headings describe these same actions in the no-JS presentation.
    if (form.previousElementSibling?.tagName === 'H3') form.previousElementSibling.hidden = true;
    form.before(trigger, dialog);
    // Keep row dialogs outside scrolling tables so native modal layout is unconstrained.
    if (context) document.body.append(dialog);
    dialog.append(heading, form);
    const note = document.createElement('p');
    note.className = 'dialog-note';
    note.textContent = page === 'ingreso' ? 'Use su correo y contraseña individuales.' : 'Complete los campos obligatorios. Cancelar no guarda ni envía datos.';
    heading.after(note);
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'secondary';
    cancel.textContent = 'Cancelar';
    cancel.prepend(actionIcon('close'));
    form.append(cancel);
    trigger.addEventListener('click', () => dialog.showModal());
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => trigger.focus());
    dialog.addEventListener('keydown', event => {
      if (event.key !== 'Tab') return;
      const controls = [...dialog.querySelectorAll('input:not([type=hidden]),select,button')].filter(control => !control.disabled);
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    });
    // Native Escape closes without submission; values stay available until navigation.
    if (page === 'ingreso') {
      form.querySelector('[name=email]')?.setAttribute('autocomplete', 'username');
      form.querySelector('[name=password]')?.setAttribute('autocomplete', 'current-password');
      dialog.showModal();
    }
  });
});
