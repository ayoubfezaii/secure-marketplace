import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap';
import './styles/app.css';

// SECURITY (rule 13): no inline onclick="..." handlers anywhere, the CSP (script-src 'self') blocks them.
// Confirmation prompts are attached here instead: <form data-confirm="Are you sure?">.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
}, true);
