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
 *   id = "artsci_core_subscribe_block",
 *   admin_label = @Translation("Subscribe Block"),
 *   category = @Translation("Site custom")
 * )
 */
class SubscribeBlock extends BlockBase {

  /**
   * Emma signup configuration.
   */
  const EMMA_APP_URL = 'https://app.e2ma.net';
  const EMMA_SIGNUP_ID = '2097296';
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
        <div class="subscribe-block">
          <div class="subscribe-block__content">
            <div class="subscribe-block__text">
              <p class="subscribe-block__heading">Subscribe to The Ampersand Newsletter:</p>
              <p class="subscribe-block__subheading">News and Stories of Incredible People, Research and Learning in Arts &amp; Sciences</p>
            </div>
            <form class="subscribe-block__form" action="{{ action_url }}" method="POST" novalidate>
              {# Hidden fields required by Emma #}
              <input type="hidden" name="group_{{ group_id }}" value="{{ group_id }}">
              <input type="hidden" name="subscriber_consent_email" value="true">
              <input type="hidden" name="subscriber_consent_tracking" value="true">
              <input type="hidden" name="checked_subscriptions" value="">
              <input type="hidden" name="e2ma_field_enable_recaptcha" value="false">
              <input type="hidden" name="plaintext_preferred" value="False">
              <input type="hidden" name="sms_phone_number" value="None">

              <div class="subscribe-block__input-wrapper">
                <label for="subscribe-email" class="visually-hidden">Email address</label>
                <input
                  type="email"
                  id="subscribe-email"
                  name="email"
                  placeholder="Email"
                  required
                  class="subscribe-block__input"
                  autocomplete="email"
                >
              <button type="submit" class="subscribe-block__submit" aria-label="Subscribe">
                <svg role="presentation" class="svg-inline--fa fa-arrow-right" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg>
              </button>
              </div>
              <div class="subscribe-block__messages" aria-live="polite"></div>
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
