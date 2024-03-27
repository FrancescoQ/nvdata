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
    $response = $this->client->request('GET', $_ENV['AINEVA_URL']);
    $statusCode = $response->getStatusCode();
    if ($statusCode === 200) {
      $this->content = $response->getContent();
    }
  }

  /**
   * Retrieve the Aineva Bulletins for all the regions.
   *
   * @param bool $load
   *
   * @return array
   */
  public function getBulletins(bool $load = FALSE): array {
    $bulletins = [];
    if (!$this->content) {
      return $bulletins;
    }

    $crawler = new Crawler($this->content);
    $locRef = $crawler->filter("default|locRef");
    if ($locRef->count()) {
      $bulletins_data = $locRef->each(function($loc, $i) use ($load) {
        $loc_id = $loc->getNode(0)->getAttribute('xlink:href');
        $bulletin = $loc->closest('default|Bulletin');
        return [
          $loc_id,
          $load ? $bulletin : $bulletin->getNode(0)->getAttribute('gml:id')
        ];
      });

      foreach ($bulletins_data as $bulletin_data) {
        $bulletins[$bulletin_data[0]] = $bulletin_data[1];
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
    $data['report_time'] = $bulletin->filter('default|dateTimeReport')->innerText();;
    $data['start_time'] = $bulletin->filter('default|TimePeriod default|beginPosition')->innerText();;
    $data['end_time'] = $bulletin->filter('default|TimePeriod default|endPosition')->innerText();;

    // Locations of the bulletin.
    $data['locations'] = $bulletin->filter('default|locRef')->each(function ($locRef, $i) {
      $loc_id = $locRef->getNode(0)->getAttribute('xlink:href');
      $loc_name = $this->ainevaRegions->getRegionName($loc_id);
      return ['id' => $loc_id, 'name' => $loc_name];
    });

    // Generic danger ratings.
    $data['ratings'] = $bulletin->filter('default|DangerRating')->each(function (Crawler $dangerRating) {
      $elevation_attribute = $dangerRating->filter('default|validElevation')->getNode(0)->getAttribute('xlink:href');
      // Remove the prefix.
      $elevation_attribute = str_replace('ElevationRange_', '', $elevation_attribute);
      // Get the direction.
      $direction_suffix = substr($elevation_attribute, -2);
      $direction = $direction_suffix === 'Hi' ? 'up' : 'down';

      // The remaining part is the elevation.
      $elevation = substr($elevation_attribute, 0, -2);

      // The only visible text is the danger rating, so we can use the entire
      // node text as the value.
      $danger_value = $dangerRating->text();
      return ['elevation' => $elevation, 'direction' => $direction, 'value' => $danger_value];
    });

    // Danger patterns.
    // @see https://bollettini.aineva.it/education/danger-patterns
    $data['danger_patterns'] = $bulletin->filter('default|DangerPattern')->each(function (Crawler $dangerPattern) {
      $danger_pattern_code = $dangerPattern->text();
      $danger_pattern = $this->dangerPatterns($danger_pattern_code);
      return ['code' => $danger_pattern_code, 'description' => $danger_pattern];
    });

    // Avalanche problems.
    $data['problems'] = $bulletin->filter('default|AvProblem')->each(function (Crawler $avProblem) {
      $problem_type = $avProblem->text();
      $elevation_attribute = $avProblem->filter('default|validElevation')->getNode(0)->getAttribute('xlink:href');
      // Remove the prefix.
      $elevation_attribute = str_replace('ElevationRange_', '', $elevation_attribute);
      // Get the direction.
      $direction_suffix = substr($elevation_attribute, -2);
      $direction = $direction_suffix === 'Hi' ? 'up' : 'down';

      // The remaining part is the elevation.
      $elevation = substr($elevation_attribute, 0, -2);

      $orientations = $avProblem->filter('default|validAspect')->each(function(Crawler $validAspect) {
        $aspect_attribute = $validAspect->getNode(0)->getAttribute('xlink:href');
        return str_replace('AspectRange_', '', $aspect_attribute);
      });
      return ['type' => $problem_type, 'orientations' => $orientations, 'elevation' => $elevation, 'direction' => $direction];
    });

    // Textual descriptions.
    $data['highlight'] = $bulletin->filter('default|avActivityHighlights')->text();
    $data['description'] = $bulletin->filter('default|avActivityComment')->text();
    $data['snow_description'] = $bulletin->filter('default|snowpackStructureComment')->text();

    // Forecast / tendency
    $data['tendency']['type'] = $bulletin->filter('default|tendency default|type')->text();
    $data['tendency']['from'] = $bulletin->filter('default|tendency default|beginPosition')->text();
    $data['tendency']['to'] = $bulletin->filter('default|tendency default|endPosition')->text();
    $data['tendency']['tendencyComment'] = $bulletin->filter('default|snowpackStructureComment')->text();

    $data['credits'] = 'AINEVA - https://bollettini.aineva.it/more/open-data';

    return $this->normalizer->normalizeAineva($data);
  }

  private function dangerPatterns($code = '') {
    $map = [
      'DP1' => 'Strato debole persistente basale',
      'DP2' => 'Valanga per scivolamento di neve',
      'DP3' => 'Pioggia',
      'DP4' => 'Freddo su caldo / caldo su freddo',
      'DP5' => 'Neve dopo un lungo periodo di freddo',
      'DP6' => 'Neve fresca fredda a debole coesione e vento',
      'DP7' => 'Passaggio da poca a molta neve',
      'DP8' => 'Brina di superficie sepolta',
      'DP9' => 'Neve pallottolare coperta da neve fresca',
      'DP10' => 'Situazione primaverile',
    ];

    return $map[$code] ?: $map;
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

  /**
   * Returns an array with the tree structure of all the regions with bulletins
   * available.
   *
   * @return array
   */
  public function getRegionsTree(): array {
    $navigation = [];

    $regions = $this->ainevaRegions->getRegions();
    $bulletins = $this->getBulletins();
    $regions_with_data = [];
    foreach ($regions as $region_id => $region_name) {
      if (empty($bulletins[$region_id])) {
        continue;
      }

      $regions_with_data[$region_id] = $region_name;
    }

    foreach ($regions_with_data as $region_id => $region_name) {
      $id_parts = explode('-', $region_id);
      $region_parents = [];
      for ($i = 0; $i < count($id_parts); $i++) {
        $region_parent = [];
        for ($j = 0; $j <= $i; $j++) {
          $region_parent[] = $id_parts[$j];
        }

        $region_parent_string = implode('-', $region_parent);
        if (!empty($regions[$region_parent_string])) {
          $region_parents[] = $region_parent_string;
          if ($i < count($id_parts) -1) {
            $region_parents[] = 'regions';
          }
        }
      }

      $this->setNestedValue($navigation, $region_parents, $region_name);
    }

    $navigation = $this->setNavigationRegionsNames($navigation, $regions);
    return $navigation;
  }

  /**
   * Set the value in the array structure based on the given parents.
   */
  private function setNestedValue(array &$array, array $parents, $value): void {
    $current = &$array;
    foreach ($parents as $parent) {

      // To handle the original input, if an item is not an array,
      // replace it with an array with the value as the first item.
      if (!is_array($current)) {
        $current = [$current];
      }

      if (!array_key_exists($parent, $current)) {
        $current[$parent] = [];
      }
      $current = &$current[$parent];
    }

    $current = $value;
  }

  /**
   * Set the name of the regions in the navigation tree array.
   *
   * @param array $navigation
   * @param array $regions
   *
   * @return array
   */
  private function setNavigationRegionsNames(array $navigation, array $regions): array {
    foreach ($navigation as $key => $value) {
      if (!empty($regions[$key])) {
        if (is_array($navigation[$key])) {
          $navigation[$key]['name'] = $regions[$key];
        }
      }

      if (!empty($value['regions'])) {
        $navigation[$key]['regions'] = $this->setNavigationRegionsNames($value['regions'], $regions);
      }
    }

    return $navigation;
  }
}
