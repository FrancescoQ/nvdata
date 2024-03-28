<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Misc utilities to be used with the DomCrawler.
 */
class CrawlerUtilities {

  /**
   * Retrieve the text for the given selector within the given crawler, or an
   * empty string.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $crawler
   * @param string $selector
   * @param bool $inner
   *
   * @return string
   */
  public function getText(Crawler $crawler, string $selector = '', bool $inner = FALSE): string {
    $text = '';
    $element = !empty($selector) ? $crawler->filter($selector) : $crawler;
    if ($element->count()) {
      $text = $inner ? $element->innerText() : $element->text();
    }
    return $text;
  }

  /**
   * @param \Symfony\Component\DomCrawler\Crawler $crawler
   * @param string $attribute_name
   * @param int $delta
   *
   * @return string
   */
  public function getAttribute(Crawler $crawler, string $attribute_name, int $delta = 0): string {
    $attribute_value = '';
    $node = $crawler->getNode($delta);
    if ($node && $node->hasAttribute($attribute_name)) {
      $attribute_value = $crawler->getNode($delta)->getAttribute($attribute_name);
    }
    return $attribute_value;
  }
}
