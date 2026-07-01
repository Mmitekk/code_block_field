<?php

declare(strict_types=1);

namespace Drupal\code_block_field\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Provides the "code_block" field type.
 *
 * Stores three sub-fields — html, css, js — plus a serialised "assets" map
 * that holds references to managed files (uploaded images) used inside the
 * HTML markup.
 *
 * @FieldType(
 *   id = "code_block",
 *   label = @Translation("Code Block (HTML / CSS / JS)"),
 *   description = @Translation("Stores an HTML/CSS/JS bundle that is rendered inside an isolated Shadow DOM and can be edited inline (text, images, links) without opening the host entity."),
 *   category = @Translation("General"),
 *   default_widget = "code_block_widget",
 *   default_formatter = "code_block_formatter",
 *   serialized_property_names = {"assets"}
 * )
 */
class CodeBlockItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'html';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['html'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('HTML'))
      ->setDescription(new TranslatableMarkup('Raw HTML markup of the block. May reference managed assets via <code>data-cbf-asset="key"</code> on &lt;img&gt; tags.'))
      ->setSetting('case_sensitive', FALSE);

    $properties['css'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('CSS'))
      ->setDescription(new TranslatableMarkup('CSS that will be injected into the Shadow DOM root of the block.'));

    $properties['js'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('JavaScript'))
      ->setDescription(new TranslatableMarkup('JS that runs once the Shadow DOM is attached. <code>this</code> inside the script is the shadow host element.'));

    $properties['assets'] = MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Managed assets (images)'))
      ->setDescription(new TranslatableMarkup('Serialised map of asset_key =&gt; { fid, src, alt }.'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'html' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        'css' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        'js' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        // `assets` is declared in serialized_property_names, so Drupal will
        // automatically (un)serialize the value. We still need a column.
        'assets' => [
          'type' => 'blob',
          'size' => 'big',
          'not null' => FALSE,
          'serialize' => TRUE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $html = $this->get('html')->getValue();
    $css = $this->get('css')->getValue();
    $js = $this->get('js')->getValue();
    return ($html === NULL || $html === '') &&
      ($css === NULL || $css === '') &&
      ($js === NULL || $js === '');
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'allowed_html_override' => '',
      'default_mode' => 'open',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::fieldSettingsForm($form, $form_state);

    $form['allowed_html_override'] = [
      '#type' => 'textarea',
      '#rows' => 3,
      '#title' => $this->t('Allowed HTML tags (override global setting)'),
      '#description' => $this->t('Leave empty to use the global setting from the module configuration. Use the same format as the Filter module HTML filter (e.g. <code>&lt;a href&gt; &lt;img src alt&gt;</code>).'),
      '#default_value' => $this->getSetting('allowed_html_override'),
    ];

    $form['default_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Shadow DOM mode'),
      '#options' => [
        'open' => $this->t('Open (developer-inspectable)'),
        'closed' => $this->t('Closed (hidden from JS outside the block)'),
      ],
      '#default_value' => $this->getSetting('default_mode'),
      '#description' => $this->t('"Closed" mode prevents external scripts from reaching into the block DOM, but inline editing still works because the editor is granted an internal reference.'),
    ];

    return $form;
  }

}
