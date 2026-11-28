/* Estado de envío real: el resultado lo confirma el servidor después de guardar la carga. */
document.querySelectorAll('[data-inventory-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.busy === '1') { event.preventDefault(); return; }
        form.dataset.busy = '1';
        const button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Procesando…'; }
        form.setAttribute('aria-busy', 'true');
        const status = form.querySelector('[data-inventory-progress]');
        if (status) status.hidden = false;
    });
});
// Atrás del navegador puede recuperar el formulario con el botón aún deshabilitado.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) location.reload();
});
