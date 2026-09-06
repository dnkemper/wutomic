<?php

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides an Emma email subscribe block for the Ampersand newsletter.
 *
 * Submits directly to Emma's signup endpoint via AJAX, falling back to a
 * standard form POST if JavaScript is unavailable.
 *
 * @Block(
 *   id = "artsci_core_ampersand_subscribe_block",
 *   admin_label = @Translation("Ampersand Subscribe Block"),
 *   category = @Translation("Site custom")
 * )
 */
class AmpersandSubscribeBlock extends BlockBase {

  /**
   * Emma signup configuration.
   */
  const EMMA_APP_URL = 'https://app.e2ma.net';
  const EMMA_SIGNUP_ID = '2097293';
  const EMMA_ACCOUNT_ID = '1936834';
  const EMMA_GROUP_ID = '40062402';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['label_display' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $action_url = self::EMMA_APP_URL . '/app2/audience/signup/' . self::EMMA_SIGNUP_ID . '/' . self::EMMA_ACCOUNT_ID . '/?r=signup';

    return [
      '#type' => 'inline_template',
      '#template' => '
        <div class="subscribe-ampersand-block">
          <div class="subscribe-ampersand-block__content">
            <form class="subscribe-ampersand-block__form" action="{{ action_url }}" method="POST" novalidate>
              {# Hidden fields required by Emma #}
              <input type="hidden" name="group_{{ group_id }}" value="{{ group_id }}">
              <input type="hidden" name="subscriber_consent_email" value="true">
              <input type="hidden" name="subscriber_consent_tracking" value="true">
              <input type="hidden" name="checked_subscriptions" value="">
              <input type="hidden" name="e2ma_field_enable_recaptcha" value="false">
              <input type="hidden" name="plaintext_preferred" value="False">
              <input type="hidden" name="sms_phone_number" value="None">

              <div class="subscribe-ampersand-block__input-wrapper">
                <label for="subscribe-ampersand-email" class="visually-hidden">Email address</label>
                <input
                  type="email"
                  id="subscribe-ampersand-email"
                  name="email"
                  placeholder="Email"
                  required
                  class="subscribe-ampersand-block__input"
                  autocomplete="email"
                >
                <button type="submit" class="bttn bttn--secondary" aria-label="Subscribe">Subscribe</button>
              </div>
              <div class="subscribe-ampersand-block__messages" aria-live="polite"></div>
            </form>
          </div>
        </div>
      ',
      '#context' => [
        'action_url' => $action_url,
        'group_id' => self::EMMA_GROUP_ID,
      ],
      '#attached' => [
        'library' => [
          'artsci_core/subscribe-block',
        ],
      ],
    ];
  }

}
