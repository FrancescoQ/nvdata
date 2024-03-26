<?php

namespace App\Service;



use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AinevaDataParser {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * @var \Symfony\Contracts\HttpClient\HttpClientInterface
   */
  private $client;

  /**
   * @var string|null
   */
  private $content;

  /**
   * @var \App\Service\AinevaRegions
   */
  private $ainevaRegions;

  /**
   * @var \App\Service\nvDataNormalizer
   */
  private $normalizer;

  public function __construct(LoggerInterface $logger, HttpClientInterface $httpClient, AinevaRegions $ainevaRegions, nvDataNormalizer $normalizer) {
    $this->logger = $logger;
    $this->client = $httpClient;
    $this->ainevaRegions = $ainevaRegions;
    $this->normalizer = $normalizer;
    $this->content = NULL;
    $url = 'https://bollettini.aineva.it/albina_files/latest/it.xml';
    $response = $this->client->request('GET', $url);
    $statusCode = $response->getStatusCode();
    if ($statusCode === 200) {
      $this->content = $response->getContent();
    }
  }

  public function getBulletins($load = FALSE) {
    $bulletins = [];
    if (!$this->content) {
      return $bulletins;
    }

    $crawler = new Crawler($this->content);
    $locRef = $crawler->filter("default|locRef");
    if ($locRef->count()) {
      foreach ($locRef as $loc) {
        $loc_id = $loc->getAttribute('xlink:href');
        $bulletin = $locRef->closest('default|Bulletin');
        $bulletins[$loc_id] = $load ? $bulletin : $bulletin->getNode(0)->getAttribute('gml:id');
      }
    }

    return $bulletins;
  }

  /**
   * Get the region data.
   *
   * @param string $region
   *
   * @return array
   */
  public function getRegionData(string $region): array {
    $data = [];
    $bulletin = $this->getRegionBulletin($region);
    if (!$bulletin) {
      return $data;
    }

    $data['region_id'] = $region;
    $data['region_name'] = $this->ainevaRegions->getRegionName($region);

    $start_time = $bulletin->filter('default|TimePeriod default|beginPosition')->innerText();
    $end_time = $bulletin->filter('default|TimePeriod default|endPosition')->innerText();
    $data['start_time'] = $start_time;
    $data['end_time'] = $end_time;

    $data['credits'] = 'AINEVA - https://bollettini.aineva.it/more/open-data';

    return $this->normalizer->normalizeAineva($data);
  }

  /**
   * Get the bulletin for the given region.
   *
   * @param string $region
   *
   * @return \Symfony\Component\DomCrawler\Crawler|null
   */
  private function getRegionBulletin(string $region): ?Crawler {
    if (!$this->content) {
      return NULL;
    }

    $bulletins = $this->getBulletins(TRUE);
    return $bulletins[$region] ?? NULL;
  }
}
