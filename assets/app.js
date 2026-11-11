import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap';
import './styles/vendor/bootstrap-icons.css';
import './styles/fonts.css';
import './styles/tokens.css';
import './styles/app.css';

// SECURITY (rule 13): no inline <script>, no onclick="...", no style="...": every behaviour is a Stimulus
// controller in assets/controllers/ (served from 'self'), so the CSP can stay strict.
