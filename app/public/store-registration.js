(() => {
    const form = document.querySelector('[data-store-registration]');
    if (!form) return;
    const store = form.elements.store_id;
    const email = form.elements.email;
    const password = form.elements.password;
    const confirmation = form.elements.password_confirmation;
    const name = form.elements.name;
    const button = form.querySelector('button[type="submit"], button:not([type])');
    const hint = form.querySelector('[data-registration-hint]');
    const domainHelp = document.getElementById('registration-domain');
    const touched = new Set();
    const mailReady = form.dataset.mailReady === '1';

    function feedback(field, id, error, success) {
        field.setCustomValidity(error);
        const show = Boolean(field.value) || touched.has(field);
        field.toggleAttribute('data-registration-invalid', show && Boolean(error));
        field.setAttribute('aria-invalid', show && error ? 'true' : 'false');
        const message = document.getElementById(id);
        message.textContent = show ? (error || success) : '';
        message.dataset.state = show ? (error ? 'error' : 'success') : '';
    }

    function validate() {
        const domain = store.selectedOptions[0]?.dataset.domain || '';
        const value = email.value.trim().toLowerCase();
        store.setCustomValidity(store.value && !domain ? 'Esta tienda no tiene un dominio habilitado para el registro.' : '');
        domainHelp.textContent = domain ? `Usa un correo de @${domain}. Confirmaremos su recepción mediante un enlace.`
            : store.value ? 'Esta tienda no tiene un dominio habilitado para el registro.' : 'Selecciona tu tienda para conocer el dominio de correo requerido.';
        domainHelp.dataset.state = store.value && !domain ? 'error' : '';
        email.placeholder = domain ? `nombre@${domain}` : 'nombre@tutienda.cl';
        email.setCustomValidity('');
        const addressDomain = value.slice(value.lastIndexOf('@') + 1);
        let emailError = !value ? 'Escribe el correo de tu tienda.'
            : email.validity.typeMismatch ? 'Escribe un correo electrónico válido.'
            : !domain ? 'Selecciona una tienda con dominio habilitado.'
            : addressDomain !== domain ? `El correo debe terminar exactamente en @${domain}.` : '';
        feedback(email, 'registration-email', emailError, 'El dominio coincide. Aún tendrás que verificar el correo.');
        const passwordError = Array.from(password.value).length < 12 ? 'Usa al menos 12 caracteres.'
            : !/\p{L}/u.test(password.value) || !/\p{N}/u.test(password.value) ? 'Incluye al menos una letra y un número.' : '';
        feedback(password, 'registration-password', passwordError, 'La contraseña cumple los requisitos.');
        const confirmationError = !confirmation.value ? 'Repite la contraseña.'
            : confirmation.value !== password.value ? 'Las contraseñas no coinciden.' : '';
        feedback(confirmation, 'registration-confirmation', confirmationError, 'Las contraseñas coinciden.');
        name.setCustomValidity(name.value.trim() ? '' : 'Escribe tu nombre.');
        const valid = [...form.elements].filter(element => element.willValidate).every(element => element.validity.valid);
        button.disabled = !mailReady || !valid;
        hint.textContent = !mailReady ? 'El registro estará disponible cuando se habilite el envío de correos.'
            : valid ? 'Todo listo. Te enviaremos un enlace para verificar el correo.' : 'Completa los campos y corrige los datos indicados para continuar.';
    }
    form.addEventListener('input', validate);
    form.addEventListener('change', validate);
    form.addEventListener('focusout', event => { touched.add(event.target); validate(); });
    form.addEventListener('focusin', validate);
    form.addEventListener('submit', event => {
        [email, password, confirmation].forEach(field => touched.add(field));
        validate();
        if (button.disabled || !form.checkValidity()) { event.preventDefault(); form.reportValidity(); }
    });
    window.addEventListener('pageshow', validate);
    validate();
})();
