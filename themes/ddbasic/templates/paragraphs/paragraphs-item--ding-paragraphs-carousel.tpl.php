<?php

/**
 * @file
 * Theme implementation for a paragraph item displayed as carousel.
 *
 * Available variables:
 * - $content: An array of content items. Use render($content) to print them
 *   all, or print a subset such as render($content['field_example']). Use
 *   hide($content['field_example']) to temporarily suppress the printing of a
 *   given element.
 * - $classes: String of classes that can be used to style contextually through
 *   CSS. It can be manipulated through the variable $classes_array from
 *   preprocess functions. By default the following classes are available, where
 *   the parts enclosed by {} are replaced by the appropriate values:
 *   - entity
 *   - entity-paragraphs-item
 *   - paragraphs-item-{bundle}
 *
 * Other variables:
 * - $classes_array: Array of html class attribute values. It is flattened into
 *   a string within the variable $classes.
 * - $paragraph_styles: Specific styles depending on paragraph type field.
 *
 * @see template_preprocess()
 * @see template_preprocess_entity()
 * @see template_process()
 * @see ddbasic_preprocess_entity_paragraphs_item()
 */

?>
<div class="<?php print $paragraph_styles; ?> paragraphs-block--carousel">
  <?php print render($content); ?>
</div>