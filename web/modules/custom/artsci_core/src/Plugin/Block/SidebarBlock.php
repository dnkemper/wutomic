<?php

declare(strict_types=1);

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a basic search block.
 *
 * @Block(
 *   id = "artsci_core_sidebar_block",
 *   admin_label = @Translation("Contact Sidebar Block"),
 *   category = @Translation("Site custom")
 * )
 */
final class SidebarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->routeMatch->getParameter('node');

    if (!$node instanceof NodeInterface || $node->bundle() !== 'person') {
      return [];
    }

    $build = [
      '#theme' => 'artsci_people_sidebar',
      '#contact' => $this->buildContactSection($node),
      '#office' => $this->buildOfficeSection($node),
      '#mailing_address' => $this->buildMailingAddressSection($node),
      '#faculty_links' => $this->buildFacultyLinksSection($node),
      '#cache' => [
        'tags' => $node->getCacheTags(),
        'contexts' => ['route'],
      ],
    ];

    return $build;
  }

  /**
   * Builds the contact section.
   */
  protected function buildContactSection(NodeInterface $node): array {
    $items = [];

    // Email.
    if ($node->hasField('field_person_email') && !$node->get('field_person_email')->isEmpty()) {
      $email = $node->get('field_person_email')->value;
      $items['email'] = [
        'label' => $this->t('Email'),
        'value' => $email,
        'link' => 'mailto:' . $email,
        'type' => 'email',
      ];
    }

    // Phone.
    if ($node->hasField('field_person_phone') && !$node->get('field_person_phone')->isEmpty()) {
      $phone = $node->get('field_person_phone')->value;
      $items['phone'] = [
        'label' => $this->t('P:'),
        'value' => $phone,
        'link' => 'tel:' . preg_replace('/[^0-9+]/', '', $phone),
        'type' => 'phone',
      ];
    }

    // Fax.
    if ($node->hasField('field_person_fax') && !$node->get('field_person_fax')->isEmpty()) {
      $fax = $node->get('field_person_fax')->value;
      $items['fax'] = [
        'label' => $this->t('F:'),
        'value' => $fax,
        'type' => 'fax',
      ];
    }

    // Social media links (if you have them).
     if ($node->hasField('field_social_media_links_p') && !$node->get('field_social_media_links_p')->isEmpty()) {
      $items['social'] = $node->get('field_social_media_links_p')->view(['label' => 'hidden']);
     }

    return $items;
  }

  /**
   * Builds the office section.
   */
  protected function buildOfficeSection(NodeInterface $node): array {
    $items = [];

    // Building name and room number.
    if ($node->hasField('field_building_name_and_room_num') && !$node->get('field_building_name_and_room_num')->isEmpty()) {
      $items['location'] = [
        'value' => $node->get('field_building_name_and_room_num')->value,
        'type' => 'location',
      ];
    }

    // Office hours.
    if ($node->hasField('field_office_hours') && !$node->get('field_office_hours')->isEmpty()) {
      $items['hours'] = [
        'value' => $node->get('field_office_hours')->value,
        'type' => 'hours',
      ];
    }

    // Get Directions URL.
    if ($node->hasField('field_external_url') && !$node->get('field_external_url')->isEmpty()) {
      $items['directions'] = [
        'label' => $this->t('Get Directions'),
        'link' => $node->get('field_external_url')->value,
        'type' => 'directions',
        'external' => TRUE,
      ];
    }

    return $items;
  }

  /**
   * Builds the mailing address section.
   */
  protected function buildMailingAddressSection(NodeInterface $node): array {
    $items = [];

    if ($node->hasField('field_person_contact_information') && !$node->get('field_person_contact_information')->isEmpty()) {
      $paragraph = $node->get('field_person_contact_information')->entity;

      if ($paragraph && $paragraph->hasField('field_artsci_contact_address')) {
        $address = $paragraph->get('field_artsci_contact_address')->first();

        if ($address) {
          $address_values = $address->getValue();
          $items['address'] = [
            'organization' => $address_values['organization'] ?? '',
            'address_line1' => $address_values['address_line1'] ?? '',
            'address_line2' => $address_values['address_line2'] ?? '',
            'locality' => $address_values['locality'] ?? '',
            'administrative_area' => $address_values['administrative_area'] ?? '',
            'postal_code' => $address_values['postal_code'] ?? '',
          ];
        }
      }
    }

    return $items;
  }

  /**
   * Builds the faculty links section.
   */
  protected function buildFacultyLinksSection(NodeInterface $node): array {
    $items = [];

    if ($node->hasField('field_person_website') && !$node->get('field_person_website')->isEmpty()) {
      foreach ($node->get('field_person_website') as $delta => $link) {
        $uri = $link->uri;
        $title = $link->title ?: $uri;

        $items[] = [
          'title' => $title,
          'url' => $uri,
          'external' => str_starts_with($uri, 'http'),
        ];
      }
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      return Cache::mergeTags(parent::getCacheTags(), $node->getCacheTags());
    }
    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
