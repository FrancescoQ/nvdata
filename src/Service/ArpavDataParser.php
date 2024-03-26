<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ArpavDataParser {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * @var \Symfony\Contracts\HttpClient\HttpClientInterface
   */
  private $client;

  /**
   * @var \App\Service\nvDataNormalizer
   */
  private $normalizer;

  public function __construct(LoggerInterface $logger, HttpClientInterface $httpClient, nvDataNormalizer $normalizer) {
    $this->logger = $logger;
    $this->client = $httpClient;
    $this->normalizer = $normalizer;
  }

  /**
   * @return array
   */
  public function getData(): array {
    $data = [];
    $response = $this->client->request('GET', $_ENV['ARPAV_URL']);
    $statusCode = $response->getStatusCode();
    if ($statusCode === 200) {
      $content = $response->getContent();
      $crawler = new Crawler($content);
      foreach ($crawler->children() as $domElement) {
        if ($domElement->nodeName === 'data_emissione') {
          $data['intro']['date'] = $domElement->getAttribute('date');
        }
        elseif ($domElement->nodeName === 'bollettino') {
          $children = [
            'situazione',
            'previsione',
            'indicazioni',
            'previsore'
          ];

          foreach ($children as $child) {
            $situation = $domElement->getElementsByTagName($child);
            $data['intro'][$child] = trim($situation->item(0)->nodeValue);
          }

          $days = $domElement->getElementsByTagName('scadenza');
          $data['days'] = [];
          foreach ($days as $day) {
            $data['days'][] = $this->getDayData($day);
          }
        }
      }
    }

    $data['credits'] = 'ARPAV - https://meteo.arpa.veneto.it';

    return $this->normalizer->normalizeArpav($data);
  }

  private function getDayData(\DOMElement $day) {
    $day_data = [];
    $day_data['date'] = $day->getAttribute('data');
    foreach ($day->getElementsByTagName('area') as $area) {
      $area_name = $area->getAttribute('nome');
      $day_data[$area_name]['area'] = $area_name;
      $children = [
        'pericolo',
        'neve_fresca',
        'luoghi_pericolosi',
        'quote',
        'tipodivalanga',
        'ambitidelpericolo'
      ];
      $day_data[$area_name] = array_merge($day_data[$area_name], $this->getChildrenValues($area, $children));
      $icons = $area->getElementsByTagName('icone')->item(0);
      foreach ($icons->childNodes as $child) {
        if ($child->nodeName === '#text') {
          continue;
        }

        $day_data[$area_name]['icons'][$child->nodeName] = [];

        $attributes = [
          'image',
          'description',
          'quota'
        ];

        foreach ($attributes as $attribute) {
          $day_data[$area_name]['icons'][$child->nodeName][$attribute] = $child->hasAttribute($attribute) ? $child->getAttribute($attribute) : '';
        }
      }
    }
    return $day_data;
  }

  private function getChildrenValues(\DOMElement $domElement, $children) {
    $data = [];

    foreach ($children as $child) {
      $childElement = $domElement->getElementsByTagName($child);
      if ($childElement->item(0)) {
        $data[$child] = trim($childElement->item(0)->nodeValue);
      }
    }

    return $data;
  }
}
