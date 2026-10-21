'use strict';
document.querySelectorAll('[data-toggle-password]').forEach(button => {
  button.addEventListener('click', () => {
    const field = document.getElementById(button.dataset.togglePassword);
    const visible = field.type === 'password';
    field.type = visible ? 'text' : 'password';
    button.textContent = visible ? '非表示' : '表示';
    button.setAttribute('aria-pressed', String(visible));
  });
});
const dialog = document.getElementById('delete-dialog');
let dialogTrigger;
document.querySelectorAll('[data-open-delete]').forEach(button => button.addEventListener('click', () => {
  dialogTrigger = button; dialog.showModal(); dialog.querySelector('[data-close-dialog]').focus();
}));
dialog?.querySelector('[data-close-dialog]')?.addEventListener('click', () => dialog.close());
dialog?.addEventListener('close', () => dialogTrigger?.focus());
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
  form.querySelectorAll('button[type="submit"]').forEach(button => {
    button.disabled = true;
    button.dataset.label = button.textContent;
    button.textContent = '処理中…';
  });
}));
window.addEventListener('pageshow', () => document.querySelectorAll('button[data-label]').forEach(button => {
  button.disabled = false; button.textContent = button.dataset.label;
}));
