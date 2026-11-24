<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Service\ApplicantProfileSwitch;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;

/**
 * Builds the versioned offer used by both schema reads and submissions.
 */
final class ProfileConsentOffer {

  /**
   * Resolves available consent offers for one locale.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Published internet form revision.
   * @param array $schema
   *   Decoded schema from that revision.
   * @param array $groups
   *   Groups resolved for the form.
   * @param \Drupal\beantragt_application\Service\ApplicantProfileSwitch $profile_switch
   *   Profile settings for the form's jurisdiction.
   * @param string $locale
   *   Locale of the text to display.
   *
   * @return array{contact: ?array, bank: ?array}
   *   Snapshots keyed by consent kind.
   */
  public static function build(FormSchemaInterface $form, array $schema, array $groups, ApplicantProfileSwitch $profile_switch, string $locale): array {
    $offers = ['contact' => NULL, 'bank' => NULL];
    $scope = ProfileConsentScope::scope($groups['routeOrganisation'], $groups['jurisdiction']);
    if ($scope === NULL || !$profile_switch->isEnabled($groups['jurisdiction'])) {
      return $offers;
    }

    $fields = ProfileConsentFields::byKind($schema);
    $locale = ProfileConsentText::normalizeLocale($locale);
    if ($fields['contact'] === []) {
      return $offers;
    }
    $jurisdiction = $scope['type'] === 'jur';
    foreach ($fields as $kind => $keys) {
      if ($keys === [] || ($kind === 'bank' && !$profile_switch->isBankEnabled($groups['jurisdiction']))) {
        continue;
      }
      $version = ProfileConsentText::version($kind);
      $offer = [
        'locale' => $locale,
        'textVersion' => $version,
        'text' => ProfileConsentText::render($kind, $locale, $scope['label'], $version, $keys, $jurisdiction),
        'textHash' => ProfileConsentText::hash($kind, $locale, $scope['label'], $version, $keys, $jurisdiction),
        'scope' => $scope,
        'fields' => $keys,
        'formUuid' => $form->uuid(),
        'formRevision' => (int) $form->getRevisionId(),
      ];
      $offer['id'] = hash('sha256', json_encode([$kind, $offer], JSON_THROW_ON_ERROR));
      $offers[$kind] = $offer;
    }
    return $offers;
  }

  /**
   * Returns the JSON exposed to the citizen schema proxy.
   */
  public static function projection(FormSchemaInterface $form, ApplicantProfileSwitch $profile_switch): ?string {
    if (!$form->isPublished() || $form->getSchemaStatus() !== 'published' || $form->getPublicationScope() !== 'internet') {
      return NULL;
    }
    $schema = json_decode((string) $form->getSchema(), TRUE);
    if (!is_array($schema)) {
      return NULL;
    }
    $groups = ProfileConsentScope::formGroups($form);
    $result = [];
    foreach (ProfileConsentText::locales() as $locale) {
      $offers = self::build($form, $schema, $groups, $profile_switch, $locale);
      $result[$locale] = array_map(static function (?array $offer): ?array {
        return $offer === NULL ? NULL : array_intersect_key($offer, array_flip(['id', 'textVersion', 'text', 'fields']));
      }, $offers);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

}
