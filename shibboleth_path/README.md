Shibboleth protected paths
==========================

INTRODUCTION
------------

The Shibboleth protected paths module provides the ability to protect paths
behind Shibboleth authentication, without requiring Drupal authentication.

Path protection works for both anonymous and authenticated users, deferring to
Drupal access when it's more restrictive.

You can:
* Protect explicit paths or use wildcards to match multiple paths.
* Protect your entire site.
* Further restrict access using Shibboleth attributes for organizational
  affiliations or group memberships.


REQUIREMENTS
------------

* The Shibboleth module
* (optional) Release of Shibboleth attributes for affiliation and group
  membership.


INSTALLATION, CONFIGURATION & PERMISSIONS
------------

* Install the module as usual.
* Enable, disable and create new path rules at
  /admin/config/people/shibboleth/path-rules
* (optional) Allow roles to bypass protected path rules.


TROUBLESHOOTING & FAQS
----------------------

This module is a work in progress. See the [Shibboleth To Do document](TODO.md)
for a list of known issues and planned features.
