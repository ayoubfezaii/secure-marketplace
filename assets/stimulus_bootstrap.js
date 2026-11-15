// FILE: assets/stimulus_bootstrap.js
// WHAT: starts the Stimulus application.
// ROLE IN THE PROJECT: startStimulusApp() finds every file of assets/controllers/ and connects it to the HTML
//   elements that have the matching data-controller="..." attribute.
// USED BY: assets/app.js (so: every page).

import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
