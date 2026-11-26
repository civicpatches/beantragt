# Changelog

Changes of the open edition. Earlier releases were not published.

## 0.8.0 (2026-11-26)

### Bug Fixes

* align BundID preflight with Nuxt runtime flags
* report insufficient SSO assurance in browser redirects
* restrict legacy delivery to submission attachments
* **security:** block Drupal installer and maintenance scripts in nginx
* **security:** update Drupal core to 11.4.14 for SA-CORE-2026-019
* **security:** update Symfony to 7.4.24
* **sso:** remove production mock and return error codes
* **sso:** restrict insufficient assurance to ranked levels
* validate BundID production proxy configuration

### Documentation

* **selfhost:** describe BundID through the UI pass-through
* **selfhost:** point the BundID note to the paragraph above

## 0.7.3 (2026-11-24)

### Documentation

* **selfhost:** drop the docker network prune hint again
* **selfhost:** start the README with git clone of the open repository
* **selfhost:** suggest docker network prune before fixed subnets

## 0.7.2 (2026-11-24)

Maintenance release without changes to this component.

## 0.7.1 (2026-11-24)

### Bug Fixes

* **deps:** update twig to 3.31.1 for 10 advisories
