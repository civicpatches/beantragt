<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves the Stelle a profile consent names and what a form can offer.
 *
 * Shared by the public submission API, which stores the consent record, and
 * the computed form_schema fields the public schema route reads, so the text
 * the frontend renders and the stored snapshot name the same Stelle.
 */
final class ProfileConsentScope {

  /**
   * Resolves the published groups a public form is routed through.
   *
   * The route organisation is the organisation on the form itself (direct or
   * legacy field_group); the default organisation sink for jurisdiction-
   * direct forms is not part of it, because the form route does not show it.
   *
   * @return array{jurisdiction: ?\Drupal\group\Entity\GroupInterface, routeOrganisation: ?\Drupal\group\Entity\GroupInterface, group: ?\Drupal\group\Entity\GroupInterface}
   *   Published jurisdiction, route organisation and legacy group.
   */
  public static function formGroups(ContentEntityInterface $form): array {
    $direct_jurisdiction = self::referencedPublishedGroup($form, 'field_jurisdiction');
    $direct_organisation = self::referencedPublishedGroup($form, 'field_organisation');
    $legacy_group = self::referencedPublishedGroup($form, 'field_group');
    $route_organisation = $direct_organisation
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'org' ? $legacy_group : NULL);
    $jurisdiction = $direct_jurisdiction
      ?? ($route_organisation instanceof GroupInterface ? self::referencedPublishedGroup($route_organisation, 'field_jurisdiction') : NULL)
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'jur' ? $legacy_group : NULL);
    return [
      'jurisdiction' => $jurisdiction,
      'routeOrganisation' => $route_organisation,
      'group' => $legacy_group,
    ];
  }

  /**
   * Resolves the groups an application of a public form is stored under.
   *
   * Like formGroups(), plus the organisation the application lands in: the
   * route organisation, otherwise the default organisation sink of the
   * jurisdiction. The submission API stores these groups on the application,
   * and the profile sync copies them to the applicant profile, so a profile
   * lookup for a form must resolve them the same way.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $form
   *   The form schema.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param callable(): string $default_organisation_slug
   *   Returns the configured default organisation slug; called only when the
   *   form names no organisation.
   *
   * @return array{jurisdiction: ?\Drupal\group\Entity\GroupInterface, organisation: ?\Drupal\group\Entity\GroupInterface, routeOrganisation: ?\Drupal\group\Entity\GroupInterface, group: ?\Drupal\group\Entity\GroupInterface}
   *   Published jurisdiction, storing organisation, route organisation and
   *   legacy group.
   */
  public static function submissionGroups(ContentEntityInterface $form, EntityTypeManagerInterface $entity_type_manager, callable $default_organisation_slug): array {
    $groups = self::formGroups($form);
    $organisation = $groups['routeOrganisation'];
    if (!$organisation instanceof GroupInterface && $groups['jurisdiction'] instanceof GroupInterface) {
      $organisation = self::defaultPublishedOrganisation($groups['jurisdiction'], $entity_type_manager, (string) $default_organisation_slug());
    }
    return [
      'jurisdiction' => $groups['jurisdiction'],
      'organisation' => $organisation,
      'routeOrganisation' => $groups['routeOrganisation'],
      'group' => $groups['group'],
    ];
  }

  /**
   * Returns the published default organisation of a jurisdiction.
   *
   * The default organisation is the one whose slug equals the configured
   * default_organisation_slug of beantragt_group.settings.
   */
  public static function defaultPublishedOrganisation(GroupInterface $jurisdiction, EntityTypeManagerInterface $entity_type_manager, string $default_slug): ?GroupInterface {
    $jurisdiction_id = (int) $jurisdiction->id();
    if ($jurisdiction_id <= 0 || $default_slug === '') {
      return NULL;
    }

    $groups = $entity_type_manager->getStorage('group')->loadByProperties(['type' => 'org']);
    foreach ($groups as $group) {
      if (!$group instanceof GroupInterface
        || $group->bundle() !== 'org'
        || !$group->isPublished()
        || !$group->hasField('field_jurisdiction')
        || $group->get('field_jurisdiction')->isEmpty()
        || (int) $group->get('field_jurisdiction')->target_id !== $jurisdiction_id) {
        continue;
      }
      if ($group->hasField('field_slug')
        && !$group->get('field_slug')->isEmpty()
        && (string) $group->get('field_slug')->value === $default_slug) {
        return $group;
      }
    }

    return NULL;
  }

  /**
   * Builds the scope snapshot of the Stelle named in the consent text.
   *
   * The organisation from the form route wins, otherwise the jurisdiction.
   * The label is the untranslated group label, independent of the request
   * language, normalized like the rendered consent text.
   *
   * @return array{type: string, id: int, uuid: string, label: string}|null
   *   Scope snapshot, or NULL when no labelled group is available.
   */
  public static function scope(?GroupInterface $route_organisation, ?GroupInterface $jurisdiction): ?array {
    $group = $route_organisation ?? $jurisdiction;
    if (!$group instanceof GroupInterface) {
      return NULL;
    }
    $label = ProfileConsentText::normalizeStelle((string) $group->getUntranslated()->label());
    if ($label === '') {
      return NULL;
    }
    return [
      'type' => $group->bundle() === 'org' ? 'org' : 'jur',
      'id' => (int) $group->id(),
      'uuid' => (string) $group->uuid(),
      'label' => $label,
    ];
  }

  /**
   * Returns the scope snapshot for a form.
   *
   * @return array{type: string, id: int, uuid: string, label: string}|null
   *   Scope snapshot, or NULL when no labelled group is available.
   */
  public static function forForm(ContentEntityInterface $form): ?array {
    $groups = self::formGroups($form);
    return self::scope($groups['routeOrganisation'], $groups['jurisdiction']);
  }

  /**
   * Describes which consents a form can offer and the Stelle they name.
   *
   * Eligibility follows Application::profileConsentEligibility(); a form
   * without a resolvable Stelle offers nothing, because the submission API
   * stores no consent in that case. The jurisdiction switch is not part of
   * this: it is read from the public jurisdiction config.
   *
   * @return array{profileSyncEligible: bool, bankConsentEligible: bool, consentStelle: ?string}
   *   Offer for the public schema projection.
   */
  public static function offerForForm(FormSchemaInterface $form): array {
    $none = [
      'profileSyncEligible' => FALSE,
      'bankConsentEligible' => FALSE,
      'consentStelle' => NULL,
    ];
    $schema = self::decodeSchema($form->getSchema());
    $scope = self::forForm($form);
    if ($schema === NULL || $scope === NULL) {
      return $none;
    }
    $eligibility = Application::profileConsentEligibility($schema);
    if ($eligibility['contact'] !== TRUE) {
      return $none;
    }
    return [
      'profileSyncEligible' => TRUE,
      'bankConsentEligible' => $eligibility['bank'] === TRUE,
      'consentStelle' => $scope['label'],
    ];
  }

  /**
   * Returns every group the consent offer of a form depends on.
   *
   * Includes unpublished groups and the jurisdiction of each referenced
   * organisation, so publishing, unpublishing or renaming any of them
   * invalidates cached projections of the offer.
   *
   * @return list<\Drupal\group\Entity\GroupInterface>
   *   Referenced groups, each once.
   */
  public static function cacheableGroups(ContentEntityInterface $form): array {
    $groups = [];
    foreach (['field_organisation', 'field_jurisdiction', 'field_group'] as $field_name) {
      $group = self::referencedGroup($form, $field_name);
      if (!$group instanceof GroupInterface) {
        continue;
      }
      $groups[(int) $group->id()] = $group;
      if ($group->bundle() === 'org') {
        $jurisdiction = self::referencedGroup($group, 'field_jurisdiction');
        if ($jurisdiction instanceof GroupInterface) {
          $groups[(int) $jurisdiction->id()] = $jurisdiction;
        }
      }
    }
    return array_values($groups);
  }

  /**
   * Loads a referenced group when it is published.
   */
  public static function referencedPublishedGroup(ContentEntityInterface $entity, string $field_name): ?GroupInterface {
    $group = self::referencedGroup($entity, $field_name);
    return $group instanceof GroupInterface && $group->isPublished() ? $group : NULL;
  }

  /**
   * Loads a referenced group regardless of its publication state.
   */
  private static function referencedGroup(ContentEntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $group = $entity->get($field_name)->entity;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Decodes a stored form schema object.
   */
  private static function decodeSchema(?string $json): ?array {
    if ($json === NULL || trim($json) === '') {
      return NULL;
    }
    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) && !array_is_list($decoded) ? $decoded : NULL;
  }

}
