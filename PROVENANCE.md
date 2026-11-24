# Herkunft der veröffentlichten Backend-Komponenten

Die jeweiligen Lizenz- und Urheberhinweise stehen in den mitgelieferten Dateien und in `NOTICE`. Mark-a-Spot ist ein Open-Source-Projekt derselben Herausgeberin unter GPL-2.0-or-later.

| Bestandteil | Herkunft |
| --- | --- |
| Mandantenkonfiguration und Gruppenhierarchie | Portiert aus Mark-a-Spot: `JurisdictionConfigController.php`, `JurisdictionConfigValidator.php`, `JurisdictionHierarchyResolver.php` und `JurisdictionIdResolverTrait.php` in `beantragt_group`. |
| GeoJSON-Validierung | Enge Portierung des Mark-a-Spot-Validators: `BoundaryGeoJsonValidator.php` in `beantragt_group`. |
| Einladungen | Die Einladungstabelle folgt der Gruppeneinladung von Mark-a-Spot (`beantragt_group.install`). |
| Revisionsprotokoll | Gemeinsames Format `civic.revision.v1` mit Mark-a-Spot (`RevisionLog.php` in `beantragt_application`). |
| Dokumentendienst | Eigenständiger Dienst unter `services/docgen/` mit MIT-Lizenz. |

FIM-Fachdatenverträge für Übertragungen sind nicht enthalten. Weitere Angaben zu Paketlizenzen stehen in `THIRD-PARTY-NOTICES.md`.
