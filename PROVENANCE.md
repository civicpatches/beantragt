# Herkunft der veröffentlichten Backend-Komponenten

Die jeweiligen Lizenz- und Urheberhinweise stehen in den mitgelieferten Dateien und in `NOTICE`.

| Bestandteil | Herkunft |
| --- | --- |
| Mandantenkonfiguration, Gruppenhierarchie und Scope | Portierungen aus dem Drupal-Profil von Mark-a-Spot. Beispiele sind `JurisdictionConfigValidator.php` und `JurisdictionHierarchyResolver.php` in `beantragt_group`. |
| GeoJSON-Validierung und Einladungen | Mark-a-Spot diente als Vorlage für `BoundaryGeoJsonValidator.php` und die Einladungstabellen in `beantragt_group`. |
| Passwortloser Zugang und SSO | Portierungen und Anpassungen aus `markaspot_passwordless` und `markaspot_sso`. |
| Revisionsprotokoll und Schema-Widget | Muster aus den Mark-a-Spot-Modulen `service_request` und `markaspot_open311`. |
| Inhalte und Aufbewahrung | Mark-a-Spot-Muster für Mandanteninhalte und Archivierung. |
| Dokumentendienst | Eigenständiger Dienst unter `services/docgen/` mit MIT-Lizenz. |

Verwendete FIM-Fachdatenverträge werden gesondert bezogen. Weitere Angaben zu Paketlizenzen stehen in `THIRD-PARTY-NOTICES.md`.
