<?php
// solar manager api user credentials
const LOGIN_EMAIL = 'user@email.com'; // CHANGE ME
const LOGIN_PASS = 'superSecretPassword'; // CHANGE ME

// solar manager api url
const API_URL = 'https://cloud.solar-manager.ch';

// solar manager ids (smId) of the solar managers to be monitored.
// can be obtained via the /v1/customers endpoint of the solar manager api.
const SOLAR_MANAGER_IDS = ['0123456789ABCDEF', 'ABCDEF0123456789']; // CHANGE ME

// sensor resolution in seconds
// allowed values are 10, 300, 900
// 300 is recommended.
// 10 may quickly yield a huge database and export files.
const SENSOR_RESOLUTION_SEC = 300;

// request timeout for the solar manager api in seconds
const REQUEST_TIMEOUT_SEC = 60;
