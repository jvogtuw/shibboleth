Shibboleth
==========

INTRODUCTION
------------

The Shibboleth module integrates Shibboleth SSO with Drupal authentication.

It uses the Shibboleth session to log in an associated Drupal user account.
Optionally, new users can be created automatically upon login attempt.

This module was highly inspired by shib_auth.


CAVEATS
-------

Because this was written for personal use, there are two hardcoded items that
will be of no use to users outside the University of Washington. However,
neither should cause an issue for other users.

1. The default config setting for the Shibboleth email attribute is
   HTTP_UWEDUEMAIL.
2. src\Authentication\ShibbolethAuthManager->getEmail() replaces the long,
   legacy email domain 'u.washington.edu' with the shorter 'uw.edu'.


REQUIREMENTS
------------

The Shibboleth service must be configured on your server.


INSTALLATION
------------

Install as usual.

You may find it useful to set some of the config values in your settings.php
file. These values will override anything set in the UI.


CONFIGURATION
-------------

1. Set the login and logout handler URLs and the attribute names along with any
   other desired settings.
   1. Under DISPLAY SETTINGS, set the term for the Shibboleth ID to be shown to
      users.
2. In the user account form display settings
   (/admin/config/people/accounts/form-display), enable the new Shibboleth
   authname field so that it is visible on the user form.
3. (Optional) Manually add Shibboleth authnames to existing users.
4. (Optional) Enable the Shibboleth login block.
5. (Optional) Install the Shibboleth path module to control access to your site.


TROUBLESHOOTING & FAQS
----------------------

This module is a work in progress. See the [To Do document](TODO.md) for a list
of known issues and planned features.
