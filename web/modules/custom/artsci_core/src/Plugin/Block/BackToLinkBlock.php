<?php

declare(strict_types=1);

namespace Drupal\artsci_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Provides a configurable "Back to" navigation link block.
 */
#[Block(
  id: 'artsci_core_backtolink',
  admin_label: new TranslatableMarkup('Back to link'),
  category: new TranslatableMarkup('Arts & Sciences'),
)]
final class BackToLinkBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'link_text' => 'Back to Faculty',
      'link_path' => '/faculty',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#description' => $this->t('Visible label, e.g. "Back to Faculty".'),
      '#default_value' => $this->configuration['link_text'],
      '#required' => TRUE,
    ];

    $form['link_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link path'),
      '#description' => $this->t('Internal path or alias starting with a slash, e.g. <code>/faculty</code> or <code>/research/bookshelf</code>.'),
      '#default_value' => $this->configuration['link_path'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    $path = trim((string) $form_state->getValue('link_path'));
    if ($path !== '' && !str_starts_with($path, '/')) {
      $form_state->setErrorByName('link_path', $this->t('The path must begin with a slash, e.g. /faculty.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['link_text'] = $form_state->getValue('link_text');
    $this->configuration['link_path'] = trim((string) $form_state->getValue('link_path'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    try {
      // fromUserInput() resolves aliases at render time, so the link always
      // reflects the current alias even if it changes later.
      $url = Url::fromUserInput($this->configuration['link_path']);
    }
    catch (\InvalidArgumentException) {
      // Bad path: render nothing rather than fataling the page.
      return [];
    }

    return [
      '#theme' => 'artsci_core_backtolink',
      '#link_text' => $this->configuration['link_text'],
      '#url' => $url,
      '#attached' => [
        'library' => ['artsci_core/backtolink'],
      ],
    ];
  }

}
