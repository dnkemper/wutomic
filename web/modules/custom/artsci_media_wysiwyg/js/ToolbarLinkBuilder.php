<?php

namespace Drupal\olympian_media_wysiwyg;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * ToolbarLinkBuilder fills out the placeholders generated in user_toolbar().
 */
class ToolbarLinkBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * ToolbarHandler constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   */
  public function __construct(AccountProxyInterface $account) {
    $this->account = $account;
  }

  /**
   * Lazy builder callback for rendering toolbar links.
   *
   * @return array
   *   A renderable array as expected by the renderer service.
   */
  public function renderToolbarLinks() {
    $links = [
      'account' => [
        'title' => $this->t('View profile'),
        'url' => Url::fromRoute('user.page'),
        'attributes' => [
          'title' => $this->t('User account'),
          'class' => [
            'toolbar-item',
            'toolbar-icon',
            'toolbar-icon-olympian-media-wysiwyg',
          ],
        ],
      ],
      'account_edit' => [
        'title' => $this->t('Edit profile'),
        'url' => Url::fromRoute('entity.user.edit_form', ['user' => $this->account->id()]),
        'attributes' => [
          'title' => $this->t('Edit user account'),
          'class' => [
            'toolbar-item',
            'toolbar-icon',
            'toolbar-icon-olympian-media-wysiwyg',
          ],
        ],
      ],
      'logout' => [
        'title' => $this->t('Log out'),
        'url' => Url::fromRoute('user.logout'),
        'class' => [
          'toolbar-icon',
        ],
      ],
    ];

    // Get the current user as we'll add cache tags for them to fix the wrong username displayed issue.
    $current_user = \Drupal::currentUser();

    $build = [
      '#theme' => 'links__toolbar_user',
      '#links' => $links,
      '#attributes' => [
        'class' => ['toolbar-menu', 'toolbar-test'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['user:' . $current_user->id()],
      ],
    ];

    return $build;
  }

  /**
   * Lazy builder callback for rendering the username.
   *
   * @return array
   *   A renderable array as expected by the renderer service.
   */
  public function renderDisplayName() {
    return [
      '#markup' => $this->account->getDisplayName(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['renderToolbarLinks', 'renderDisplayName'];
  }

}
