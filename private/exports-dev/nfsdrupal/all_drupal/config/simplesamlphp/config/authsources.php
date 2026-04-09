<?php

$config = [

    'admin' => [
        'core:AdminPassword',
    ],

    'default-sp' => [
        'saml:SP',
        'privatekey' => 'saml.pem',
        'certificate' => 'saml.crt',
        'entityID' => 'https://artscidev.wustl.edu/',
        'idp' => 'https://login.wustl.edu/idp/shibboleth',
        'discoURL' => null,
    ],

];
