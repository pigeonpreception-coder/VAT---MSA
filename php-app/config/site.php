<?php

/*
|--------------------------------------------------------------------------
| Public marketing site
|--------------------------------------------------------------------------
|
| Details the public pages show only when they are configured, so the site
| never publishes an address or an operator name nobody has confirmed.
| Leave both unset and the footer simply omits them.
|
*/

return [

    // A monitored inbox visitors can write to (shown in the footer and on
    // /trust as "Report a security concern"). Example: info@your-domain.com
    'contact_email' => env('SITE_CONTACT_EMAIL'),

    // The legal name of whoever operates this deployment, e.g. "Example
    // Advisory CC". Shown in the footer copyright line when set.
    'operator' => env('SITE_OPERATOR_NAME'),

];
