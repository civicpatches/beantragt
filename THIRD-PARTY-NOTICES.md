# Drittanbieterhinweise zum Backend

Die Paketstände aus der S3-Bestandsaufnahme sind unten aufgeführt. Abhängigkeiten behalten ihre jeweiligen Lizenzen. Bei einem Release wird das Inventar mit `composer.lock`, den installierten Drupal-Abhängigkeiten und `services/docgen/package-lock.json` abgeglichen. Beim Weitergeben von Images gehören die Originalhinweise der tatsächlich enthaltenen Komponenten und Schriften zum Paket. Gotenberg, veraPDF und die Basisimages werden separat bezogen.

## Docgen

| Paket | Fassung | Lizenz laut Lock | Art | Originaldateien im Tarball | Beleg |
|---|---|---|---|---|---|
| `@xmldom/xmldom` | `0.9.12` | MIT | Direkt | `package/LICENSE` | `services/docgen/package-lock.json:20` |
| `docxtemplater` | `3.71.0` | MIT | Direkt | `package/LICENSE.md` | `services/docgen/package-lock.json:29` |
| `pako` | `2.2.0` | (MIT AND Zlib) | Transitiv | `package/LICENSE` | `services/docgen/package-lock.json:41` |
| `pizzip` | `3.3.0` | (MIT OR GPL-3.0) | Direkt | `package/LICENSE.markdown`, `package/es6/license_header.js`, `package/js/license_header.js`, `package/utils/LICENSE.markdown`, `package/utils/es6/license_header.js`, `package/ziploom/js/license_header.js` | `services/docgen/package-lock.json:57` |

## Composer

<details>
<summary>Alle 199 Paketstände</summary>

| Paket | Fassung | Lizenz laut Lock | Art | Beleg |
|---|---|---|---|---|
| `asm89/stack-cors` | `v2.4.0` | MIT | Laufzeit | `composer.lock:10` |
| `brick/math` | `1.0.0` | MIT | Laufzeit | `composer.lock:67` |
| `chi-teck/drupal-code-generator` | `4.2.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:128` |
| `chillerlan/php-qrcode` | `5.0.5` | MIT, Apache-2.0 | Laufzeit | `composer.lock:189` |
| `chillerlan/php-settings-container` | `3.3.0` | MIT | Laufzeit | `composer.lock:283` |
| `composer/installers` | `v2.3.0` | MIT | Laufzeit | `composer.lock:353` |
| `composer/semver` | `3.4.4` | MIT | Laufzeit | `composer.lock:499` |
| `consolidation/annotated-command` | `4.10.4` | MIT | Laufzeit | `composer.lock:577` |
| `consolidation/config` | `3.2.0` | MIT | Laufzeit | `composer.lock:633` |
| `consolidation/filter-via-dot-access-data` | `2.0.3` | MIT | Laufzeit | `composer.lock:693` |
| `consolidation/log` | `3.1.1` | MIT | Laufzeit | `composer.lock:743` |
| `consolidation/output-formatters` | `4.7.0` | MIT | Laufzeit | `composer.lock:795` |
| `consolidation/robo` | `5.1.1` | MIT | Laufzeit | `composer.lock:849` |
| `consolidation/site-alias` | `4.1.2` | MIT | Laufzeit | `composer.lock:922` |
| `consolidation/site-process` | `5.4.2` | MIT | Laufzeit | `composer.lock:981` |
| `defuse/php-encryption` | `v2.4.0` | MIT | Laufzeit | `composer.lock:1038` |
| `dflydev/dot-access-data` | `v3.0.3` | MIT | Laufzeit | `composer.lock:1105` |
| `doctrine/lexer` | `3.0.3` | MIT | Laufzeit | `composer.lock:1180` |
| `drupal/core` | `11.4.13` | GPL-2.0-or-later | Laufzeit | `composer.lock:1256` |
| `drupal/core-composer-scaffold` | `11.4.13` | GPL-2.0-or-later | Laufzeit | `composer.lock:1451` |
| `drupal/core-recommended` | `11.4.13` | GPL-2.0-or-later | Laufzeit | `composer.lock:1502` |
| `drupal/encrypt` | `3.3.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:1583` |
| `drupal/entity` | `1.8.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:1648` |
| `drupal/field_encrypt` | `4.1.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:1726` |
| `drupal/flexible_permissions` | `2.0.1` | GPL-2.0-or-later | Laufzeit | `composer.lock:1782` |
| `drupal/gin` | `5.0.16` | GPL-2.0-or-later | Laufzeit | `composer.lock:1835` |
| `drupal/gin_login` | `2.1.5` | GPL-2.0+ | Laufzeit | `composer.lock:1901` |
| `drupal/gin_toolbar` | `3.0.3` | GPL-2.0+ | Laufzeit | `composer.lock:1967` |
| `drupal/group` | `3.3.5` | GPL-2.0-or-later | Laufzeit | `composer.lock:2028` |
| `drupal/json_form_widget` | `1.0.1` | GPL-2.0-or-later | Laufzeit | `composer.lock:2084` |
| `drupal/jsonapi_extras` | `3.28.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:2144` |
| `drupal/key` | `1.22.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:2206` |
| `drupal/real_aes` | `2.6.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:2281` |
| `drupal/select2` | `2.0.0` | GPL-2.0-or-later | Laufzeit | `composer.lock:2335` |
| `drupal/select_or_other` | `4.2.2` | GPL-2.0-or-later | Laufzeit | `composer.lock:2392` |
| `drush/drush` | `13.7.2` | GPL-2.0-or-later | Laufzeit | `composer.lock:2464` |
| `e0ipso/shaper` | `1.2.7` | GPL-2.0 | Laufzeit | `composer.lock:2613` |
| `egulias/email-validator` | `4.0.4` | MIT | Laufzeit | `composer.lock:2659` |
| `grasmash/expander` | `3.0.1` | MIT | Laufzeit | `composer.lock:2727` |
| `grasmash/yaml-cli` | `3.2.1` | MIT | Laufzeit | `composer.lock:2779` |
| `guzzlehttp/guzzle` | `7.16.3` | MIT | Laufzeit | `composer.lock:2835` |
| `guzzlehttp/promises` | `2.6.0` | MIT | Laufzeit | `composer.lock:2964` |
| `guzzlehttp/psr7` | `2.13.4` | MIT | Laufzeit | `composer.lock:3049` |
| `justinrainbow/json-schema` | `6.8.2` | MIT | Laufzeit | `composer.lock:3169` |
| `laravel/prompts` | `v0.3.16` | MIT | Laufzeit | `composer.lock:3245` |
| `league/container` | `4.2.5` | MIT | Laufzeit | `composer.lock:3304` |
| `marc-mabe/php-enum` | `v4.7.2` | BSD-3-Clause | Laufzeit | `composer.lock:3386` |
| `masterminds/html5` | `2.12.0` | MIT | Laufzeit | `composer.lock:3460` |
| `mck89/peast` | `v1.17.7` | BSD-3-Clause | Laufzeit | `composer.lock:3529` |
| `nikic/php-parser` | `v5.9.0` | BSD-3-Clause | Laufzeit | `composer.lock:3579` |
| `onelogin/php-saml` | `4.3.2` | MIT | Laufzeit | `composer.lock:3637` |
| `opis/json-schema` | `2.6.0` | Apache-2.0 | Laufzeit | `composer.lock:3701` |
| `opis/string` | `2.1.0` | Apache-2.0 | Laufzeit | `composer.lock:3766` |
| `opis/uri` | `1.1.0` | Apache-2.0 | Laufzeit | `composer.lock:3828` |
| `paragonie/random_compat` | `v9.99.100` | MIT | Laufzeit | `composer.lock:3891` |
| `pear/archive_tar` | `1.6.1` | BSD-2-Clause | Laufzeit | `composer.lock:3942` |
| `pear/console_getopt` | `v1.4.3` | BSD-2-Clause | Laufzeit | `composer.lock:4013` |
| `pear/pear-core-minimal` | `v1.10.18` | BSD-3-Clause | Laufzeit | `composer.lock:4065` |
| `pear/pear_exception` | `v1.0.2` | BSD-2-Clause | Laufzeit | `composer.lock:4115` |
| `phootwork/collection` | `v3.2.3` | MIT | Laufzeit | `composer.lock:4175` |
| `phootwork/lang` | `v3.2.3` | MIT | Laufzeit | `composer.lock:4230` |
| `php-tuf/composer-stager` | `v2.1.1` | MIT | Laufzeit | `composer.lock:4279` |
| `phpowermove/docblock` | `v4.0` | MIT | Laufzeit | `composer.lock:4354` |
| `psr/clock` | `1.0.0` | MIT | Laufzeit | `composer.lock:4406` |
| `psr/container` | `2.0.2` | MIT | Laufzeit | `composer.lock:4454` |
| `psr/event-dispatcher` | `1.0.0` | MIT | Laufzeit | `composer.lock:4508` |
| `psr/http-client` | `1.0.3` | MIT | Laufzeit | `composer.lock:4559` |
| `psr/http-factory` | `1.1.0` | MIT | Laufzeit | `composer.lock:4612` |
| `psr/http-message` | `2.0` | MIT | Laufzeit | `composer.lock:4668` |
| `psr/log` | `3.0.2` | MIT | Laufzeit | `composer.lock:4722` |
| `psy/psysh` | `v0.12.22` | MIT | Laufzeit | `composer.lock:4773` |
| `ralouphie/getallheaders` | `3.0.3` | MIT | Laufzeit | `composer.lock:4852` |
| `revolt/event-loop` | `v1.0.9` | MIT | Laufzeit | `composer.lock:4897` |
| `robrichards/xmlseclibs` | `3.1.5` | BSD-3-Clause | Laufzeit | `composer.lock:4970` |
| `sebastian/diff` | `6.0.2` | BSD-3-Clause | Laufzeit | `composer.lock:5012` |
| `spomky-labs/pki-framework` | `1.6.3` | MIT | Laufzeit | `composer.lock:5080` |
| `symfony/console` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5190` |
| `symfony/dependency-injection` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5289` |
| `symfony/deprecation-contracts` | `v3.7.1` | MIT | Laufzeit | `composer.lock:5374` |
| `symfony/error-handler` | `v7.4.22` | MIT | Laufzeit | `composer.lock:5446` |
| `symfony/event-dispatcher` | `v7.4.17` | MIT | Laufzeit | `composer.lock:5529` |
| `symfony/event-dispatcher-contracts` | `v3.7.1` | MIT | Laufzeit | `composer.lock:5615` |
| `symfony/filesystem` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5696` |
| `symfony/finder` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5767` |
| `symfony/http-foundation` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5836` |
| `symfony/http-kernel` | `v7.4.23` | MIT | Laufzeit | `composer.lock:5919` |
| `symfony/mailer` | `v7.4.22` | MIT | Laufzeit | `composer.lock:6039` |
| `symfony/mime` | `v7.4.22` | MIT | Laufzeit | `composer.lock:6124` |
| `symfony/polyfill-ctype` | `v1.37.0` | MIT | Laufzeit | `composer.lock:6214` |
| `symfony/polyfill-iconv` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6298` |
| `symfony/polyfill-intl-grapheme` | `v1.43.3` | MIT | Laufzeit | `composer.lock:6383` |
| `symfony/polyfill-intl-idn` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6466` |
| `symfony/polyfill-intl-normalizer` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6554` |
| `symfony/polyfill-mbstring` | `v1.43.3` | MIT | Laufzeit | `composer.lock:6640` |
| `symfony/polyfill-php80` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6726` |
| `symfony/polyfill-php81` | `v1.38.1` | MIT | Laufzeit | `composer.lock:6811` |
| `symfony/polyfill-php83` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6892` |
| `symfony/polyfill-php84` | `v1.43.0` | MIT | Laufzeit | `composer.lock:6973` |
| `symfony/polyfill-php85` | `v1.44.0` | MIT | Laufzeit | `composer.lock:7054` |
| `symfony/polyfill-php86` | `v1.43.0` | MIT | Laufzeit | `composer.lock:7135` |
| `symfony/process` | `v7.4.22` | MIT | Laufzeit | `composer.lock:7216` |
| `symfony/psr-http-message-bridge` | `v7.4.21` | MIT | Laufzeit | `composer.lock:7282` |
| `symfony/routing` | `v7.4.23` | MIT | Laufzeit | `composer.lock:7371` |
| `symfony/runtime` | `v7.4.23` | MIT | Laufzeit | `composer.lock:7457` |
| `symfony/serializer` | `v7.4.23` | MIT | Laufzeit | `composer.lock:7543` |
| `symfony/service-contracts` | `v3.7.3` | MIT | Laufzeit | `composer.lock:7648` |
| `symfony/string` | `v7.4.22` | MIT | Laufzeit | `composer.lock:7736` |
| `symfony/translation-contracts` | `v3.7.1` | MIT | Laufzeit | `composer.lock:7828` |
| `symfony/validator` | `v7.4.23` | MIT | Laufzeit | `composer.lock:7911` |
| `symfony/var-dumper` | `v7.4.22` | MIT | Laufzeit | `composer.lock:8016` |
| `symfony/var-exporter` | `v7.4.22` | MIT | Laufzeit | `composer.lock:8104` |
| `symfony/yaml` | `v7.4.23` | MIT | Laufzeit | `composer.lock:8186` |
| `twig/html-extra` | `v3.29.0` | MIT | Laufzeit | `composer.lock:8263` |
| `twig/twig` | `v3.30.0` | BSD-3-Clause | Laufzeit | `composer.lock:8332` |
| `web-token/jwt-library` | `4.2.3` | MIT | Laufzeit | `composer.lock:8413` |
| `behat/mink` | `v1.13.0` | MIT | Entwicklung | `composer.lock:8505` |
| `behat/mink-browserkit-driver` | `v2.3.0` | MIT | Entwicklung | `composer.lock:8573` |
| `colinodell/psr-testlogger` | `v1.3.1` | MIT | Entwicklung | `composer.lock:8642` |
| `composer/ca-bundle` | `1.6.1` | MIT | Entwicklung | `composer.lock:8722` |
| `composer/class-map-generator` | `1.9.0` | MIT | Entwicklung | `composer.lock:8795` |
| `composer/composer` | `2.11.2` | MIT | Entwicklung | `composer.lock:8865` |
| `composer/metadata-minifier` | `1.0.1` | MIT | Entwicklung | `composer.lock:8981` |
| `composer/pcre` | `3.4.0` | MIT | Entwicklung | `composer.lock:9047` |
| `composer/spdx-licenses` | `1.6.0` | MIT | Entwicklung | `composer.lock:9124` |
| `composer/xdebug-handler` | `3.0.5` | MIT | Entwicklung | `composer.lock:9201` |
| `dealerdirect/phpcodesniffer-composer-installer` | `v1.2.1` | MIT | Entwicklung | `composer.lock:9268` |
| `doctrine/deprecations` | `1.2.0` | MIT | Entwicklung | `composer.lock:9365` |
| `doctrine/instantiator` | `2.2.0` | MIT | Entwicklung | `composer.lock:9414` |
| `drupal/coder` | `8.3.31` | GPL-2.0-or-later | Entwicklung | `composer.lock:9484` |
| `drupal/core-dev` | `11.4.13` | GPL-2.0-or-later | Entwicklung | `composer.lock:9536` |
| `google/protobuf` | `v5.36.2` | BSD-3-Clause | Entwicklung | `composer.lock:9597` |
| `lullabot/mink-selenium2-driver` | `v1.7.4` | MIT | Entwicklung | `composer.lock:9642` |
| `lullabot/php-webdriver` | `v2.0.7` | Apache-2.0 | Entwicklung | `composer.lock:9715` |
| `mglaman/phpstan-drupal` | `2.4.0` | MIT | Entwicklung | `composer.lock:9764` |
| `micheh/phpcs-gitlab` | `2.1.0` | BSD-3-Clause | Entwicklung | `composer.lock:9867` |
| `mikey179/vfsstream` | `v1.6.12` | BSD-3-Clause | Entwicklung | `composer.lock:9921` |
| `myclabs/deep-copy` | `1.14.0` | MIT | Entwicklung | `composer.lock:9974` |
| `nyholm/psr7-server` | `1.1.0` | MIT | Entwicklung | `composer.lock:10035` |
| `open-telemetry/api` | `1.11.0` | Apache-2.0 | Entwicklung | `composer.lock:10102` |
| `open-telemetry/context` | `1.6.0` | Apache-2.0 | Entwicklung | `composer.lock:10180` |
| `open-telemetry/exporter-otlp` | `1.5.0` | Apache-2.0 | Entwicklung | `composer.lock:10239` |
| `open-telemetry/gen-otlp-protobuf` | `1.11.1` | Apache-2.0 | Entwicklung | `composer.lock:10304` |
| `open-telemetry/sdk` | `1.16.1` | Apache-2.0 | Entwicklung | `composer.lock:10371` |
| `open-telemetry/sem-conv` | `1.45.0` | Apache-2.0 | Entwicklung | `composer.lock:10471` |
| `phar-io/manifest` | `2.0.4` | BSD-3-Clause | Entwicklung | `composer.lock:10529` |
| `phar-io/version` | `3.2.1` | BSD-3-Clause | Entwicklung | `composer.lock:10597` |
| `php-http/discovery` | `1.20.0` | MIT | Entwicklung | `composer.lock:10649` |
| `php-http/guzzle7-adapter` | `1.1.0` | MIT | Entwicklung | `composer.lock:10729` |
| `php-http/httplug` | `2.4.1` | MIT | Entwicklung | `composer.lock:10789` |
| `php-http/promise` | `1.3.1` | MIT | Entwicklung | `composer.lock:10847` |
| `phpdocumentor/reflection-common` | `2.2.1` | MIT | Entwicklung | `composer.lock:10900` |
| `phpdocumentor/reflection-docblock` | `6.0.3` | MIT | Entwicklung | `composer.lock:10959` |
| `phpdocumentor/type-resolver` | `2.1.0` | MIT | Entwicklung | `composer.lock:11025` |
| `phpspec/prophecy` | `v1.26.1` | MIT | Entwicklung | `composer.lock:11084` |
| `phpspec/prophecy-phpunit` | `v2.5.0` | MIT | Entwicklung | `composer.lock:11156` |
| `phpstan/extension-installer` | `1.4.3` | MIT | Entwicklung | `composer.lock:11212` |
| `phpstan/phpdoc-parser` | `2.4.1` | MIT | Entwicklung | `composer.lock:11261` |
| `phpstan/phpstan` | `2.3.6` | MIT | Entwicklung | `composer.lock:11309` |
| `phpstan/phpstan-deprecation-rules` | `2.0.5` | MIT | Entwicklung | `composer.lock:11374` |
| `phpstan/phpstan-phpunit` | `2.1.2` | MIT | Entwicklung | `composer.lock:11426` |
| `phpunit/php-code-coverage` | `11.0.12` | BSD-3-Clause | Entwicklung | `composer.lock:11485` |
| `phpunit/php-file-iterator` | `5.1.1` | BSD-3-Clause | Entwicklung | `composer.lock:11576` |
| `phpunit/php-invoker` | `5.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:11650` |
| `phpunit/php-text-template` | `4.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:11715` |
| `phpunit/php-timer` | `7.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:11776` |
| `phpunit/phpunit` | `11.5.58` | BSD-3-Clause | Entwicklung | `composer.lock:11837` |
| `ramsey/collection` | `2.1.1` | MIT | Entwicklung | `composer.lock:11932` |
| `ramsey/uuid` | `4.10.0` | MIT | Entwicklung | `composer.lock:12009` |
| `react/promise` | `v3.3.0` | MIT | Entwicklung | `composer.lock:12087` |
| `sebastian/cli-parser` | `3.0.2` | BSD-3-Clause | Entwicklung | `composer.lock:12161` |
| `sebastian/code-unit` | `3.0.3` | BSD-3-Clause | Entwicklung | `composer.lock:12219` |
| `sebastian/code-unit-reverse-lookup` | `4.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:12277` |
| `sebastian/comparator` | `6.3.3` | BSD-3-Clause | Entwicklung | `composer.lock:12334` |
| `sebastian/complexity` | `4.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:12427` |
| `sebastian/environment` | `7.2.1` | BSD-3-Clause | Entwicklung | `composer.lock:12486` |
| `sebastian/exporter` | `6.3.2` | BSD-3-Clause | Entwicklung | `composer.lock:12563` |
| `sebastian/global-state` | `7.0.2` | BSD-3-Clause | Entwicklung | `composer.lock:12654` |
| `sebastian/lines-of-code` | `3.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:12717` |
| `sebastian/object-enumerator` | `6.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:12776` |
| `sebastian/object-reflector` | `4.0.1` | BSD-3-Clause | Entwicklung | `composer.lock:12835` |
| `sebastian/recursion-context` | `6.0.3` | BSD-3-Clause | Entwicklung | `composer.lock:12892` |
| `sebastian/type` | `5.1.3` | BSD-3-Clause | Entwicklung | `composer.lock:12969` |
| `sebastian/version` | `5.0.2` | BSD-3-Clause | Entwicklung | `composer.lock:13039` |
| `seld/jsonlint` | `1.12.2` | MIT | Entwicklung | `composer.lock:13094` |
| `seld/phar-utils` | `1.2.3` | MIT | Entwicklung | `composer.lock:13159` |
| `seld/signal-handler` | `2.0.2` | MIT | Entwicklung | `composer.lock:13208` |
| `sirbrillig/phpcs-variable-analysis` | `v2.13.0` | BSD-2-Clause | Entwicklung | `composer.lock:13270` |
| `slevomat/coding-standard` | `8.22.1` | MIT | Entwicklung | `composer.lock:13327` |
| `squizlabs/php_codesniffer` | `3.13.6` | BSD-3-Clause | Entwicklung | `composer.lock:13393` |
| `staabm/side-effects-detector` | `1.0.5` | MIT | Entwicklung | `composer.lock:13473` |
| `symfony/browser-kit` | `v7.4.23` | MIT | Entwicklung | `composer.lock:13526` |
| `symfony/css-selector` | `v7.4.22` | MIT | Entwicklung | `composer.lock:13601` |
| `symfony/dom-crawler` | `v7.4.22` | MIT | Entwicklung | `composer.lock:13671` |
| `symfony/lock` | `v7.4.23` | MIT | Entwicklung | `composer.lock:13744` |
| `symfony/polyfill-php73` | `v1.37.0` | MIT | Entwicklung | `composer.lock:13828` |
| `tbachert/spi` | `v1.0.5` | Apache-2.0 | Entwicklung | `composer.lock:13909` |
| `theseer/tokenizer` | `1.3.1` | BSD-3-Clause | Entwicklung | `composer.lock:13962` |
| `webflo/drupal-finder` | `1.3.1` | GPL-2.0-or-later | Entwicklung | `composer.lock:14013` |
| `webmozart/assert` | `2.5.1` | MIT | Entwicklung | `composer.lock:14060` |

</details>

## English translation (the German text above is authoritative)

### Third-party notices for the backend

The package versions recorded in the S3 inventory are listed below. Dependencies retain their respective licenses. At release time, the inventory is checked against `composer.lock`, the installed Drupal dependencies and `services/docgen/package-lock.json`. Images are distributed with the original notices for the components and fonts they contain. Gotenberg, veraPDF and the base images are obtained separately.

#### Docgen

| Package | Version | License in the lockfile | Type | Original files in the tarball | Evidence |
|---|---|---|---|---|---|
| `@xmldom/xmldom` | `0.9.12` | MIT | Direct | `package/LICENSE` | `services/docgen/package-lock.json:20` |
| `docxtemplater` | `3.71.0` | MIT | Direct | `package/LICENSE.md` | `services/docgen/package-lock.json:29` |
| `pako` | `2.2.0` | (MIT AND Zlib) | Transitive | `package/LICENSE` | `services/docgen/package-lock.json:41` |
| `pizzip` | `3.3.0` | (MIT OR GPL-3.0) | Direct | `package/LICENSE.markdown`, `package/es6/license_header.js`, `package/js/license_header.js`, `package/utils/LICENSE.markdown`, `package/utils/es6/license_header.js`, `package/ziploom/js/license_header.js` | `services/docgen/package-lock.json:57` |

#### Composer

<details>
<summary>All 199 package versions</summary>

| Package | Version | License in the lockfile | Type | Evidence |
|---|---|---|---|---|
| `asm89/stack-cors` | `v2.4.0` | MIT | Runtime | `composer.lock:10` |
| `brick/math` | `1.0.0` | MIT | Runtime | `composer.lock:67` |
| `chi-teck/drupal-code-generator` | `4.2.0` | GPL-2.0-or-later | Runtime | `composer.lock:128` |
| `chillerlan/php-qrcode` | `5.0.5` | MIT, Apache-2.0 | Runtime | `composer.lock:189` |
| `chillerlan/php-settings-container` | `3.3.0` | MIT | Runtime | `composer.lock:283` |
| `composer/installers` | `v2.3.0` | MIT | Runtime | `composer.lock:353` |
| `composer/semver` | `3.4.4` | MIT | Runtime | `composer.lock:499` |
| `consolidation/annotated-command` | `4.10.4` | MIT | Runtime | `composer.lock:577` |
| `consolidation/config` | `3.2.0` | MIT | Runtime | `composer.lock:633` |
| `consolidation/filter-via-dot-access-data` | `2.0.3` | MIT | Runtime | `composer.lock:693` |
| `consolidation/log` | `3.1.1` | MIT | Runtime | `composer.lock:743` |
| `consolidation/output-formatters` | `4.7.0` | MIT | Runtime | `composer.lock:795` |
| `consolidation/robo` | `5.1.1` | MIT | Runtime | `composer.lock:849` |
| `consolidation/site-alias` | `4.1.2` | MIT | Runtime | `composer.lock:922` |
| `consolidation/site-process` | `5.4.2` | MIT | Runtime | `composer.lock:981` |
| `defuse/php-encryption` | `v2.4.0` | MIT | Runtime | `composer.lock:1038` |
| `dflydev/dot-access-data` | `v3.0.3` | MIT | Runtime | `composer.lock:1105` |
| `doctrine/lexer` | `3.0.3` | MIT | Runtime | `composer.lock:1180` |
| `drupal/core` | `11.4.13` | GPL-2.0-or-later | Runtime | `composer.lock:1256` |
| `drupal/core-composer-scaffold` | `11.4.13` | GPL-2.0-or-later | Runtime | `composer.lock:1451` |
| `drupal/core-recommended` | `11.4.13` | GPL-2.0-or-later | Runtime | `composer.lock:1502` |
| `drupal/encrypt` | `3.3.0` | GPL-2.0-or-later | Runtime | `composer.lock:1583` |
| `drupal/entity` | `1.8.0` | GPL-2.0-or-later | Runtime | `composer.lock:1648` |
| `drupal/field_encrypt` | `4.1.0` | GPL-2.0-or-later | Runtime | `composer.lock:1726` |
| `drupal/flexible_permissions` | `2.0.1` | GPL-2.0-or-later | Runtime | `composer.lock:1782` |
| `drupal/gin` | `5.0.16` | GPL-2.0-or-later | Runtime | `composer.lock:1835` |
| `drupal/gin_login` | `2.1.5` | GPL-2.0+ | Runtime | `composer.lock:1901` |
| `drupal/gin_toolbar` | `3.0.3` | GPL-2.0+ | Runtime | `composer.lock:1967` |
| `drupal/group` | `3.3.5` | GPL-2.0-or-later | Runtime | `composer.lock:2028` |
| `drupal/json_form_widget` | `1.0.1` | GPL-2.0-or-later | Runtime | `composer.lock:2084` |
| `drupal/jsonapi_extras` | `3.28.0` | GPL-2.0-or-later | Runtime | `composer.lock:2144` |
| `drupal/key` | `1.22.0` | GPL-2.0-or-later | Runtime | `composer.lock:2206` |
| `drupal/real_aes` | `2.6.0` | GPL-2.0-or-later | Runtime | `composer.lock:2281` |
| `drupal/select2` | `2.0.0` | GPL-2.0-or-later | Runtime | `composer.lock:2335` |
| `drupal/select_or_other` | `4.2.2` | GPL-2.0-or-later | Runtime | `composer.lock:2392` |
| `drush/drush` | `13.7.2` | GPL-2.0-or-later | Runtime | `composer.lock:2464` |
| `e0ipso/shaper` | `1.2.7` | GPL-2.0 | Runtime | `composer.lock:2613` |
| `egulias/email-validator` | `4.0.4` | MIT | Runtime | `composer.lock:2659` |
| `grasmash/expander` | `3.0.1` | MIT | Runtime | `composer.lock:2727` |
| `grasmash/yaml-cli` | `3.2.1` | MIT | Runtime | `composer.lock:2779` |
| `guzzlehttp/guzzle` | `7.16.3` | MIT | Runtime | `composer.lock:2835` |
| `guzzlehttp/promises` | `2.6.0` | MIT | Runtime | `composer.lock:2964` |
| `guzzlehttp/psr7` | `2.13.4` | MIT | Runtime | `composer.lock:3049` |
| `justinrainbow/json-schema` | `6.8.2` | MIT | Runtime | `composer.lock:3169` |
| `laravel/prompts` | `v0.3.16` | MIT | Runtime | `composer.lock:3245` |
| `league/container` | `4.2.5` | MIT | Runtime | `composer.lock:3304` |
| `marc-mabe/php-enum` | `v4.7.2` | BSD-3-Clause | Runtime | `composer.lock:3386` |
| `masterminds/html5` | `2.12.0` | MIT | Runtime | `composer.lock:3460` |
| `mck89/peast` | `v1.17.7` | BSD-3-Clause | Runtime | `composer.lock:3529` |
| `nikic/php-parser` | `v5.9.0` | BSD-3-Clause | Runtime | `composer.lock:3579` |
| `onelogin/php-saml` | `4.3.2` | MIT | Runtime | `composer.lock:3637` |
| `opis/json-schema` | `2.6.0` | Apache-2.0 | Runtime | `composer.lock:3701` |
| `opis/string` | `2.1.0` | Apache-2.0 | Runtime | `composer.lock:3766` |
| `opis/uri` | `1.1.0` | Apache-2.0 | Runtime | `composer.lock:3828` |
| `paragonie/random_compat` | `v9.99.100` | MIT | Runtime | `composer.lock:3891` |
| `pear/archive_tar` | `1.6.1` | BSD-2-Clause | Runtime | `composer.lock:3942` |
| `pear/console_getopt` | `v1.4.3` | BSD-2-Clause | Runtime | `composer.lock:4013` |
| `pear/pear-core-minimal` | `v1.10.18` | BSD-3-Clause | Runtime | `composer.lock:4065` |
| `pear/pear_exception` | `v1.0.2` | BSD-2-Clause | Runtime | `composer.lock:4115` |
| `phootwork/collection` | `v3.2.3` | MIT | Runtime | `composer.lock:4175` |
| `phootwork/lang` | `v3.2.3` | MIT | Runtime | `composer.lock:4230` |
| `php-tuf/composer-stager` | `v2.1.1` | MIT | Runtime | `composer.lock:4279` |
| `phpowermove/docblock` | `v4.0` | MIT | Runtime | `composer.lock:4354` |
| `psr/clock` | `1.0.0` | MIT | Runtime | `composer.lock:4406` |
| `psr/container` | `2.0.2` | MIT | Runtime | `composer.lock:4454` |
| `psr/event-dispatcher` | `1.0.0` | MIT | Runtime | `composer.lock:4508` |
| `psr/http-client` | `1.0.3` | MIT | Runtime | `composer.lock:4559` |
| `psr/http-factory` | `1.1.0` | MIT | Runtime | `composer.lock:4612` |
| `psr/http-message` | `2.0` | MIT | Runtime | `composer.lock:4668` |
| `psr/log` | `3.0.2` | MIT | Runtime | `composer.lock:4722` |
| `psy/psysh` | `v0.12.22` | MIT | Runtime | `composer.lock:4773` |
| `ralouphie/getallheaders` | `3.0.3` | MIT | Runtime | `composer.lock:4852` |
| `revolt/event-loop` | `v1.0.9` | MIT | Runtime | `composer.lock:4897` |
| `robrichards/xmlseclibs` | `3.1.5` | BSD-3-Clause | Runtime | `composer.lock:4970` |
| `sebastian/diff` | `6.0.2` | BSD-3-Clause | Runtime | `composer.lock:5012` |
| `spomky-labs/pki-framework` | `1.6.3` | MIT | Runtime | `composer.lock:5080` |
| `symfony/console` | `v7.4.23` | MIT | Runtime | `composer.lock:5190` |
| `symfony/dependency-injection` | `v7.4.23` | MIT | Runtime | `composer.lock:5289` |
| `symfony/deprecation-contracts` | `v3.7.1` | MIT | Runtime | `composer.lock:5374` |
| `symfony/error-handler` | `v7.4.22` | MIT | Runtime | `composer.lock:5446` |
| `symfony/event-dispatcher` | `v7.4.17` | MIT | Runtime | `composer.lock:5529` |
| `symfony/event-dispatcher-contracts` | `v3.7.1` | MIT | Runtime | `composer.lock:5615` |
| `symfony/filesystem` | `v7.4.23` | MIT | Runtime | `composer.lock:5696` |
| `symfony/finder` | `v7.4.23` | MIT | Runtime | `composer.lock:5767` |
| `symfony/http-foundation` | `v7.4.23` | MIT | Runtime | `composer.lock:5836` |
| `symfony/http-kernel` | `v7.4.23` | MIT | Runtime | `composer.lock:5919` |
| `symfony/mailer` | `v7.4.22` | MIT | Runtime | `composer.lock:6039` |
| `symfony/mime` | `v7.4.22` | MIT | Runtime | `composer.lock:6124` |
| `symfony/polyfill-ctype` | `v1.37.0` | MIT | Runtime | `composer.lock:6214` |
| `symfony/polyfill-iconv` | `v1.43.0` | MIT | Runtime | `composer.lock:6298` |
| `symfony/polyfill-intl-grapheme` | `v1.43.3` | MIT | Runtime | `composer.lock:6383` |
| `symfony/polyfill-intl-idn` | `v1.43.0` | MIT | Runtime | `composer.lock:6466` |
| `symfony/polyfill-intl-normalizer` | `v1.43.0` | MIT | Runtime | `composer.lock:6554` |
| `symfony/polyfill-mbstring` | `v1.43.3` | MIT | Runtime | `composer.lock:6640` |
| `symfony/polyfill-php80` | `v1.43.0` | MIT | Runtime | `composer.lock:6726` |
| `symfony/polyfill-php81` | `v1.38.1` | MIT | Runtime | `composer.lock:6811` |
| `symfony/polyfill-php83` | `v1.43.0` | MIT | Runtime | `composer.lock:6892` |
| `symfony/polyfill-php84` | `v1.43.0` | MIT | Runtime | `composer.lock:6973` |
| `symfony/polyfill-php85` | `v1.44.0` | MIT | Runtime | `composer.lock:7054` |
| `symfony/polyfill-php86` | `v1.43.0` | MIT | Runtime | `composer.lock:7135` |
| `symfony/process` | `v7.4.22` | MIT | Runtime | `composer.lock:7216` |
| `symfony/psr-http-message-bridge` | `v7.4.21` | MIT | Runtime | `composer.lock:7282` |
| `symfony/routing` | `v7.4.23` | MIT | Runtime | `composer.lock:7371` |
| `symfony/runtime` | `v7.4.23` | MIT | Runtime | `composer.lock:7457` |
| `symfony/serializer` | `v7.4.23` | MIT | Runtime | `composer.lock:7543` |
| `symfony/service-contracts` | `v3.7.3` | MIT | Runtime | `composer.lock:7648` |
| `symfony/string` | `v7.4.22` | MIT | Runtime | `composer.lock:7736` |
| `symfony/translation-contracts` | `v3.7.1` | MIT | Runtime | `composer.lock:7828` |
| `symfony/validator` | `v7.4.23` | MIT | Runtime | `composer.lock:7911` |
| `symfony/var-dumper` | `v7.4.22` | MIT | Runtime | `composer.lock:8016` |
| `symfony/var-exporter` | `v7.4.22` | MIT | Runtime | `composer.lock:8104` |
| `symfony/yaml` | `v7.4.23` | MIT | Runtime | `composer.lock:8186` |
| `twig/html-extra` | `v3.29.0` | MIT | Runtime | `composer.lock:8263` |
| `twig/twig` | `v3.30.0` | BSD-3-Clause | Runtime | `composer.lock:8332` |
| `web-token/jwt-library` | `4.2.3` | MIT | Runtime | `composer.lock:8413` |
| `behat/mink` | `v1.13.0` | MIT | Development | `composer.lock:8505` |
| `behat/mink-browserkit-driver` | `v2.3.0` | MIT | Development | `composer.lock:8573` |
| `colinodell/psr-testlogger` | `v1.3.1` | MIT | Development | `composer.lock:8642` |
| `composer/ca-bundle` | `1.6.1` | MIT | Development | `composer.lock:8722` |
| `composer/class-map-generator` | `1.9.0` | MIT | Development | `composer.lock:8795` |
| `composer/composer` | `2.11.2` | MIT | Development | `composer.lock:8865` |
| `composer/metadata-minifier` | `1.0.1` | MIT | Development | `composer.lock:8981` |
| `composer/pcre` | `3.4.0` | MIT | Development | `composer.lock:9047` |
| `composer/spdx-licenses` | `1.6.0` | MIT | Development | `composer.lock:9124` |
| `composer/xdebug-handler` | `3.0.5` | MIT | Development | `composer.lock:9201` |
| `dealerdirect/phpcodesniffer-composer-installer` | `v1.2.1` | MIT | Development | `composer.lock:9268` |
| `doctrine/deprecations` | `1.2.0` | MIT | Development | `composer.lock:9365` |
| `doctrine/instantiator` | `2.2.0` | MIT | Development | `composer.lock:9414` |
| `drupal/coder` | `8.3.31` | GPL-2.0-or-later | Development | `composer.lock:9484` |
| `drupal/core-dev` | `11.4.13` | GPL-2.0-or-later | Development | `composer.lock:9536` |
| `google/protobuf` | `v5.36.2` | BSD-3-Clause | Development | `composer.lock:9597` |
| `lullabot/mink-selenium2-driver` | `v1.7.4` | MIT | Development | `composer.lock:9642` |
| `lullabot/php-webdriver` | `v2.0.7` | Apache-2.0 | Development | `composer.lock:9715` |
| `mglaman/phpstan-drupal` | `2.4.0` | MIT | Development | `composer.lock:9764` |
| `micheh/phpcs-gitlab` | `2.1.0` | BSD-3-Clause | Development | `composer.lock:9867` |
| `mikey179/vfsstream` | `v1.6.12` | BSD-3-Clause | Development | `composer.lock:9921` |
| `myclabs/deep-copy` | `1.14.0` | MIT | Development | `composer.lock:9974` |
| `nyholm/psr7-server` | `1.1.0` | MIT | Development | `composer.lock:10035` |
| `open-telemetry/api` | `1.11.0` | Apache-2.0 | Development | `composer.lock:10102` |
| `open-telemetry/context` | `1.6.0` | Apache-2.0 | Development | `composer.lock:10180` |
| `open-telemetry/exporter-otlp` | `1.5.0` | Apache-2.0 | Development | `composer.lock:10239` |
| `open-telemetry/gen-otlp-protobuf` | `1.11.1` | Apache-2.0 | Development | `composer.lock:10304` |
| `open-telemetry/sdk` | `1.16.1` | Apache-2.0 | Development | `composer.lock:10371` |
| `open-telemetry/sem-conv` | `1.45.0` | Apache-2.0 | Development | `composer.lock:10471` |
| `phar-io/manifest` | `2.0.4` | BSD-3-Clause | Development | `composer.lock:10529` |
| `phar-io/version` | `3.2.1` | BSD-3-Clause | Development | `composer.lock:10597` |
| `php-http/discovery` | `1.20.0` | MIT | Development | `composer.lock:10649` |
| `php-http/guzzle7-adapter` | `1.1.0` | MIT | Development | `composer.lock:10729` |
| `php-http/httplug` | `2.4.1` | MIT | Development | `composer.lock:10789` |
| `php-http/promise` | `1.3.1` | MIT | Development | `composer.lock:10847` |
| `phpdocumentor/reflection-common` | `2.2.1` | MIT | Development | `composer.lock:10900` |
| `phpdocumentor/reflection-docblock` | `6.0.3` | MIT | Development | `composer.lock:10959` |
| `phpdocumentor/type-resolver` | `2.1.0` | MIT | Development | `composer.lock:11025` |
| `phpspec/prophecy` | `v1.26.1` | MIT | Development | `composer.lock:11084` |
| `phpspec/prophecy-phpunit` | `v2.5.0` | MIT | Development | `composer.lock:11156` |
| `phpstan/extension-installer` | `1.4.3` | MIT | Development | `composer.lock:11212` |
| `phpstan/phpdoc-parser` | `2.4.1` | MIT | Development | `composer.lock:11261` |
| `phpstan/phpstan` | `2.3.6` | MIT | Development | `composer.lock:11309` |
| `phpstan/phpstan-deprecation-rules` | `2.0.5` | MIT | Development | `composer.lock:11374` |
| `phpstan/phpstan-phpunit` | `2.1.2` | MIT | Development | `composer.lock:11426` |
| `phpunit/php-code-coverage` | `11.0.12` | BSD-3-Clause | Development | `composer.lock:11485` |
| `phpunit/php-file-iterator` | `5.1.1` | BSD-3-Clause | Development | `composer.lock:11576` |
| `phpunit/php-invoker` | `5.0.1` | BSD-3-Clause | Development | `composer.lock:11650` |
| `phpunit/php-text-template` | `4.0.1` | BSD-3-Clause | Development | `composer.lock:11715` |
| `phpunit/php-timer` | `7.0.1` | BSD-3-Clause | Development | `composer.lock:11776` |
| `phpunit/phpunit` | `11.5.58` | BSD-3-Clause | Development | `composer.lock:11837` |
| `ramsey/collection` | `2.1.1` | MIT | Development | `composer.lock:11932` |
| `ramsey/uuid` | `4.10.0` | MIT | Development | `composer.lock:12009` |
| `react/promise` | `v3.3.0` | MIT | Development | `composer.lock:12087` |
| `sebastian/cli-parser` | `3.0.2` | BSD-3-Clause | Development | `composer.lock:12161` |
| `sebastian/code-unit` | `3.0.3` | BSD-3-Clause | Development | `composer.lock:12219` |
| `sebastian/code-unit-reverse-lookup` | `4.0.1` | BSD-3-Clause | Development | `composer.lock:12277` |
| `sebastian/comparator` | `6.3.3` | BSD-3-Clause | Development | `composer.lock:12334` |
| `sebastian/complexity` | `4.0.1` | BSD-3-Clause | Development | `composer.lock:12427` |
| `sebastian/environment` | `7.2.1` | BSD-3-Clause | Development | `composer.lock:12486` |
| `sebastian/exporter` | `6.3.2` | BSD-3-Clause | Development | `composer.lock:12563` |
| `sebastian/global-state` | `7.0.2` | BSD-3-Clause | Development | `composer.lock:12654` |
| `sebastian/lines-of-code` | `3.0.1` | BSD-3-Clause | Development | `composer.lock:12717` |
| `sebastian/object-enumerator` | `6.0.1` | BSD-3-Clause | Development | `composer.lock:12776` |
| `sebastian/object-reflector` | `4.0.1` | BSD-3-Clause | Development | `composer.lock:12835` |
| `sebastian/recursion-context` | `6.0.3` | BSD-3-Clause | Development | `composer.lock:12892` |
| `sebastian/type` | `5.1.3` | BSD-3-Clause | Development | `composer.lock:12969` |
| `sebastian/version` | `5.0.2` | BSD-3-Clause | Development | `composer.lock:13039` |
| `seld/jsonlint` | `1.12.2` | MIT | Development | `composer.lock:13094` |
| `seld/phar-utils` | `1.2.3` | MIT | Development | `composer.lock:13159` |
| `seld/signal-handler` | `2.0.2` | MIT | Development | `composer.lock:13208` |
| `sirbrillig/phpcs-variable-analysis` | `v2.13.0` | BSD-2-Clause | Development | `composer.lock:13270` |
| `slevomat/coding-standard` | `8.22.1` | MIT | Development | `composer.lock:13327` |
| `squizlabs/php_codesniffer` | `3.13.6` | BSD-3-Clause | Development | `composer.lock:13393` |
| `staabm/side-effects-detector` | `1.0.5` | MIT | Development | `composer.lock:13473` |
| `symfony/browser-kit` | `v7.4.23` | MIT | Development | `composer.lock:13526` |
| `symfony/css-selector` | `v7.4.22` | MIT | Development | `composer.lock:13601` |
| `symfony/dom-crawler` | `v7.4.22` | MIT | Development | `composer.lock:13671` |
| `symfony/lock` | `v7.4.23` | MIT | Development | `composer.lock:13744` |
| `symfony/polyfill-php73` | `v1.37.0` | MIT | Development | `composer.lock:13828` |
| `tbachert/spi` | `v1.0.5` | Apache-2.0 | Development | `composer.lock:13909` |
| `theseer/tokenizer` | `1.3.1` | BSD-3-Clause | Development | `composer.lock:13962` |
| `webflo/drupal-finder` | `1.3.1` | GPL-2.0-or-later | Development | `composer.lock:14013` |
| `webmozart/assert` | `2.5.1` | MIT | Development | `composer.lock:14060` |

</details>
