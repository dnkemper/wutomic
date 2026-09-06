<?php

namespace Drupal\artsci_core\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\system\Form\SiteInformationForm;

/**
 * Configure site information settings for this site.
 *
 * @phpstan-ignore-next-line
 */
class ArtsciCoreSiteInformationForm extends SiteInformationForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Retrieve the system.site configuration.
    $site_config = $this->config('system.site');

    // Get the original form from the class we are extending.
    $form = parent::buildForm($form, $form_state);
    $form['site_information']['production_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Production URL'),
      '#default_value' => $site_config->get('production_url') ?? 'https://artsci.washu.edu',
      '#description' => $this->t('URL of production site. Used for absolute links that must always point to production, such as the academic calendar subscription feed.'),
    ];
    $form['site_information']['has_parent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('This site is part of a parent organization.'),
      '#default_value' => $site_config->get('has_parent'),
      '#description' => $this->t('Show additional options for setting the parent organization website. Note: this setting is not necessary to show that a site is part of Washu.'),
    ];

    $form['site_information']['parent'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="has_parent"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['site_information']['parent']['site_parent_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $site_config->get('parent.name'),
      '#description' => $this->t('The official name of the parent organization.'),
      '#states' => [
        'required' => [
          ':input[name="has_parent"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['site_information']['parent']['site_parent_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL'),
      '#default_value' => $site_config->get('parent.url'),
      '#description' => $this->t('URL of parent site.'),
      '#states' => [
        'required' => [
          ':input[name="has_parent"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];
    $form['social_media'] = [
      '#type' => 'details',
      '#title' => $this->t('Social Media'),
      '#open' => TRUE,
    ];

    $form['social_media']['twitter_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('X (Twitter) Username'),
      '#default_value' => $site_config->get('social_media.twitter_username') ?? 'washuartsci',
      '#description' => $this->t('Username without the @ symbol.'),
    ];

    $form['social_media']['facebook_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook Username'),
      '#default_value' => $site_config->get('social_media.facebook_username') ?? 'washuartsci',
      '#description' => $this->t('Facebook page username or ID.'),
    ];

    $form['social_media']['instagram_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instagram Username'),
      '#default_value' => $site_config->get('social_media.instagram_username') ?? 'washuartsci',
      '#description' => $this->t('Username without the @ symbol.'),
    ];

    $form['social_media']['youtube_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('YouTube Channel'),
      '#default_value' => $site_config->get('social_media.youtube_username') ?? 'washuartsci',
      '#description' => $this->t('The part after https://www.youtube.com/@'),
    ];

    $form['social_media']['linkedin_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LinkedIn'),
      '#default_value' => $site_config->get('social_media.linkedin_username') ?? 'showcase/washu-arts-sciences',
      '#description' => $this->t('The part after https://www.linkedin.com/'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $has_parent = $form_state->getValue('has_parent');
    if (!$has_parent) {
      $form_state
        ->setValueForElement($form['site_information']['parent']['site_parent_name'], NULL)
        ->setValueForElement($form['site_information']['parent']['site_parent_url'], NULL);
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save additional site information config.
    $this->config('system.site')
      ->set('production_url', $form_state->getValue('production_url'))
      ->set('has_parent', $form_state->getValue('has_parent'))
      ->set('parent.name', $form_state->getValue('site_parent_name'))
      ->set('parent.url', $form_state->getValue('site_parent_url'))
      ->set('social_media.twitter_username', $form_state->getValue('twitter_username'))
      ->set('social_media.facebook_username', $form_state->getValue('facebook_username'))
      ->set('social_media.instagram_username', $form_state->getValue('instagram_username'))
      ->set('social_media.youtube_username', $form_state->getValue('youtube_username'))
      ->set('social_media.linkedin_username', $form_state->getValue('linkedin_username'))
      // Make sure to save the configuration.
      ->save();

    // Pass the remaining values off to the original form that we have extended,
    // so that they are also saved.
    parent::submitForm($form, $form_state);
  }

}
