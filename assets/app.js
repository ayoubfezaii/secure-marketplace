import './stimulus_bootstrap.js';
import 'bootstrap';

// SECURITY (rule 13): no inline <script>, no onclick="...", no style="...": every behaviour is a Stimulus
// controller in assets/controllers/ (served from 'self'), so the CSP can stay strict.
//
// CSS is NOT imported here on purpose. AssetMapper maps a CSS import to an empty "data:application/javascript,"
// module; with script-src 'self' (no data:) the browser blocks it and the WHOLE app module fails to run (no Stimulus,
// no charts, no modals). The stylesheets are linked with <link> tags in templates/base.html.twig instead, so the CSP
// doesn't need to allow data: scripts.
