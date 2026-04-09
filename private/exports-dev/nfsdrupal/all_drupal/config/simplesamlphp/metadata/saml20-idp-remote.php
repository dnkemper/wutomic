<?php

/**
 * SAML 2.0 remote IdP metadata for SimpleSAMLphp.
 *
 * Remember to remove the IdPs you don't use from this file.
 *
 * See: https://simplesamlphp.org/docs/stable/simplesamlphp-reference-idp-remote
 */

 /* $metadata['https://idp.lndo.site/simplesaml/saml2/idp/metadata.php'] = array (
 *   'metadata-set' => 'saml20-idp-remote',
 *   'entityid' => 'https://idp.lndo.site/simplesaml/saml2/idp/metadata.php',
 *   'SingleSignOnService' =>
 *       array (
 *           0 =>
 *               array (
 *                   'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
 *                   'Location' => 'https://idp.lndo.site/simplesaml/saml2/idp/SSOService.php',
 *               ),
 *       ),
 *   'SingleLogoutService' =>
 *       array (
 *           0 =>
 *               array (
 *                   'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
 *                   'Location' => 'https://idp.lndo.site/simplesaml/saml2/idp/SingleLogoutService.php',
 *               ),
 *       ),
 *   'certData' => '',
 *   'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
); **/
//<spry-value:saml21-idp-remote.remote-metadata>

$metadata['https://login.wustl.edu/idp/shibboleth'] = [
    'SingleSignOnService' => 'https://login.wustl.edu/idp/profile/SAML2/Redirect/SSO',
    'certificate' => 'wustl.pem',
    'metadata-set' => 'saml20-idp-remote',
    /*   'entityid' => 'https://myprehealth.artscistage.wustl.edu/simplesaml/saml2/idp/metadata.php', */
    'entityid' => 'https://olympian.artscidev.wustl.edu/simplesaml/saml2/idp/metadata.php',
    'SingleLogoutService' => 'https://connect.wustl.edu/logout',
];

