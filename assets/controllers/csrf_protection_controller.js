// FILE: assets/controllers/csrf_protection_controller.js
// WHAT: script provided by Symfony (symfony/stimulus-bundle recipe) for its "stateless" CSRF protection.
// ROLE IN THE PROJECT: SECURITY: CSRF - when a form with a field named "_csrf_token" is submitted (the LOGIN and
//   2FA forms), it puts a random token in the field AND in a cookie ("double submit"). The server accepts the
//   form only if both match. Another website cannot read or set our cookies, so it cannot forge the request.
// USED BY: the login form, the 2FA form and the logout form. The other forms use classic session tokens.

// Allowed format of the token name and of the token value.
const nameCheck = /^[-_a-zA-Z0-9]{4,22}$/;
const tokenCheck = /^[-_/+a-zA-Z0-9]{24,}$/;

// Generate and double-submit a CSRF token in a form field and a cookie, as defined by Symfony's SameOriginCsrfTokenManager
// Use `form.requestSubmit()` to ensure that the submit event is triggered. Using `form.submit()` will not trigger the event
// and thus this event-listener will not be executed.
document.addEventListener('submit', function (event) {
    generateCsrfToken(event.target);
}, true);

// When @hotwired/turbo handles form submissions, send the CSRF token in a header in addition to a cookie
// The `framework.csrf_protection.check_header` config option needs to be enabled for the header to be checked
document.addEventListener('turbo:submit-start', function (event) {
    const h = generateCsrfHeaders(event.detail.formSubmission.formElement);
    Object.keys(h).map(function (k) {
        event.detail.formSubmission.fetchRequest.headers[k] = h[k];
    });
});

// When @hotwired/turbo handles form submissions, remove the CSRF cookie once a form has been submitted
document.addEventListener('turbo:submit-end', function (event) {
    removeCsrfToken(event.detail.formSubmission.formElement);
});

// generateCsrfToken(form): writes a fresh random token in the hidden field and in a cookie.
export function generateCsrfToken (formElement) {
    const csrfField = formElement.querySelector('input[data-controller="csrf-protection"], input[name="_csrf_token"]');

    // No CSRF field in this form: nothing to do.
    if (!csrfField) {
        return;
    }

    let csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');
    let csrfToken = csrfField.value;

    // First submission: the field still contains the token NAME. Keep the name, replace the value by 18 random bytes.
    if (!csrfCookie && nameCheck.test(csrfToken)) {
        csrfField.setAttribute('data-csrf-protection-cookie-value', csrfCookie = csrfToken);
        csrfField.defaultValue = csrfToken = btoa(String.fromCharCode.apply(null, (window.crypto || window.msCrypto).getRandomValues(new Uint8Array(18))));
    }
    csrfField.dispatchEvent(new Event('change', { bubbles: true }));

    // Write the cookie "<name>_<token>=<name>". On https it gets the __Host- prefix and the Secure flag;
    // samesite=strict means the browser never sends it with requests coming from another site.
    if (csrfCookie && tokenCheck.test(csrfToken)) {
        const cookie = csrfCookie + '_' + csrfToken + '=' + csrfCookie + '; path=/; samesite=strict';
        document.cookie = window.location.protocol === 'https:' ? '__Host-' + cookie + '; secure' : cookie;
    }
}

// generateCsrfHeaders(form): returns the same token as an HTTP header (used when Turbo sends the form).
export function generateCsrfHeaders (formElement) {
    const headers = {};
    const csrfField = formElement.querySelector('input[data-controller="csrf-protection"], input[name="_csrf_token"]');

    if (!csrfField) {
        return headers;
    }

    const csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');

    if (tokenCheck.test(csrfField.value) && nameCheck.test(csrfCookie)) {
        headers[csrfCookie] = csrfField.value;
    }

    return headers;
}

// removeCsrfToken(form): deletes the cookie after the form was sent (max-age=0).
export function removeCsrfToken (formElement) {
    const csrfField = formElement.querySelector('input[data-controller="csrf-protection"], input[name="_csrf_token"]');

    if (!csrfField) {
        return;
    }

    const csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');

    if (tokenCheck.test(csrfField.value) && nameCheck.test(csrfCookie)) {
        const cookie = csrfCookie + '_' + csrfField.value + '=0; path=/; samesite=strict; max-age=0';

        document.cookie = window.location.protocol === 'https:' ? '__Host-' + cookie + '; secure' : cookie;
    }
}

// The line below tells Stimulus to load this file only when needed.
/* stimulusFetch: 'lazy' */
export default 'csrf-protection-controller';
